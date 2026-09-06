param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase3Verifier = Join-Path $PSScriptRoot 'verify-phase3.ps1'

if ($SkipDocker) {
    & $phase3Verifier -SkipDocker
    Write-Host 'Phase 4 native verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase3Verifier

$frontendUrl = 'http://localhost:5173'
$laravelUrl = 'http://localhost:8000'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$browserHeaders = @{
    Accept = 'application/json'
    Origin = $frontendUrl
    Referer = "$frontendUrl/"
}

Invoke-WebRequest `
    -UseBasicParsing `
    -Uri "$laravelUrl/sanctum/csrf-cookie" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10 | Out-Null

function Invoke-JsonMutation {
    param(
        [Parameter(Mandatory)]
        [ValidateSet('Post', 'Put')]
        [string]$Method,
        [Parameter(Mandatory)]
        [string]$Path,
        [hashtable]$Body = @{}
    )

    $xsrfCookie = $session.Cookies.GetCookies([Uri]$laravelUrl)['XSRF-TOKEN']
    if (-not $xsrfCookie) {
        throw 'The browser session does not contain an XSRF-TOKEN cookie.'
    }

    return Invoke-RestMethod `
        -Method $Method `
        -Uri "$laravelUrl$Path" `
        -WebSession $session `
        -Headers @{
            Accept = 'application/json'
            Origin = $frontendUrl
            Referer = "$frontendUrl/"
            'X-XSRF-TOKEN' = [Uri]::UnescapeDataString($xsrfCookie.Value)
        } `
        -ContentType 'application/json' `
        -Body ($Body | ConvertTo-Json -Depth 20) `
        -TimeoutSec 10
}

$password = 'PhaseFourPass2026'
$email = "phase4-$([Guid]::NewGuid().ToString('N'))@example.test"
Invoke-JsonMutation -Method Post -Path '/api/auth/register' -Body @{
    name = 'Phase 4 Canvas User'
    email = $email
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

$graph = @{
    version = 1
    name = 'Phase 4 visual workflow'
    description = 'Researcher to Writer to Export canvas acceptance'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 120; y = 180 }
            config = @{
                topic = 'Visual AI workflows'
                source_count = 5
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
        @{ id = 'edge_researcher_writer'; source = 'researcher_01'; target = 'writer_01' },
        @{ id = 'edge_writer_export'; source = 'writer_01'; target = 'export_01' }
    )
}
$payload = @{
    name = $graph.name
    description = $graph.description
    status = 'draft'
    graph_json = $graph
}

$created = Invoke-JsonMutation -Method Post -Path '/api/workflows' -Body $payload
$workflowId = $created.data.workflow.id

$valid = Invoke-JsonMutation -Method Post -Path "/api/workflows/$workflowId/validate"
if (-not $valid.data.validation.valid -or
    $valid.data.validation.errors.Count -ne 0 -or
    $valid.data.validation.warnings.Count -ne 0) {
    throw 'The configured Researcher to Writer to Export DAG did not validate.'
}

$cyclePayload = @{
    name = $payload.name
    description = $payload.description
    status = $payload.status
    graph_json = @{
        version = $graph.version
        name = $graph.name
        description = $graph.description
        nodes = $graph.nodes
        edges = @(
            $graph.edges[0],
            $graph.edges[1],
            @{ id = 'edge_export_researcher'; source = 'export_01'; target = 'researcher_01' }
        )
    }
}

try {
    Invoke-JsonMutation -Method Put -Path "/api/workflows/$workflowId" -Body $cyclePayload | Out-Null
    throw 'A cyclic graph was accepted by the workflow API.'
}
catch {
    if (-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 422) {
        throw
    }
}

$graph.nodes[0].position.x = 333.5
$graph.nodes[0].position.y = 244.25
$graph.nodes[0].config.topic = ''
$warningUpdate = Invoke-JsonMutation -Method Put -Path "/api/workflows/$workflowId" -Body $payload
if ($warningUpdate.data.workflow.graph_json.nodes[0].position.x -ne 333.5 -or
    $warningUpdate.data.workflow.graph_json.nodes[0].position.y -ne 244.25) {
    throw 'Updated visual node positions were not persisted.'
}

$reloaded = Invoke-RestMethod `
    -Uri "$laravelUrl/api/workflows/$workflowId" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
if ($reloaded.data.workflow.graph_json.nodes[0].position.x -ne 333.5 -or
    $reloaded.data.workflow.graph_json.nodes[0].position.y -ne 244.25) {
    throw 'Reloading the visual workflow did not preserve exact positions.'
}

$warnings = Invoke-JsonMutation -Method Post -Path "/api/workflows/$workflowId/validate"
$topicWarning = $warnings.data.validation.warnings | Where-Object {
    $_.code -eq 'required_config' -and $_.node_id -eq 'researcher_01' -and $_.field -eq 'topic'
}
if ($warnings.data.validation.valid -or
    $warnings.data.validation.errors.Count -ne 0 -or
    -not $topicWarning) {
    throw 'Missing required node configuration was not returned as a visible warning.'
}

$frontend = Invoke-WebRequest `
    -UseBasicParsing `
    -Uri "$frontendUrl/workflows/$workflowId" `
    -TimeoutSec 10
if ($frontend.StatusCode -ne 200 -or $frontend.Content -notmatch '<div id="root"></div>') {
    throw 'The visual workflow editor route did not load through the SPA fallback.'
}

Write-Host 'Real DAG validation, cycle rejection, exact position reload, configuration warning, and editor-route checks passed.'
Write-Host 'Phase 4 verification passed.'
