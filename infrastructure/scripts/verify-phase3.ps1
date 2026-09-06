param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase2Verifier = Join-Path $PSScriptRoot 'verify-phase2.ps1'

if ($SkipDocker) {
    & $phase2Verifier -SkipDocker
    Write-Host 'Phase 3 native verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase2Verifier

$frontendUrl = 'http://localhost:5173'
$laravelUrl = 'http://localhost:8000'
$browserHeaders = @{
    Accept = 'application/json'
    Origin = $frontendUrl
    Referer = "$frontendUrl/"
}

function Initialize-BrowserSession {
    param(
        [Parameter(Mandatory)]
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )

    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl/sanctum/csrf-cookie" `
        -WebSession $Session `
        -Headers $browserHeaders `
        -TimeoutSec 10 | Out-Null
}

function Invoke-JsonMutation {
    param(
        [Parameter(Mandatory)]
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)]
        [ValidateSet('Post', 'Put', 'Patch', 'Delete')]
        [string]$Method,
        [Parameter(Mandatory)]
        [string]$Path,
        [hashtable]$Body = @{}
    )

    $xsrfCookie = $Session.Cookies.GetCookies([Uri]$laravelUrl)['XSRF-TOKEN']
    if (-not $xsrfCookie) {
        throw 'The browser session does not contain an XSRF-TOKEN cookie.'
    }

    $headers = @{
        Accept = 'application/json'
        Origin = $frontendUrl
        Referer = "$frontendUrl/"
        'X-XSRF-TOKEN' = [Uri]::UnescapeDataString($xsrfCookie.Value)
    }

    return Invoke-RestMethod `
        -Method $Method `
        -Uri "$laravelUrl$Path" `
        -WebSession $Session `
        -Headers $headers `
        -ContentType 'application/json' `
        -Body ($Body | ConvertTo-Json -Depth 20) `
        -TimeoutSec 10
}

function Assert-JsonStatus {
    param(
        [Parameter(Mandatory)]
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)]
        [ValidateSet('Get', 'Post', 'Put', 'Delete')]
        [string]$Method,
        [Parameter(Mandatory)]
        [string]$Path,
        [Parameter(Mandatory)]
        [int]$ExpectedStatus,
        [hashtable]$Body = @{}
    )

    try {
        if ($Method -eq 'Get') {
            Invoke-RestMethod `
                -Method Get `
                -Uri "$laravelUrl$Path" `
                -WebSession $Session `
                -Headers $browserHeaders `
                -TimeoutSec 10 | Out-Null
        }
        else {
            Invoke-JsonMutation -Session $Session -Method $Method -Path $Path -Body $Body | Out-Null
        }
        throw "Expected HTTP $ExpectedStatus from $Method $Path, but the request succeeded."
    }
    catch {
        if (-not $_.Exception.Response) {
            throw
        }

        $actualStatus = [int]$_.Exception.Response.StatusCode
        if ($actualStatus -ne $ExpectedStatus) {
            throw
        }
    }
}

$ownerSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$intruderSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $ownerSession
Initialize-BrowserSession -Session $intruderSession

$password = 'PhaseThreePass2026'
$ownerEmail = "phase3-owner-$([Guid]::NewGuid().ToString('N'))@example.test"
$intruderEmail = "phase3-intruder-$([Guid]::NewGuid().ToString('N'))@example.test"

