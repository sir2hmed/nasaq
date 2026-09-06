param(
    [switch]$SkipDocker,
    [switch]$SkipPreviousDocker
)

$ErrorActionPreference = 'Stop'
$phase12Verifier = Join-Path $PSScriptRoot 'verify-phase12.ps1'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

if ($SkipDocker) {
    & $phase12Verifier -SkipDocker

    $requiredEvidence = @(
        @{ Path = 'backend-laravel\routes\api.php'; Pattern = "throttle:10,1" },
        @{ Path = 'backend-laravel\app\Http\Controllers\AgentOutputController.php'; Pattern = 'safeArtifactPath' },
        @{ Path = 'backend-laravel\app\Services\WorkflowGraphValidator.php'; Pattern = 'invalid_config' },
        @{ Path = 'frontend\src\components\AppErrorBoundary.jsx'; Pattern = 'unexpectedTitle' },
        @{ Path = 'frontend\src\styles.css'; Pattern = '@media (max-width: 480px)' },
        @{ Path = 'frontend\src\i18n\locales\ar.js'; Pattern = 'invalid_config' }
    )
    foreach ($evidence in $requiredEvidence) {
        $path = Join-Path $root $evidence.Path
        if (-not (Select-String -LiteralPath $path -SimpleMatch $evidence.Pattern -Quiet)) {
            throw "Missing Phase 13 evidence '$($evidence.Pattern)' in $($evidence.Path)."
        }
    }

    Write-Host 'Phase 13 native security, localization, accessibility, and mobile verification passed.'
    Write-Host 'Docker acceptance was skipped.'
    exit 0
}

if (-not $SkipPreviousDocker) {
    & $phase12Verifier
}

# Start the security phase with an isolated transient-cache budget. Earlier
# phases register disposable users from the same local client address; this
# reset keeps those setup requests from pre-consuming the authentication
# limiter that Phase 13 deliberately verifies below.
docker compose exec -T laravel php artisan cache:clear | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Could not reset transient Laravel cache before the security acceptance phase.'
}

$frontendUrl = 'http://localhost:5173'
$laravelUrl = 'http://localhost:8000'
$browserHeaders = @{
    Accept = 'application/json'
    Origin = $frontendUrl
    Referer = "$frontendUrl/"
}

function Initialize-BrowserSession {
    param([Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session)

    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl/sanctum/csrf-cookie" `
        -WebSession $Session `
        -Headers $browserHeaders `
        -TimeoutSec 10 | Out-Null
}

function Invoke-JsonMutation {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [hashtable]$Body,
        [string]$Method = 'Post'
    )

    $xsrfCookie = $Session.Cookies.GetCookies([Uri]$laravelUrl)['XSRF-TOKEN']
    if (-not $xsrfCookie) {
        throw 'The browser session does not contain an XSRF-TOKEN cookie.'
    }
    return Invoke-RestMethod `
        -Method $Method `
        -Uri "$laravelUrl$Path" `
        -WebSession $Session `
        -Headers @{
            Accept = 'application/json'
            Origin = $frontendUrl
            Referer = "$frontendUrl/"
            'X-XSRF-TOKEN' = [Uri]::UnescapeDataString($xsrfCookie.Value)
        } `
        -ContentType 'application/json' `
        -Body ($Body | ConvertTo-Json -Depth 24) `
        -TimeoutSec 30
}

function New-AuthenticatedSession {
    param([Parameter(Mandatory)] [string]$Label)

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    Initialize-BrowserSession -Session $session
    Invoke-JsonMutation -Session $session -Path '/api/auth/register' -Body @{
        name = "Phase 13 $Label"
        email = "phase13-$($Label.ToLower())-$([Guid]::NewGuid().ToString('N'))@example.test"
        password = 'PhaseThirteenPass2026'
        password_confirmation = 'PhaseThirteenPass2026'
        preferred_locale = 'en'
    } | Out-Null
    return $session
}

function Invoke-ExpectedFailure {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [int]$Status,
        [string]$Method = 'Get',
        [hashtable]$Body = @{}
    )

    try {
        if ($Method -eq 'Get') {
            Invoke-WebRequest `
                -UseBasicParsing `
                -Uri "$laravelUrl$Path" `
                -WebSession $Session `
                -Headers $browserHeaders `
                -TimeoutSec 10 | Out-Null
        }
        else {
            $xsrfCookie = $Session.Cookies.GetCookies([Uri]$laravelUrl)['XSRF-TOKEN']
            if (-not $xsrfCookie) {
                throw 'The browser session does not contain an XSRF-TOKEN cookie.'
            }
            Invoke-WebRequest `
                -UseBasicParsing `
                -Method $Method `
                -Uri "$laravelUrl$Path" `
                -WebSession $Session `
                -Headers @{
                    Accept = 'application/json'
                    Origin = $frontendUrl
                    Referer = "$frontendUrl/"
                    'X-XSRF-TOKEN' = [Uri]::UnescapeDataString($xsrfCookie.Value)
                } `
                -ContentType 'application/json' `
                -Body ($Body | ConvertTo-Json -Depth 24) `
                -TimeoutSec 30 | Out-Null
        }
        throw "Expected HTTP $Status for $Method $Path."
    }
    catch {
        $actual = [int]$_.Exception.Response.StatusCode
        if ($actual -ne $Status) {
            throw "Expected HTTP $Status but received HTTP $actual for $Method $Path."
        }
        $responseBody = $_.ErrorDetails.Message
        if (-not $responseBody -and $_.Exception.Response) {
            $responseStream = $_.Exception.Response.GetResponseStream()
            if ($responseStream) {
                $reader = New-Object System.IO.StreamReader($responseStream)
                try {
                    $responseBody = $reader.ReadToEnd()
                }
                finally {
                    $reader.Dispose()
                }
            }
        }
        return $responseBody
    }
}

