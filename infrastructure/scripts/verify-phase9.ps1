param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase8Verifier = Join-Path $PSScriptRoot 'verify-phase8.ps1'

if ($SkipDocker) {
    & $phase8Verifier -SkipDocker
    Write-Host 'Phase 9 native approval verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase8Verifier

# The cumulative verifier intentionally creates several isolated browser users in
# Phases 2-8. Reset only the transient cache before this next isolated phase so
# those earlier authentication attempts do not consume Phase 9's production
# authentication-rate-limit budget. Phase 13 exercises the limiter itself.
docker compose exec -T laravel php artisan cache:clear | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Could not reset transient Laravel cache between isolated acceptance phases.'
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

function Wait-ForStatus {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$RunId,
        [Parameter(Mandatory)] [string[]]$Statuses,
        [int]$TimeoutSeconds = 60
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
    } while ($run.status -notin $Statuses -and (Get-Date) -lt $deadline)
    return $run
}

function New-ApprovalWorkflow {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$Name
    )

    $graph = @{
        version = 1
        name = $Name
        description = 'Research, write, review, then export'
        nodes = @(
            @{
                id = 'researcher_01'
                type = 'researcher'
                position = @{ x = 120; y = 180 }
                config = @{
                    topic = 'Human-reviewed visual AI workflows'
                    source_count = 3
                    language = 'en'
                    search_depth = 'basic'
                }
            },
            @{
                id = 'writer_01'
                type = 'writer'
                position = @{ x = 420; y = 180 }
                config = @{
                    style = 'professional'
                    length = 'short'
                    format = 'article'
                    language = 'same_as_input'
                }
            },
            @{
                id = 'approval_01'
                type = 'approval'
                position = @{ x = 700; y = 180 }
                config = @{}
            },
            @{
                id = 'export_01'
                type = 'export'
                position = @{ x = 960; y = 180 }
                config = @{ formats = @('markdown', 'pdf', 'docx') }
            }
        )
        edges = @(
            @{ id = 'edge_research_writer'; source = 'researcher_01'; target = 'writer_01' },
            @{ id = 'edge_writer_approval'; source = 'writer_01'; target = 'approval_01' },
            @{ id = 'edge_approval_export'; source = 'approval_01'; target = 'export_01' }
        )
    }
    $created = Invoke-JsonMutation -Session $Session -Path '/api/workflows' -Body @{
        name = $Name
        description = $graph.description
        status = 'draft'
        graph_json = $graph
    }
    return $created.data.workflow.id
}

function Start-DemoRun {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [int]$WorkflowId
    )

    $started = Invoke-JsonMutation -Session $Session -Path "/api/workflows/$WorkflowId/run" -Body @{
        mode = 'demo'
        idempotency_key = [Guid]::NewGuid().ToString()
    }
    return $started.data.run.id
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $session
$password = 'PhaseNinePass2026'
Invoke-JsonMutation -Session $session -Path '/api/auth/register' -Body @{
    name = 'Phase 9 Reviewer'
    email = "phase9-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

$approvedWorkflowId = New-ApprovalWorkflow -Session $session -Name 'Phase 9 approval resume'
$approvedRunId = Start-DemoRun -Session $session -WorkflowId $approvedWorkflowId
$waiting = Wait-ForStatus -Session $session -RunId $approvedRunId -Statuses @('waiting_for_approval', 'failed')
if ($waiting.status -ne 'waiting_for_approval' -or
    $waiting.current_node_key -ne 'approval_01' -or
    $waiting.approvals[0].status -ne 'pending' -or
    -not $waiting.approvals[0].preview_output.text_content) {
    throw 'The workflow did not pause with a persisted human-review preview.'
}
$beforeOutputs = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$approvedRunId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ('export_01' -in @($beforeOutputs.data.outputs | ForEach-Object { $_.node_key })) {
    throw 'Export executed before the approval decision.'
}

$approvalResponse = Invoke-JsonMutation `
    -Session $session `
    -Path "/api/runs/$approvedRunId/approval/approval_01/approve" `
    -Body @{ comment = 'Phase 9 acceptance approved.' }
if ($approvalResponse.data.run.status -ne 'queued' -or
    $approvalResponse.data.run.approvals[0].status -ne 'approved') {
    throw 'Approval was not persisted before resume was queued.'
}
$approved = Wait-ForStatus -Session $session -RunId $approvedRunId -Statuses @('success', 'failed')
if ($approved.status -ne 'success') {
    throw "The approved workflow did not resume successfully: $($approved.status)."
}
$afterOutputs = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$approvedRunId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$afterNodeKeys = @($afterOutputs.data.outputs | ForEach-Object { $_.node_key })
if ('approval_01' -notin $afterNodeKeys -or 'export_01' -notin $afterNodeKeys) {
    throw 'The approved workflow did not execute the approval and export nodes.'
}

$rejectedWorkflowId = New-ApprovalWorkflow -Session $session -Name 'Phase 9 safe rejection'
$rejectedRunId = Start-DemoRun -Session $session -WorkflowId $rejectedWorkflowId
$rejectWaiting = Wait-ForStatus -Session $session -RunId $rejectedRunId -Statuses @('waiting_for_approval', 'failed')
if ($rejectWaiting.status -ne 'waiting_for_approval') {
    throw 'The rejection scenario did not reach human review.'
}
$rejectionResponse = Invoke-JsonMutation `
    -Session $session `
    -Path "/api/runs/$rejectedRunId/approval/approval_01/reject" `
    -Body @{ comment = 'Phase 9 acceptance rejected.' }
if ($rejectionResponse.data.run.status -ne 'failed' -or
    $rejectionResponse.data.run.approvals[0].status -ne 'rejected') {
    throw 'Rejection did not stop the workflow safely.'
}
$rejectedOutputs = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$rejectedRunId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ('export_01' -in @($rejectedOutputs.data.outputs | ForEach-Object { $_.node_key })) {
    throw 'A rejected workflow performed the gated export action.'
}

Write-Host 'A four-node workflow paused with a persisted Writer preview before the gated action.'
Write-Host 'Approval resumed through Celery and produced Approval plus real Export outputs.'
Write-Host 'A separate rejection persisted the reviewer decision and performed no gated action.'
Write-Host 'Phase 9 verification passed.'