Invoke-JsonMutation -Session $ownerSession -Method Post -Path '/api/auth/register' -Body @{
    name = 'Phase 3 Owner'
    email = $ownerEmail
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

Invoke-JsonMutation -Session $intruderSession -Method Post -Path '/api/auth/register' -Body @{
    name = 'Phase 3 Intruder'
    email = $intruderEmail
    password = $password
    password_confirmation = $password
    preferred_locale = 'ar'
} | Out-Null

$graph = @{
    version = 1
    name = 'Phase 3 persisted workflow'
    description = 'Container acceptance graph'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 120; y = 180 }
            config = @{ topic = 'Bilingual AI workflows'; source_count = 5; language = 'en' }
        },
        @{
            id = 'writer_01'
            type = 'writer'
            position = @{ x = 460; y = 180 }
            config = @{ style = 'professional'; language = 'same_as_input' }
        },
        @{
            id = 'export_01'
            type = 'export'
            position = @{ x = 800; y = 180 }
            config = @{ formats = @('markdown', 'pdf', 'docx') }
        }
    )
    edges = @(
        @{ id = 'edge_researcher_writer'; source = 'researcher_01'; target = 'writer_01' },
        @{ id = 'edge_writer_export'; source = 'writer_01'; target = 'export_01' }
    )
}
$workflowPayload = @{
    name = 'Phase 3 persisted workflow'
    description = 'Container acceptance graph'
    status = 'draft'
    graph_json = $graph
}

$created = Invoke-JsonMutation `
    -Session $ownerSession `
    -Method Post `
    -Path '/api/workflows' `
    -Body $workflowPayload
$workflowId = $created.data.workflow.id
if (-not $workflowId -or $created.data.workflow.node_count -ne 3 -or $created.data.workflow.edge_count -ne 2) {
    throw 'Workflow creation did not persist the complete graph.'
}

$ownerList = Invoke-RestMethod `
    -Uri "$laravelUrl/api/workflows" `
    -WebSession $ownerSession `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ($ownerList.meta.total -ne 1 -or $ownerList.data.workflows[0].id -ne $workflowId) {
    throw 'The owner workflow list did not restore the created workflow.'
}

$reloaded = Invoke-RestMethod `
    -Uri "$laravelUrl/api/workflows/$workflowId" `
    -WebSession $ownerSession `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ($reloaded.data.workflow.graph_json.nodes[1].id -ne 'writer_01' -or
    $reloaded.data.workflow.graph_json.edges[1].target -ne 'export_01') {
    throw 'Reloading the workflow did not return the exact stored graph.'
}

$workflowPayload.name = 'Phase 3 updated workflow'
$workflowPayload.description = 'Updated through the container acceptance gate'
$workflowPayload.graph_json.name = $workflowPayload.name
$workflowPayload.graph_json.description = $workflowPayload.description
$updated = Invoke-JsonMutation `
    -Session $ownerSession `
    -Method Put `
    -Path "/api/workflows/$workflowId" `
    -Body $workflowPayload
if ($updated.data.workflow.name -ne 'Phase 3 updated workflow') {
    throw 'Workflow update did not persist.'
}

$duplicated = Invoke-JsonMutation `
    -Session $ownerSession `
    -Method Post `
    -Path "/api/workflows/$workflowId/duplicate"
if ($duplicated.data.workflow.id -eq $workflowId -or $duplicated.data.workflow.node_count -ne 3) {
    throw 'Workflow duplication did not produce an independent graph.'
}

$intruderList = Invoke-RestMethod `
    -Uri "$laravelUrl/api/workflows" `
    -WebSession $intruderSession `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ($intruderList.meta.total -ne 0) {
    throw 'A second user could see workflows they do not own.'
}
Assert-JsonStatus `
    -Session $intruderSession `
    -Method Get `
    -Path "/api/workflows/$workflowId" `
    -ExpectedStatus 403

Invoke-JsonMutation `
    -Session $ownerSession `
    -Method Delete `
    -Path "/api/workflows/$workflowId" | Out-Null
Assert-JsonStatus `
    -Session $ownerSession `
    -Method Get `
    -Path "/api/workflows/$workflowId" `
    -ExpectedStatus 404

$frontend = Invoke-WebRequest `
    -UseBasicParsing `
    -Uri "$frontendUrl/workflows/$($duplicated.data.workflow.id)" `
    -TimeoutSec 10
if ($frontend.StatusCode -ne 200 -or $frontend.Content -notmatch '<div id="root"></div>') {
    throw 'The frontend did not serve the reloadable workflow detail route.'
}

Write-Host 'Real workflow create, list, reload, update, duplicate, ownership, delete, and SPA detail checks passed.'
Write-Host 'Phase 3 verification passed.'
