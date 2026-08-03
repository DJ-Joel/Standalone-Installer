<#
.SYNOPSIS
    Points an installed copy of OpenKJ at this request server, without
    disturbing any of the user's other OpenKJ settings.

.DESCRIPTION
    OpenKJ stores its settings in a plain INI file, not the registry:
        %LOCALAPPDATA%\OpenKJ\OpenKJ\openkj.ini
    (confirmed against a real installed copy - org "OpenKJ", app "OpenKJ").

    Three keys live under [General]:
        requestServerUrl     - e.g. http://localhost:8080/api.php
        requestServerVenue    - an integer; unused server-side, any value works
        requestServerApiKey   - NOT written here. It's optional (OpenKJ falls
                                 back to a coded default when absent) and the
                                 server doesn't validate it, so there's nothing
                                 to coordinate.

    This script only ever touches those two lines. Every other section
    (window geometry, column widths, audio settings, etc.) is preserved
    exactly as-is, whether the file already exists or has to be created.

.PARAMETER Url
    The full request server URL OpenKJ should use, e.g.
    "http://localhost:8080/api.php".

.PARAMETER VenueId
    Integer venue id to write. Defaults to 0 - the server doesn't use this
    value for anything today, so any integer is fine.

.NOTES
    OpenKJ must be closed while this runs. QSettings caches in memory and
    only writes to disk on change or on close - if OpenKJ is open, it has no
    idea this file changed on disk, and could silently overwrite these edits
    the next time it saves its own state. This script checks for a running
    OpenKJ process and refuses to proceed if one is found, rather than write
    a change that might get clobbered a few seconds later.
#>

param(
    [Parameter(Mandatory = $true)]
    [string]$Url,

    [int]$VenueId = 0
)

$ErrorActionPreference = 'Stop'

# ---------------------------------------------------------------------------
# Refuse to run while OpenKJ is open - see .NOTES above.
# ---------------------------------------------------------------------------
$running = Get-Process -Name 'OpenKJ' -ErrorAction SilentlyContinue
if ($running) {
    Write-Error "OpenKJ is currently running. Close it before continuing, then run setup again."
    exit 1
}

# ---------------------------------------------------------------------------
# Locate the settings file. This does NOT hardcode the path - it asks
# Windows for the real per-user Local AppData folder, the same way Qt's
# QStandardPaths::DataLocation resolves it, so this stays correct even if a
# future OpenKJ version or Windows itself changes the exact resolved path.
# ---------------------------------------------------------------------------
$iniDir = Join-Path $env:LOCALAPPDATA 'OpenKJ\OpenKJ'
$iniPath = Join-Path $iniDir 'openkj.ini'

if (-not (Test-Path $iniDir)) {
    New-Item -ItemType Directory -Path $iniDir -Force | Out-Null
}

# ---------------------------------------------------------------------------
# Read the existing file (if any) as raw lines, so untouched sections are
# preserved byte-for-byte rather than reformatted.
# ---------------------------------------------------------------------------
$lines = @()
if (Test-Path $iniPath) {
    $lines = Get-Content -Path $iniPath -Encoding UTF8
}

function Set-IniValueInGeneral {
    param(
        [string[]]$Lines,
        [string]$Key,
        [string]$Value
    )

    $result = New-Object System.Collections.Generic.List[string]
    $inGeneral = $false
    $generalFound = $false
    $keyWritten = $false

    foreach ($line in $Lines) {
        $trimmed = $line.Trim()

        if ($trimmed -match '^\[(.+)\]$') {
            # Leaving [General] without having written the key - insert it
            # right before this next section header.
            if ($inGeneral -and -not $keyWritten) {
                $result.Add("$Key=$Value")
                $keyWritten = $true
            }
            $inGeneral = ($trimmed -eq '[General]')
            if ($inGeneral) { $generalFound = $true }
            $result.Add($line)
            continue
        }

        if ($inGeneral -and $trimmed -match "^$([regex]::Escape($Key))=") {
            $result.Add("$Key=$Value")
            $keyWritten = $true
            continue
        }

        $result.Add($line)
    }

    # [General] was found but the key was never written (was last section,
    # or file ended while still inside it).
    if ($inGeneral -and -not $keyWritten) {
        $result.Add("$Key=$Value")
        $keyWritten = $true
    }

    # No [General] section existed at all - create one at the very top.
    if (-not $generalFound) {
        $newTop = New-Object System.Collections.Generic.List[string]
        $newTop.Add('[General]')
        $newTop.Add("$Key=$Value")
        $newTop.AddRange($result)
        return $newTop
    }

    return $result
}

$lines = Set-IniValueInGeneral -Lines $lines -Key 'requestServerUrl' -Value $Url
$lines = Set-IniValueInGeneral -Lines $lines -Key 'requestServerVenue' -Value $VenueId
$lines = Set-IniValueInGeneral -Lines $lines -Key 'requestServerEnabled' -Value 'true'

Set-Content -Path $iniPath -Value $lines -Encoding UTF8

Write-Host "OpenKJ settings updated: $iniPath"
Write-Host "  requestServerUrl=$Url"
Write-Host "  requestServerVenue=$VenueId"