$owner = New-AuthenticatedSession -Label 'Owner'
$intruder = New-AuthenticatedSession -Label 'Intruder'
$graph = @{
    version = 1
    name = 'Phase 13 owner isolation'
    description = 'Security acceptance graph'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 100; y = 100 }
            config = @{ topic = 'Security'; source_count = 2; language = 'en'; search_depth = 'basic' }
        },
        @{
            id = 'writer_01'
            type = 'writer'
            position = @{ x = 400; y = 100 }
            config = @{ style = 'professional'; length = 'short'; format = 'article'; language = 'same_as_input' }
        }
    )
    edges = @(
        @{ id = 'edge_research_writer'; source = 'researcher_01'; target = 'writer_01' }
    )
}
$created = Invoke-JsonMutation -Session $owner -Path '/api/workflows' -Body @{
    name = $graph.name
    description = $graph.description
    status = 'draft'
    graph_json = $graph
}
$workflowId = $created.data.workflow.id
$forbiddenBody = Invoke-ExpectedFailure `
    -Session $intruder `
    -Path "/api/workflows/$workflowId" `
    -Status 403
if ($forbiddenBody -match 'graph_json|Security acceptance graph') {
    throw 'The cross-user authorization failure exposed owner data.'
}

$ownerToken = "phase13-drive-$([Guid]::NewGuid().ToString('N'))"
Invoke-JsonMutation -Session $owner -Path '/api/integrations/google_drive/connect' -Body @{
    credentials = @{ access_token = $ownerToken }
} | Out-Null
$intruderStatus = Invoke-RestMethod `
    -Uri "$laravelUrl/api/integrations/google_drive/status" `
    -WebSession $intruder `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ($intruderStatus.data.integration.connected) {
    throw 'An integration connection crossed its owner boundary.'
}
Invoke-JsonMutation `
    -Session $intruder `
    -Path '/api/integrations/google_drive' `
    -Method 'Delete' `
    -Body @{} | Out-Null
$ownerStatus = Invoke-RestMethod `
    -Uri "$laravelUrl/api/integrations/google_drive/status" `
    -WebSession $owner `
    -Headers $browserHeaders `
    -TimeoutSec 10
if (-not $ownerStatus.data.integration.connected) {
    throw 'Another user was able to remove the owner integration.'
}

# Numeric Laravel throttles can share the same client signature across routes.
# Isolate the run limiter assertion from the two authentication setup requests
# above, then prove that exactly ten attempts are accepted and the next is 429.
docker compose exec -T laravel php artisan cache:clear | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Could not isolate the run-rate-limit acceptance scenario.'
}

foreach ($attempt in 1..10) {
    Invoke-ExpectedFailure `
        -Session $owner `
        -Path "/api/workflows/$workflowId/run" `
        -Status 422 `
        -Method 'Post' `
        -Body @{ mode = 'invalid'; idempotency_key = [Guid]::NewGuid().ToString() } | Out-Null
}
$rateBody = Invoke-ExpectedFailure `
    -Session $owner `
    -Path "/api/workflows/$workflowId/run" `
    -Status 429 `
    -Method 'Post' `
    -Body @{ mode = 'invalid'; idempotency_key = [Guid]::NewGuid().ToString() }
if ($rateBody -notmatch 'rate_limited') {
    throw 'The run rate limit did not return its safe machine-readable error code.'
}

$correlation = "phase13-$([Guid]::NewGuid().ToString('N'))"
$health = Invoke-WebRequest `
    -UseBasicParsing `
    -Uri "$laravelUrl/api/health" `
    -Headers @{ 'X-Correlation-ID' = $correlation } `
    -TimeoutSec 10
if ($health.Headers['X-Correlation-ID'] -ne $correlation) {
    throw 'The API did not preserve the safe correlation identifier.'
}
$deniedCors = Invoke-WebRequest `
    -UseBasicParsing `
    -Method Options `
    -Uri "$laravelUrl/api/health" `
    -Headers @{ Origin = 'https://untrusted.example'; 'Access-Control-Request-Method' = 'GET' } `
    -TimeoutSec 10
$allowedOrigin = $deniedCors.Headers['Access-Control-Allow-Origin']
if ($allowedOrigin -eq '*' -or $allowedOrigin -eq 'https://untrusted.example') {
    throw 'An untrusted production origin received a CORS allowance.'
}

Write-Host 'Cross-user workflow and integration access stayed isolated.'
Write-Host 'Run throttling returned a safe 429 envelope and correlation IDs were preserved.'
Write-Host 'The configured CORS boundary rejected an untrusted origin.'
Write-Host 'Phase 13 verification passed.'
