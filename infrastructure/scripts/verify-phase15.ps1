param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$phase14Verifier = Join-Path $PSScriptRoot 'verify-phase14.ps1'

function Invoke-Checked {
    param(
        [Parameter(Mandatory)] [string]$FilePath,
        [Parameter(Mandatory)] [string[]]$Arguments,
        [Parameter(Mandatory)] [string]$WorkingDirectory
    )

    Push-Location $WorkingDirectory
    try {
        & $FilePath @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "$FilePath $($Arguments -join ' ') failed with exit code $LASTEXITCODE."
        }
    }
    finally {
        Pop-Location
    }
}

if ($SkipDocker) {
    & $phase14Verifier -SkipDocker
}
else {
    & $phase14Verifier
}

$required = @(
    '.env.production.example',
    'docker-compose.prod.yml',
    'infrastructure\nginx\nasaq.conf',
    'docs\deployment.md',
    'docs\architecture.md',
    'docs\api-contracts.md',
    'docs\final-demo.md',
    'docs\definition-of-done.md',
    'docs\screenshots\dashboard-desktop-en.jpg',
    'docs\screenshots\workflow-desktop-en.jpg',
    'docs\screenshots\workflow-mobile-ar.jpg',
    'docs\screenshots\outputs-mobile-ar.jpg',
    'README.md'
)
foreach ($relative in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $root $relative) -PathType Leaf)) {
        throw "Missing Phase 15 deliverable: $relative"
    }
}

$production = Get-Content -Raw -LiteralPath (Join-Path $root 'docker-compose.prod.yml')
foreach ($pattern in @(
    '${APP_KEY:?',
    '${POSTGRES_PASSWORD:?',
    '${REDIS_PASSWORD:?',
    '${AI_SERVICE_TOKEN:?',
    '${INTERNAL_CALLBACK_TOKEN:?',
    'internal: true',
    'no-new-privileges:true',
    'VITE_API_BASE_URL: /api'
)) {
    if (-not $production.Contains($pattern)) {
        throw "Production Compose is missing required evidence: $pattern"
    }
}
if ($production -match 'local-only-change-me|replace-with-a-long-random-token') {
    throw 'Production Compose contains a usable development secret default.'
}
if ([regex]::Matches($production, '(?m)^    ports:').Count -ne 1) {
    throw 'Exactly one production service (the gateway) must publish host ports.'
}

$documentationChecks = @(
    @{ Path = 'README.md'; Patterns = @('Clean local start', 'Architecture', 'Limitations', 'test@example.com') },
    @{ Path = 'docs\deployment.md'; Patterns = @('First deployment from a clean clone', 'Backups and restore', 'rollback', 'Honest production limitations') },
    @{ Path = 'docs\final-demo.md'; Patterns = @('Scenario A', 'Scenario B', 'Scenario C', 'Scenario D') },
    @{ Path = 'docs\api-contracts.md'; Patterns = @('X-Correlation-ID', 'status_counts', 'Limits and diagnostics') },
    @{ Path = 'docs\definition-of-done.md'; Patterns = @('| 30 |', 'No core dead button', 'Real integrations when configured') }
)
foreach ($check in $documentationChecks) {
    $content = Get-Content -Raw -LiteralPath (Join-Path $root $check.Path)
    foreach ($pattern in $check.Patterns) {
        if (-not $content.Contains($pattern)) {
            throw "$($check.Path) is missing '$pattern'."
        }
    }
}

foreach ($relative in $required | Where-Object { $_ -like '*.jpg' }) {
    $bytes = [IO.File]::ReadAllBytes((Join-Path $root $relative))
    if ($bytes.Length -lt 10000) {
        throw "$relative is not a complete product screenshot."
    }
    if ($bytes[0] -ne 255 -or $bytes[1] -ne 216 -or
        $bytes[$bytes.Length - 2] -ne 255 -or $bytes[$bytes.Length - 1] -ne 217) {
        throw "$relative does not have a valid JPEG signature."
    }
}

if (-not $SkipDocker) {
    $dockerCandidates = @(
        (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'),
        (Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe')
    )
    $docker = $dockerCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $docker) {
        $docker = (Get-Command docker -ErrorAction Stop).Source
    }

    $env:PUBLIC_URL = 'https://nasaq.example.test'
    $env:PUBLIC_HOST = 'nasaq.example.test'
    $env:APP_KEY = 'base64:fqPkTGMI1WcSTo1d9/pQcIHodhRPqp9FKWeSd+pHkQQ='
    $env:POSTGRES_PASSWORD = 'phase15-database-password'
    $env:REDIS_PASSWORD = 'phase15-url-safe-redis-password'
    $env:AI_SERVICE_TOKEN = 'phase15-ai-service-token-000000000000'
    $env:INTERNAL_CALLBACK_TOKEN = 'phase15-callback-token-111111111111'
    Invoke-Checked $docker @('compose', '-f', 'docker-compose.prod.yml', 'config', '--quiet') $root
    Write-Host 'Production Compose rendering passed with explicit non-default secrets.'
}

Write-Host 'Phase 15 packaging, documentation, screenshot, and Definition-of-Done evidence verification passed.'
if ($SkipDocker) {
    Write-Host 'Docker acceptance and production Compose rendering were skipped.'
}
