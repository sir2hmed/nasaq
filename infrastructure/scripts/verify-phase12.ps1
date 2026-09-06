param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase11Verifier = Join-Path $PSScriptRoot 'verify-phase11.ps1'

if ($SkipDocker) {
    & $phase11Verifier -SkipDocker
    Write-Host 'Phase 12 native complete-library verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase11Verifier

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
    foreach ($attempt in 1..15) {
        try {
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
        catch {
            $statusCode = [int]$_.Exception.Response.StatusCode
            if ($statusCode -ne 429 -or $attempt -eq 15) {
                throw
            }
            Start-Sleep -Seconds 5
        }
    }
}

function Wait-ForStatus {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$RunId,
        [Parameter(Mandatory)] [string[]]$Statuses,
        [int]$TimeoutSeconds = 180
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        Start-Sleep -Milliseconds 400
        $response = Invoke-RestMethod `
            -Uri "$laravelUrl/api/runs/$RunId" `
            -WebSession $Session `
            -Headers $browserHeaders `
            -TimeoutSec 10
        $run = $response.data.run
    } while ($run.status -notin $Statuses -and (Get-Date) -lt $deadline)
    return $run
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $session
$password = 'PhaseTwelvePass2026'
Invoke-JsonMutation -Session $session -Path '/api/auth/register' -Body @{
    name = 'Phase 12 Complete Demo Owner'
    email = "phase12-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

$graph = @{
    version = 1
    name = 'Phase 12 full product workflow'
    description = 'All final agents, a human gate, simulated distribution, and real artifacts'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 80; y = 100 }
            config = @{
                topic = 'Complete bilingual visual AI workflows'
                source_count = 3
                language = 'en'
                search_depth = 'basic'
            }
        },
        @{
            id = 'writer_01'
            type = 'writer'
            position = @{ x = 340; y = 100 }
            config = @{
                style = 'professional'
                length = 'short'
                format = 'script'
                language = 'same_as_input'
            }
        },
        @{
            id = 'video_01'
            type = 'video'
            position = @{ x = 600; y = 100 }
            config = @{
                scene_duration = 1
                max_scenes = 3
                narration = 'silent'
                voice = 'alloy'
            }
        },
        @{
            id = 'approval_01'
            type = 'approval'
            position = @{ x = 860; y = 100 }
            config = @{}
        },
        @{
            id = 'publisher_01'
            type = 'publisher'
            position = @{ x = 1120; y = 100 }
            config = @{ destination = 'youtube'; privacy_status = 'private' }
        },
        @{
            id = 'email_01'
            type = 'email'
            position = @{ x = 1380; y = 100 }
            config = @{
                recipients = @('reviewer@example.test')
                subject = 'Nasaq Phase 12 full demo'
                body_template = "The approved video is ready:`n{links}"
            }
        },
        @{
            id = 'export_01'
            type = 'export'
            position = @{ x = 860; y = 380 }
            config = @{ formats = @('markdown', 'pdf', 'docx') }
        }
    )
    edges = @(
        @{ id = 'edge_research_writer'; source = 'researcher_01'; target = 'writer_01' },
        @{ id = 'edge_writer_video'; source = 'writer_01'; target = 'video_01' },
        @{ id = 'edge_video_approval'; source = 'video_01'; target = 'approval_01' },
        @{ id = 'edge_approval_publisher'; source = 'approval_01'; target = 'publisher_01' },
        @{ id = 'edge_publisher_email'; source = 'publisher_01'; target = 'email_01' },
        @{ id = 'edge_video_export'; source = 'video_01'; target = 'export_01' }
    )
}
$created = Invoke-JsonMutation -Session $session -Path '/api/workflows' -Body @{
    name = $graph.name
    description = $graph.description
    status = 'draft'
    graph_json = $graph
}
$workflowId = $created.data.workflow.id
$started = Invoke-JsonMutation -Session $session -Path "/api/workflows/$workflowId/run" -Body @{
    mode = 'demo'
    idempotency_key = [Guid]::NewGuid().ToString()
}
$runId = $started.data.run.id
$waiting = Wait-ForStatus -Session $session -RunId $runId -Statuses @('waiting_for_approval', 'failed')
if ($waiting.status -ne 'waiting_for_approval' -or
    $waiting.current_node_key -ne 'approval_01' -or
    $waiting.approvals[0].status -ne 'pending') {
    throw 'The complete workflow did not pause at its explicit human approval gate.'
}
$beforeOutputs = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$beforeNodeKeys = @($beforeOutputs.data.outputs | ForEach-Object { $_.node_key } | Select-Object -Unique)
if (@('publisher_01', 'email_01', 'export_01') | Where-Object { $_ -in $beforeNodeKeys }) {
    throw 'A post-approval side effect or export ran before human approval.'
}
if (@('researcher_01', 'writer_01', 'video_01') | Where-Object { $_ -notin $beforeNodeKeys }) {
    throw 'The full workflow did not produce its pre-approval research, script, and video outputs.'
}

