param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$phase4Verifier = Join-Path $PSScriptRoot 'verify-phase4.ps1'

if ($SkipDocker) {
    & $phase4Verifier -SkipDocker
    Write-Host 'Phase 5 native verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase4Verifier

$runId = "phase5-$([Guid]::NewGuid().ToString('N'))"
$graph = @{
    version = 1
    name = 'Phase 5 demo execution'
    description = 'Researcher to Writer to Export acceptance'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 120; y = 180 }
            config = @{
                topic = 'Visual AI workflows'
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
$request = @{
    run_id = $runId
    user_id = 'phase5-acceptance-user'
    selected_language = 'en'
    correlation_id = "correlation-$runId"
    workflow = $graph
}

$result = Invoke-RestMethod `
    -Method Post `
    -Uri 'http://127.0.0.1:8001/internal/demo-executions' `
    -ContentType 'application/json' `
    -Body ($request | ConvertTo-Json -Depth 20) `
    -TimeoutSec 30

if ($result.status -ne 'success' -or -not $result.demo_mode) {
    throw 'The containerized three-node demo workflow did not succeed in demo mode.'
}
if (($result.execution_order -join ',') -ne 'researcher_01,writer_01,export_01') {
    throw 'The containerized workflow did not execute in deterministic topological order.'
}
if ($result.outputs.researcher_01.data.sources.Count -ne 3 -or
    $result.outputs.researcher_01.data.sources[0].url -notlike 'demo://*' -or
    -not $result.outputs.researcher_01.data.sources[0].is_demo) {
    throw 'Researcher output was not deterministic, structured, and explicitly simulated.'
}
if ($result.outputs.writer_01.data.demo_notice -notlike 'DEMO MODE*') {
    throw 'Writer output did not clearly disclose demo research data.'
}
if ($result.artifacts.Count -ne 3) {
    throw 'Export did not return all three required artifacts.'
}

$dockerCandidates = @(
    (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'),
    (Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe')
)
$docker = $dockerCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $docker) {
    $docker = (Get-Command docker -ErrorAction Stop).Source
}

$artifactCheck = @'
import hashlib
import pathlib
import sys
import zipfile

path = pathlib.Path(sys.argv[1])
expected_checksum = sys.argv[2]
if not path.is_file() or path.stat().st_size <= 0:
    raise SystemExit('artifact is missing or empty')
if hashlib.sha256(path.read_bytes()).hexdigest() != expected_checksum:
    raise SystemExit('artifact checksum mismatch')
if path.suffix == '.md' and 'DEMO MODE' not in path.read_text(encoding='utf-8'):
    raise SystemExit('Markdown demo disclosure is missing')
if path.suffix == '.pdf' and not path.read_bytes().startswith(b'%PDF-'):
    raise SystemExit('PDF signature is invalid')
if path.suffix == '.docx' and not zipfile.is_zipfile(path):
    raise SystemExit('DOCX container is invalid')
'@

foreach ($artifact in $result.artifacts) {
    if ($artifact.owner_id -ne 'phase5-acceptance-user' -or
        $artifact.workflow_run_id -ne $runId -or
        $artifact.node_id -ne 'export_01' -or
        $artifact.file_size -le 0 -or
        $artifact.checksum_sha256.Length -ne 64) {
        throw "Artifact metadata is incomplete for $($artifact.file_name)."
    }
    & $docker compose exec -T ai-service python -c $artifactCheck `
        $artifact.path $artifact.checksum_sha256
    if ($LASTEXITCODE -ne 0) {
        throw "Artifact format validation failed for $($artifact.file_name)."
    }
}

$invalidGraph = @{
    run_id = "invalid-$runId"
    workflow = @{
        version = 1
        nodes = $graph.nodes
        edges = @(
            $graph.edges[0],
            $graph.edges[1],
            @{ id = 'edge_cycle'; source = 'export_01'; target = 'researcher_01' }
        )
    }
}
$invalid = Invoke-RestMethod `
    -Method Post `
    -Uri 'http://127.0.0.1:8001/internal/demo-executions' `
    -ContentType 'application/json' `
    -Body ($invalidGraph | ConvertTo-Json -Depth 20) `
    -TimeoutSec 15
$cycleError = $invalid.errors | Where-Object { $_.code -eq 'workflow_cycle' }
if ($invalid.status -ne 'failed' -or @($invalid.outputs.PSObject.Properties).Count -ne 0 -or -not $cycleError) {
    throw 'Invalid graph failure was not returned as a structured workflow error.'
}

Write-Host 'Deterministic Researcher, Writer, and Export execution produced verified Markdown, PDF, and DOCX artifacts.'
Write-Host 'Structured cycle failure and full containerized Phase 5 execution passed.'
Write-Host 'Phase 5 verification passed.'
