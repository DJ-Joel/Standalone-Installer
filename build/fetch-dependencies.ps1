<#
.SYNOPSIS
    Downloads and verifies the pinned third-party binaries this installer
    bundles, before compiling. None of these are committed to git - large
    binaries bloat a repo permanently, even after later removal.

.DESCRIPTION
    Three dependencies, each with a genuine reason for its exact form here:

      PHP (NTS, x64)  - windows.php.net's own docs: "NTS builds are for
                         single-threaded use cases, typically PHP running via
                         FastCGI or on the CLI" - exactly our case, since we
                         run PHP via its own built-in server (php -S), not
                         through a threaded web server module.

      WinSW 2.x        - wraps the PHP process as a real Windows service, so
                         it survives reboots without anyone staying logged
                         in. MIT-licensed, actively maintained. (NSSM was the
                         original candidate, but its last stable release is
                         from 2014, with a known startup bug on modern
                         Windows that isn't fixed until a 2017 pre-release
                         build - not what a 2026 commercial product should
                         depend on.)

      qrencode.exe     - LGPL 2.1. Generates the venue's QR code fully
                         offline. Invoked as a separate process, not linked
                         into anything we compile, so LGPL's obligations
                         don't reach our own code - only its own license text
                         needs to travel with the binary (handled below).

    Every download is verified against a pinned SHA256 hash before use. If a
    hash doesn't match, the script stops rather than proceed with a file that
    might be corrupted, tampered with, or simply not the build that was
    tested against.

.NOTES
    THE PINNED HASHES BELOW ARE PLACEHOLDERS. This script's verification
    logic is real and complete, but the actual SHA256 values need to be
    filled in after a real, manually-verified first download of each file -
    they were never computed by an automated process with the ability to
    reach these download hosts, and shipping a fabricated hash would be
    worse than no hash at all. See the "REQUIRES SETUP" markers below.
#>

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$depsDir = Join-Path $repoRoot 'deps'
$phpDir = Join-Path $depsDir 'php'
$toolsDir = Join-Path $depsDir 'tools'

foreach ($dir in @($depsDir, $phpDir, $toolsDir)) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}

# ---------------------------------------------------------------------------
# Each dependency: where to get it, where it lands, and the pinned hash to
# verify it against. Bumping a version is a deliberate edit here, not
# something that happens silently on a routine rebuild - PHP in particular
# ships security patches often enough that "always grab latest" would mean
# shipping an untested build to customers on every single compile.
# ---------------------------------------------------------------------------
$dependencies = @(
    @{
        Name = 'PHP 8.5.8 NTS x64'
        Url = 'https://windows.php.net/downloads/releases/php-8.5.8-nts-Win32-vs17-x64.zip'
        Sha256 = 'REQUIRES SETUP - fill in after one manually verified download'
        Destination = Join-Path $depsDir 'php-8.5.8-nts-Win32-vs17-x64.zip'
        ExtractTo = $phpDir
        PostExtractNote = 'Confirm php.ini has extension=pdo_sqlite enabled - the NTS zip ships php.ini-production/php.ini-development, neither active by default. This installer needs one renamed to php.ini with that extension uncommented.'
    },
    @{
        Name = 'WinSW 2.x (stable, .NET Framework build)'
        Url = 'https://github.com/winsw/winsw/releases/latest/download/WinSW.NET4.exe'
        Sha256 = 'REQUIRES SETUP - fill in after one manually verified download'
        Destination = Join-Path $toolsDir 'WinSW.exe'
        ExtractTo = $null
        PostExtractNote = $null
    },
    @{
        Name = 'qrencode.exe (LGPL 2.1)'
        Url = 'REQUIRES SETUP - pin a specific trustworthy build. Building from a pinned upstream source commit ourselves (https://github.com/fukuchi/libqrencode) or sourcing via vcpkg''s own build pipeline are both safer than trusting an arbitrary prebuilt binary found online.'
        Sha256 = 'REQUIRES SETUP'
        Destination = Join-Path $toolsDir 'qrencode.exe'
        ExtractTo = $null
        PostExtractNote = 'Also copy qrencode''s own LICENSE/COPYING file into deps/tools/ - it must travel with the binary in the final install.'
    }
)

function Get-FileSha256 {
    param([string]$Path)
    return (Get-FileHash -Path $Path -Algorithm SHA256).Hash
}

$anyPlaceholders = $false

foreach ($dep in $dependencies) {
    Write-Host ""
    Write-Host "=== $($dep.Name) ===" -ForegroundColor Cyan

    if ($dep.Url -like 'REQUIRES SETUP*' -or $dep.Sha256 -like 'REQUIRES SETUP*') {
        Write-Warning "$($dep.Name) is not fully configured yet - see the comment above this entry in the script. Skipping."
        $anyPlaceholders = $true
        continue
    }

    if (Test-Path $dep.Destination) {
        $existingHash = Get-FileSha256 -Path $dep.Destination
        if ($existingHash -eq $dep.Sha256) {
            Write-Host "Already downloaded and verified: $($dep.Destination)"
            continue
        }
        Write-Warning "Existing file at $($dep.Destination) doesn't match the pinned hash - re-downloading."
        Remove-Item $dep.Destination -Force
    }

    Write-Host "Downloading from $($dep.Url) ..."
    Invoke-WebRequest -Uri $dep.Url -OutFile $dep.Destination -UseBasicParsing

    $actualHash = Get-FileSha256 -Path $dep.Destination
    if ($actualHash -ne $dep.Sha256) {
        Remove-Item $dep.Destination -Force
        Write-Error "SHA256 mismatch for $($dep.Name). Expected $($dep.Sha256), got $actualHash. The downloaded file was deleted rather than used - do not proceed until this is understood."
        exit 1
    }
    Write-Host "Verified OK (SHA256 $actualHash)"

    if ($dep.ExtractTo) {
        Write-Host "Extracting to $($dep.ExtractTo) ..."
        Expand-Archive -Path $dep.Destination -DestinationPath $dep.ExtractTo -Force
    }

    if ($dep.PostExtractNote) {
        Write-Host $dep.PostExtractNote -ForegroundColor Yellow
    }
}

if ($anyPlaceholders) {
    Write-Host ""
    Write-Warning "One or more dependencies above are still placeholders. The Inno Setup build will not have everything it needs until these are filled in with real, verified sources."
}
