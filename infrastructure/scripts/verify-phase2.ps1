param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$phase1Verifier = Join-Path $PSScriptRoot 'verify-phase1.ps1'

if ($SkipDocker) {
    & $phase1Verifier -SkipDocker
    Write-Host 'Phase 2 native verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase1Verifier

$frontendUrl = 'http://localhost:5173'
$laravelUrl = 'http://localhost:8000'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$browserHeaders = @{
    Accept = 'application/json'
    Origin = $frontendUrl
    Referer = "$frontendUrl/"
}

function Refresh-CsrfToken {
    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl/sanctum/csrf-cookie" `
        -WebSession $session `
        -Headers $browserHeaders `
        -TimeoutSec 10 | Out-Null

    $xsrfCookie = $session.Cookies.GetCookies([Uri]$laravelUrl)['XSRF-TOKEN']
    if (-not $xsrfCookie) {
        throw 'Sanctum did not issue an XSRF-TOKEN cookie.'
    }

}

function Invoke-JsonRequest {
    param(
        [Parameter(Mandatory)] [ValidateSet('Post', 'Patch')] [string]$Method,
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [hashtable]$Body
    )

    # Axios reads the latest XSRF cookie before every mutation. Laravel may
    # re-encrypt that cookie on any response, so the verifier must do the same.
    $xsrfCookie = $session.Cookies.GetCookies([Uri]$laravelUrl)['XSRF-TOKEN']
    if (-not $xsrfCookie) {
        throw 'The current browser session does not contain an XSRF-TOKEN cookie.'
    }
    $stateChangingHeaders = @{
        Accept = 'application/json'
        Origin = $frontendUrl
        Referer = "$frontendUrl/"
        'X-XSRF-TOKEN' = [Uri]::UnescapeDataString($xsrfCookie.Value)
    }

    return Invoke-RestMethod `
        -Method $Method `
        -Uri "$laravelUrl$Path" `
        -WebSession $session `
        -Headers $stateChangingHeaders `
        -ContentType 'application/json' `
        -Body ($Body | ConvertTo-Json) `
        -TimeoutSec 10
}

Refresh-CsrfToken

$email = "phase2-$([Guid]::NewGuid().ToString('N'))@example.test"
$password = 'PhaseTwoPass2026'
$registration = Invoke-JsonRequest -Method Post -Path '/api/auth/register' -Body @{
    name = 'Phase 2 Acceptance User'
    email = $email
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
}

if ($registration.data.user.email -ne $email) {
    throw 'Registration did not return the created authenticated user.'
}

$currentUser = Invoke-RestMethod `
    -Uri "$laravelUrl/api/auth/me" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ($currentUser.data.user.email -ne $email) {
    throw 'The authenticated session did not persist to /api/auth/me.'
}

$localeUpdate = Invoke-JsonRequest -Method Patch -Path '/api/auth/locale' -Body @{
    preferred_locale = 'ar'
}
if ($localeUpdate.data.user.preferred_locale -ne 'ar') {
    throw 'The authenticated locale preference was not persisted.'
}

Invoke-JsonRequest -Method Post -Path '/api/auth/logout' -Body @{} | Out-Null

try {
    Invoke-RestMethod `
        -Uri "$laravelUrl/api/auth/me" `
        -WebSession $session `
        -Headers $browserHeaders `
        -TimeoutSec 10 | Out-Null
    throw 'The protected current-user route remained accessible after logout.'
}
catch {
    $statusCode = [int]$_.Exception.Response.StatusCode
    if ($statusCode -ne 401) {
        throw
    }
}

Refresh-CsrfToken
$login = Invoke-JsonRequest -Method Post -Path '/api/auth/login' -Body @{
    email = $email
    password = $password
    remember = $false
}
if ($login.data.user.email -ne $email -or $login.data.user.preferred_locale -ne 'ar') {
    throw 'Login did not restore the user and persisted Arabic locale.'
}

$frontend = Invoke-WebRequest -UseBasicParsing -Uri "$frontendUrl/dashboard" -TimeoutSec 10
if ($frontend.StatusCode -ne 200 -or $frontend.Content -notmatch '<div id="root"></div>') {
    throw 'The frontend SPA fallback did not serve the protected-route entry point.'
}

Write-Host 'Real registration, session persistence, locale update, logout, login, and SPA fallback passed.'
Write-Host 'Phase 2 verification passed.'
