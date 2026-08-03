<#
.SYNOPSIS
    Finds the machine's LAN-facing IPv4 address - the one singers' phones can
    actually reach.

.DESCRIPTION
    A typical venue laptop has several network adapters at once: real Wi-Fi,
    maybe Ethernet, plus virtual adapters from VPN clients, Hyper-V, Docker,
    etc. Picking the wrong one means the QR code points at an address nobody
    else on the network can reach.

    This selects the IPv4 address whose adapter:
      - Is actually Up
      - Has a default gateway configured (virtual/isolated adapters usually
        don't - this is the main signal that separates "a real network
        connection" from "a virtual adapter that happens to have an IP")
      - Isn't a loopback or link-local (169.254.x.x) address

    If more than one adapter still qualifies (e.g. Wi-Fi and Ethernet both
    connected), the first one found wins and a warning is written so the KJ
    can double check it's the right network - there's no reliable way to
    guess which one actually has singers' phones on it.

.OUTPUTS
    Prints the chosen IPv4 address to stdout on success, or writes an error
    and exits non-zero if nothing suitable was found.
#>

$ErrorActionPreference = 'Stop'

$candidates = Get-NetIPConfiguration | Where-Object {
    $_.NetAdapter.Status -eq 'Up' -and
    $_.IPv4DefaultGateway -and
    $_.IPv4Address
}

if (-not $candidates -or $candidates.Count -eq 0) {
    Write-Error "No active network adapter with internet/LAN connectivity was found. Connect to Wi-Fi or Ethernet and try again."
    exit 1
}

$chosen = $candidates | Select-Object -First 1
$ip = $chosen.IPv4Address.IPAddress

if ($candidates.Count -gt 1) {
    $others = ($candidates | Select-Object -Skip 1 | ForEach-Object { $_.InterfaceAlias }) -join ', '
    Write-Warning "More than one active network connection was found. Using '$($chosen.InterfaceAlias)' ($ip). Other candidates: $others. If singers can't connect, this may not be the right one."
}

Write-Output $ip
