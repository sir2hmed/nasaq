param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$phase5Verifier = Join-Path $PSScriptRoot 'verify-phase5.ps1'

if ($SkipDocker) {
    & $phase5Verifier -SkipDocker
    Write-Host 'Phase 6 native verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase5Verifier

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
        [Parameter(Mandatory)] [ValidateSet('Post', 'Put', 'Patch')] [string]$Method,
        [Parameter(Mandatory)] [string]$Path,
        [Parameter(Mandatory)] [hashtable]$Body
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

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $session
$password = 'PhaseSixPass2026'
$email = "phase6-$([Guid]::NewGuid().ToString('N'))@example.test"
Invoke-JsonMutation -Session $session -Method Post -Path '/api/auth/register' -Body @{
    name = 'Phase 6 Run Owner'
    email = $email
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

$graph = @{
    version = 1
    name = 'Phase 6 integrated workflow'
    description = 'Laravel to FastAPI callback acceptance'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 120; y = 180 }
            config = @{
                topic = 'Bilingual visual AI workflows'
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
$created = Invoke-JsonMutation -Session $session -Method Post -Path '/api/workflows' -Body @{
    name = $graph.name
    description = $graph.description
    status = 'draft'
    graph_json = $graph
}
$workflowId = $created.data.workflow.id

$started = Invoke-JsonMutation -Session $session -Method Post -Path "/api/workflows/$workflowId/run" -Body @{
    mode = 'demo'
    idempotency_key = [Guid]::NewGuid().ToString()
}
$runId = $started.data.run.id
if ($started.data.run.status -notin @('queued', 'running', 'success')) {
    throw 'Laravel did not return an accepted workflow run.'
}

$deadline = (Get-Date).AddSeconds(45)
do {
    Start-Sleep -Milliseconds 500
    $runResponse = Invoke-RestMethod `
        -Uri "$laravelUrl/api/runs/$runId" `
        -WebSession $session `
        -Headers $browserHeaders `
        -TimeoutSec 10
    $run = $runResponse.data.run
} while ($run.status -notin @('success', 'failed', 'cancelled') -and (Get-Date) -lt $deadline)

if ($run.status -ne 'success' -or
    $run.node_statuses.researcher_01 -ne 'success' -or
    $run.node_statuses.writer_01 -ne 'success' -or
    $run.node_statuses.export_01 -ne 'success') {
    throw "The integrated workflow did not reach success. Final status: $($run.status)."
}

$logsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/logs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$outputsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$logs = @($logsResponse.data.logs)
$outputs = @($outputsResponse.data.outputs)
$eventTypes = @($logs | ForEach-Object { $_.event_type })
if ($logs.Count -ne 8 -or
    'RUN_STARTED' -notin $eventTypes -or
    'RUN_SUCCEEDED' -notin $eventTypes -or
    @($eventTypes | Where-Object { $_ -eq 'NODE_SUCCEEDED' }).Count -ne 3) {
    throw 'The protected callback timeline was not fully persisted.'
}

$writerOutput = $outputs | Where-Object {
    $_.node_key -eq 'writer_01' -and $_.output_type -eq 'text'
} | Select-Object -First 1
$artifacts = @($outputs | Where-Object { $_.output_type -eq 'artifact' })
if (-not $writerOutput -or $writerOutput.text_content -notmatch 'DEMO MODE' -or $artifacts.Count -ne 3) {
    throw 'The output API did not return the persisted Writer preview and three artifacts.'
}

$downloadDirectory = Join-Path $root ".tmp\phase6-$runId"
New-Item -ItemType Directory -Path $downloadDirectory -Force | Out-Null
foreach ($artifact in $artifacts) {
    if (-not $artifact.download_url -or -not $artifact.file_name) {
        throw 'Artifact output is missing an authorized download URL or file name.'
    }
    $target = Join-Path $downloadDirectory $artifact.file_name
    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl$($artifact.download_url)" `
        -WebSession $session `
        -Headers @{ Origin = $frontendUrl; Referer = "$frontendUrl/" } `
        -OutFile $target `
        -TimeoutSec 20
    $bytes = [System.IO.File]::ReadAllBytes($target)
    if ($bytes.Length -le 0) {
        throw "Downloaded artifact $($artifact.file_name) is empty."
    }
    switch ([System.IO.Path]::GetExtension($target)) {
        '.md' {
            if ((Get-Content -Raw -LiteralPath $target) -notmatch 'DEMO MODE') {
                throw 'Downloaded Markdown is missing its demo disclosure.'
            }
        }
        '.pdf' {
            if ([System.Text.Encoding]::ASCII.GetString($bytes[0..4]) -ne '%PDF-') {
                throw 'Downloaded PDF signature is invalid.'
            }
        }
        '.docx' {
            if ($bytes[0] -ne 0x50 -or $bytes[1] -ne 0x4B) {
                throw 'Downloaded DOCX container signature is invalid.'
            }
        }
    }
}

$invalidEvent = @{
    event_id = [Guid]::NewGuid().ToString()
    event_type = 'RUN_STARTED'
    correlation_id = $run.correlation_id
    node_key = $null
    occurred_at = (Get-Date).ToUniversalTime().ToString('o')
    attempt = 1
    message = 'Unauthorized replay attempt.'
    data = @{}
}
try {
    Invoke-WebRequest `
        -UseBasicParsing `
        -Method Post `
        -Uri "$laravelUrl/api/internal/executions/$runId/events" `
        -Headers @{ 'X-Nasaq-Service-Token' = 'wrong-token' } `
        -ContentType 'application/json' `
        -Body ($invalidEvent | ConvertTo-Json -Depth 10) `
        -TimeoutSec 10 | Out-Null
    throw 'The internal callback accepted an invalid service token.'
}
catch {
    if (-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 401) {
        throw
    }
}

$intruderSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $intruderSession
Invoke-JsonMutation -Session $intruderSession -Method Post -Path '/api/auth/register' -Body @{
    name = 'Phase 6 Intruder'
    email = "phase6-intruder-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null
try {
    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl/api/runs/$runId" `
        -WebSession $intruderSession `
        -Headers $browserHeaders `
        -TimeoutSec 10 | Out-Null
    throw 'A different user accessed the workflow run.'
}
catch {
    if (-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 403) {
        throw
    }
}

Write-Host 'Laravel started FastAPI execution; protected callbacks persisted the complete status and log timeline.'
Write-Host 'React polling contracts returned node states, Writer preview, and three owner-authorized real downloads.'
Write-Host 'Service-token rejection and cross-user run isolation passed.'
Write-Host 'Phase 6 verification passed.'
