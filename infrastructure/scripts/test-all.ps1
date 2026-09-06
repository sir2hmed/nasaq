param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$verifier = Join-Path $PSScriptRoot 'verify-phase15.ps1'

if ($SkipDocker) {
    & $verifier -SkipDocker
}
else {
    & $verifier
}
