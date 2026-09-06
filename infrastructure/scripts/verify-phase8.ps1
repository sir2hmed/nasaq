param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase7Verifier = Join-Path $PSScriptRoot 'verify-phase7.ps1'

if ($SkipDocker) {
    & $phase7Verifier -SkipDocker
    Write-Host 'Phase 8 native provider-contract verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase7Verifier

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

function Wait-ForRun {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$RunId,
        [int]$TimeoutSeconds = 90
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        Start-Sleep -Milliseconds 300
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
$password = 'PhaseEightPass2026'
Invoke-JsonMutation -Session $session -Path '/api/auth/register' -Body @{
    name = 'Phase 8 Provider Owner'
    email = "phase8-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

$graph = @{
    version = 1
    name = 'Phase 8 real provider workflow'
    description = 'Real Tavily to OpenAI source-preservation acceptance'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 120; y = 180 }
            config = @{
                topic = 'Bilingual visual AI workflow orchestration'
                source_count = 3
                language = 'en'
                search_depth = 'basic'
            }
        },
        @{
            id = 'writer_01'
            type = 'writer'
            position = @{ x = 460; y = 180 }
            config = @{
                style = 'professional'
                length = 'short'
                format = 'article'
                language = 'same_as_input'
            }
        }
    )
    edges = @(
        @{ id = 'edge_research_writer'; source = 'researcher_01'; target = 'writer_01' }
    )
}
$workflowResponse = Invoke-JsonMutation -Session $session -Path '/api/workflows' -Body @{
    name = $graph.name
    description = $graph.description
    status = 'draft'
    graph_json = $graph
}
$workflowId = $workflowResponse.data.workflow.id
$started = Invoke-JsonMutation -Session $session -Path "/api/workflows/$workflowId/run" -Body @{
    mode = 'real'
    idempotency_key = [Guid]::NewGuid().ToString()
}
if ($started.data.run.demo_mode) {
    throw 'Laravel changed an explicit real-provider run into demo mode.'
}

$runId = $started.data.run.id
$completed = Wait-ForRun -Session $session -RunId $runId
$logsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/logs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$logs = @($logsResponse.data.logs)

$hasLiveCredentials = -not [string]::IsNullOrWhiteSpace($env:SEARCH_API_KEY) -and
    -not [string]::IsNullOrWhiteSpace($env:LLM_API_KEY)
if ($hasLiveCredentials) {
    if ($completed.status -ne 'success') {
        throw "The opt-in credentialed real-provider workflow failed: $($completed.status)."
    }
    $outputsResponse = Invoke-RestMethod `
        -Uri "$laravelUrl/api/runs/$runId/outputs" `
        -WebSession $session `
        -Headers $browserHeaders `
        -TimeoutSec 10
    $outputs = @($outputsResponse.data.outputs)
    $research = $outputs | Where-Object { $_.node_key -eq 'researcher_01' } | Select-Object -First 1
    $writer = $outputs | Where-Object { $_.node_key -eq 'writer_01' } | Select-Object -First 1
    if (-not $research -or -not $writer -or $research.content.sources[0].url -notmatch '^https?://') {
        throw 'The credentialed run did not preserve real Researcher source metadata.'
    }
    if (($writer.content.source_references | ConvertTo-Json -Depth 12 -Compress) -ne
        ($research.content.sources | ConvertTo-Json -Depth 12 -Compress)) {
        throw 'Writer did not preserve the exact Researcher source metadata.'
    }
    Write-Host 'Opt-in real Tavily and OpenAI execution succeeded with exact source preservation.'
}
else {
    if ($completed.status -ne 'failed') {
        throw "A real run without complete credentials should fail safely, not reach $($completed.status)."
    }
    $failureMessages = @($logs | Where-Object { $_.level -eq 'error' } | ForEach-Object { $_.message })
    if (($failureMessages -join ' ') -notmatch 'credentials are not configured') {
        throw 'The missing-key failure was not actionable for the operator.'
    }
    if (($failureMessages -join ' ') -match 'server-side-search-key|valid-search-key|valid-llm-key') {
        throw 'A provider credential appeared in user-visible logs.'
    }
    Write-Host 'Real mode without credentials failed safely with an actionable server-side configuration error.'
}

Write-Host 'Mock-transport tests verified valid Tavily/OpenAI contracts, strict structured output, retries, and exact Researcher-to-Writer source preservation.'
Write-Host 'Phase 8 verification passed.'
