$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$required = @(
    'README.md',
    'PROJECT_STATUS.md',
    'CHANGELOG.md',
    'REQUIREMENTS_MATRIX.md',
    'LICENSE',
    '.gitignore',
    '.env.example',
    'docker-compose.yml',
    'docker-compose.prod.yml',
    'sample_workflow.json',
    'docs\architecture.md',
    'docs\api-contracts.md',
    'docs\agent-contracts.md',
    'docs\bilingual-ui.md',
    'docs\deployment.md',
    'docs\naming-conventions.md',
    'docs\testing.md',
    'docs\schemas\workflow.schema.json',
    'frontend\Dockerfile',
    'frontend\.env.example',
    'backend-laravel\Dockerfile',
    'backend-laravel\.env.example',
    'backend-laravel\public\index.php',
    'ai-service-python\Dockerfile',
    'ai-service-python\.env.example',
    'ai-service-python\app\main.py'
)

$missing = @()
foreach ($relative in $required) {
    $path = Join-Path $root $relative
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        $missing += $relative
    }
}

if ($missing.Count -gt 0) {
    throw "Missing Phase 0 files: $($missing -join ', ')"
}

$sample = Get-Content -Raw -LiteralPath (Join-Path $root 'sample_workflow.json') | ConvertFrom-Json
$schema = Get-Content -Raw -LiteralPath (Join-Path $root 'docs\schemas\workflow.schema.json') | ConvertFrom-Json

if ($sample.version -ne 1 -or $sample.nodes.Count -lt 1 -or $sample.edges.Count -lt 1) {
    throw 'Sample workflow does not contain a valid versioned graph shape.'
}

$nodeIds = @($sample.nodes | ForEach-Object { $_.id })
if (($nodeIds | Select-Object -Unique).Count -ne $nodeIds.Count) {
    throw 'Sample workflow contains duplicate node IDs.'
}

foreach ($edge in $sample.edges) {
    if ($edge.source -notin $nodeIds -or $edge.target -notin $nodeIds) {
        throw "Edge $($edge.id) references a missing node."
    }
}

if ($schema.'$schema' -ne 'https://json-schema.org/draft/2020-12/schema') {
    throw 'Workflow schema is not JSON Schema draft 2020-12.'
}

$matrix = Get-Content -Raw -LiteralPath (Join-Path $root 'REQUIREMENTS_MATRIX.md')
foreach ($id in 'FR-01', 'FR-02', 'FR-03', 'FR-04', 'FR-05', 'FR-06', 'NFR-01', 'NFR-02', 'NFR-03', 'NFR-04') {
    if ($matrix -notmatch [regex]::Escape($id)) {
        throw "Requirements matrix is missing $id."
    }
}

Write-Host 'Phase 0 structural verification passed.'
Write-Host "Verified $($required.Count) required files, workflow graph references, schema draft, and report FR/NFR traceability."
