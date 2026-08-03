<#
.SYNOPSIS
    Generates a QR code image encoding the venue's request-server URL, so
    singers can scan it instead of typing an address in by hand.

.DESCRIPTION
    Calls the bundled qrencode.exe (LGPL 2.1, https://fukuchi.org/works/qrencode/)
    as a separate process - no QR-generation code is linked into this
    installer, so it carries no obligations beyond including qrencode's own
    license text alongside the binary (already placed in tools\ - see
    build/fetch-dependencies.ps1).

    Runs entirely offline. No internet access is required or used - this
    matters because venue Wi-Fi during a live show is exactly the kind of
    thing that shouldn't be relied on for something this basic to work.

.PARAMETER Url
    The URL singers should be sent to, e.g. "http://192.168.1.5:8080/index.php".
    Typically the LAN IP from get-lan-ip.ps1 combined with the chosen port.

.PARAMETER OutputPath
    Where to write the generated PNG. Defaults to a QR code image inside the
    ProgramData folder, next to the database, so it survives app upgrades and
    is easy to find/reprint later without re-running setup.

.OUTPUTS
    Prints the path to the generated image to stdout on success.
#>

param(
    [Parameter(Mandatory = $true)]
    [string]$Url,

    [string]$OutputPath = (Join-Path $env:ProgramData 'Standalone Installer\venue-qr-code.png')
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$qrEncodeExe = Join-Path $scriptDir '..\tools\qrencode.exe'

if (-not (Test-Path $qrEncodeExe)) {
    Write-Error "qrencode.exe not found at $qrEncodeExe. It should have been installed alongside this app - see build/fetch-dependencies.ps1."
    exit 1
}

$outputDir = Split-Path -Parent $OutputPath
if (-not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir -Force | Out-Null
}

# -o output file, -s pixel size per module, -l error correction level (M is a
# reasonable middle ground - readable even if the printed/displayed copy gets
# a bit scuffed, without bloating the image for a plain URL).
& $qrEncodeExe -o $OutputPath -s 8 -l M $Url

if ($LASTEXITCODE -ne 0) {
    Write-Error "qrencode.exe failed (exit code $LASTEXITCODE) trying to encode: $Url"
    exit 1
}

Write-Host "QR code written: $OutputPath"
Write-Output $OutputPath