$approvedResponse = Invoke-JsonMutation `
    -Session $session `
    -Path "/api/runs/$runId/approval/approval_01/approve" `
    -Body @{ comment = 'Phase 12 complete demo approved.' }
if ($approvedResponse.data.run.status -ne 'queued') {
    throw 'The approval decision did not queue the immutable workflow snapshot for resume.'
}
$completed = Wait-ForStatus -Session $session -RunId $runId -Statuses @('success', 'failed')
if ($completed.status -ne 'success') {
    throw "The approved complete workflow did not succeed: $($completed.status)."
}

$expectedNodes = @(
    'researcher_01',
    'writer_01',
    'video_01',
    'approval_01',
    'publisher_01',
    'email_01',
    'export_01'
)
foreach ($nodeId in $expectedNodes) {
    if ($completed.node_statuses.$nodeId -ne 'success') {
        throw "Node $nodeId does not display a successful final status."
    }
}

$outputsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$outputs = @($outputsResponse.data.outputs)
$outputNodes = @($outputs | ForEach-Object { $_.node_key } | Select-Object -Unique)
if ($expectedNodes | Where-Object { $_ -notin $outputNodes }) {
    throw 'At least one agent is missing from the persisted full-demo outputs.'
}
$video = $outputs | Where-Object { $_.node_key -eq 'video_01' -and $_.mime_type -eq 'video/mp4' } | Select-Object -First 1
$publication = $outputs | Where-Object { $_.node_key -eq 'publisher_01' -and $_.output_type -eq 'publication' } | Select-Object -First 1
$email = $outputs | Where-Object { $_.node_key -eq 'email_01' -and $_.output_type -eq 'email_delivery' } | Select-Object -First 1
$emailPreview = $outputs | Where-Object { $_.node_key -eq 'email_01' -and $_.mime_type -eq 'message/rfc822' } | Select-Object -First 1
$exportFiles = @($outputs | Where-Object { $_.node_key -eq 'export_01' -and $_.output_type -eq 'artifact' })
if (-not $video -or -not $publication -or -not $email -or -not $emailPreview -or
    $publication.public_url -notmatch '^https://youtube\.example\.invalid/' -or
    $email.content.delivery_status -ne 'simulated' -or
    $exportFiles.Count -ne 3) {
    throw 'The full-demo video, publication, delivery, preview, or exported documents are incomplete.'
}

$logsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/logs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$succeededNodes = @(
    $logsResponse.data.logs |
        Where-Object { $_.event_type -eq 'NODE_SUCCEEDED' } |
        ForEach-Object { $_.node_key } |
        Select-Object -Unique
)
if ($expectedNodes | Where-Object { $_ -notin $succeededNodes }) {
    throw 'The execution timeline is missing a successful event for at least one agent.'
}

Write-Host 'The complete seven-agent workflow paused before every post-review action.'
Write-Host 'Approval resumed the same run through Celery and every node displayed success.'
Write-Host 'Research, script, MP4, publication, email preview, and three export files were persisted.'
Write-Host 'The timeline contains successful events for every agent in the library.'
Write-Host 'Phase 12 verification passed.'
