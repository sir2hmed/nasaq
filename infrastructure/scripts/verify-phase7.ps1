param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase6Verifier = Join-Path $PSScriptRoot 'verify-phase6.ps1'

if ($SkipDocker) {
    & $phase6Verifier -SkipDocker
    Write-Host 'Phase 7 native verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase6Verifier

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
        [Parameter(Mandatory)] [hashtable]$Body
    )

    $xsrfCookie = $Session.Cookies.GetCookies([Uri]$laravelUrl)['XSRF-TOKEN']
    if (-not $xsrfCookie) {
        throw 'The browser session does not contain an XSRF-TOKEN cookie.'
    }
    return Invoke-RestMethod `
        -Method Post `
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

function New-ExecutionGraph {
    param(
        [Parameter(Mandatory)] [string]$Name,
        [Parameter(Mandatory)] [int]$TransientFailures
    )

    return @{
        version = 1
        name = $Name
        description = 'Phase 7 asynchronous execution acceptance'
        nodes = @(
            @{
                id = 'researcher_01'
                type = 'researcher'
                position = @{ x = 120; y = 180 }
                config = @{
                    topic = 'Asynchronous visual AI workflows'
                    source_count = 3
                    language = 'en'
                    search_depth = 'basic'
                    simulate_transient_failures = $TransientFailures
                }
            },
            @{
                id = 'writer_01'
                type = 'writer'
                position = @{ x = 460; y = 180 }
                config = @{
                    style = 'professional'
                    length = 'medium'
                    format = 'article'
                    language = 'same_as_input'
                }
            },
            @{
                id = 'export_01'
                type = 'export'
                position = @{ x = 800; y = 180 }
                config = @{ formats = @('markdown', 'pdf', 'docx') }
            }
        )
        edges = @(
            @{ id = 'edge_research_writer'; source = 'researcher_01'; target = 'writer_01' },
            @{ id = 'edge_writer_export'; source = 'writer_01'; target = 'export_01' }
        )
    }
}

function New-Workflow {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [hashtable]$Graph
    )

    $created = Invoke-JsonMutation -Session $Session -Path '/api/workflows' -Body @{
        name = $Graph.name
        description = $Graph.description
        status = 'draft'
        graph_json = $Graph
    }
    return $created.data.workflow.id
}

function Wait-ForRun {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$RunId,
        [int]$TimeoutSeconds = 45
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        Start-Sleep -Milliseconds 250
        $response = Invoke-RestMethod `
            -Uri "$laravelUrl/api/runs/$RunId" `
            -WebSession $Session `
            -Headers $browserHeaders `
            -TimeoutSec 10
        $run = $response.data.run
    } while ($run.status -notin @('success', 'failed', 'cancelled') -and (Get-Date) -lt $deadline)
    return $run
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $session
$password = 'PhaseSevenPass2026'
Invoke-JsonMutation -Session $session -Path '/api/auth/register' -Body @{
    name = 'Phase 7 Run Owner'
    email = "phase7-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

$retryGraph = New-ExecutionGraph -Name 'Phase 7 retry workflow' -TransientFailures 2
$retryWorkflowId = New-Workflow -Session $session -Graph $retryGraph
$stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
$started = Invoke-JsonMutation -Session $session -Path "/api/workflows/$retryWorkflowId/run" -Body @{
    mode = 'demo'
    idempotency_key = [Guid]::NewGuid().ToString()
}
$stopwatch.Stop()
$retryRunId = $started.data.run.id
if ($stopwatch.ElapsedMilliseconds -ge 1200) {
    throw "The asynchronous run endpoint took $($stopwatch.ElapsedMilliseconds) ms."
}
if ($started.data.run.status -ne 'queued' -or -not $started.data.run.task_id) {
    throw 'The run was not queued with a Celery task ID.'
}

$healthStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
$health = Invoke-RestMethod -Uri "$laravelUrl/api/health" -TimeoutSec 10
$healthStopwatch.Stop()
if ($health.data.status -ne 'ok' -or $healthStopwatch.ElapsedMilliseconds -ge 2000) {
    throw 'The Laravel API was not responsive while the Celery task was running.'
}

$retryRun = Wait-ForRun -Session $session -RunId $retryRunId
if ($retryRun.status -ne 'success') {
    throw "The retry workflow did not succeed. Final status: $($retryRun.status)."
}
$retryLogsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$retryRunId/logs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$retryLogs = @($retryLogsResponse.data.logs)
$retryEvents = @($retryLogs | Where-Object { $_.event_type -eq 'NODE_RETRYING' })
if ($retryEvents.Count -ne 2 -or
    $retryEvents[0].context.attempt -ne 2 -or
    $retryEvents[1].context.attempt -ne 3 -or
    $retryEvents[0].message -notmatch 'transient') {
    throw 'The transient failure did not persist two understandable retry events.'
}

$cancelGraph = New-ExecutionGraph -Name 'Phase 7 cancellation workflow' -TransientFailures 3
$cancelWorkflowId = New-Workflow -Session $session -Graph $cancelGraph
$cancelStarted = Invoke-JsonMutation -Session $session -Path "/api/workflows/$cancelWorkflowId/run" -Body @{
    mode = 'demo'
    idempotency_key = [Guid]::NewGuid().ToString()
}
$cancelRunId = $cancelStarted.data.run.id
$cancelResponse = Invoke-JsonMutation -Session $session -Path "/api/runs/$cancelRunId/cancel" -Body @{}
if (-not $cancelResponse.data.run.cancellation_requested) {
    throw 'Laravel did not persist the cancellation request.'
}

$cancelledRun = Wait-ForRun -Session $session -RunId $cancelRunId
if ($cancelledRun.status -ne 'cancelled') {
    throw "The worker did not acknowledge cancellation. Final status: $($cancelledRun.status)."
}
$cancelLogsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$cancelRunId/logs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ('RUN_CANCELLED' -notin @($cancelLogsResponse.data.logs | ForEach-Object { $_.event_type })) {
    throw 'The cancellation callback was not persisted for the user.'
}

Write-Host "Run submission returned in $($stopwatch.ElapsedMilliseconds) ms while Celery executed through Redis."
Write-Host 'Two exponential-backoff retry attempts were persisted with attempts 2 and 3.'
Write-Host 'A second queued run accepted cooperative cancellation and reached cancelled.'
Write-Host 'Phase 7 verification passed.'
