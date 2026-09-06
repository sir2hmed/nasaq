param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

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

& (Join-Path $PSScriptRoot 'verify-phase0.ps1')

$nodeHome = Join-Path $root '.tools\node-v24.18.0-win-x64'
$npm = if (Test-Path (Join-Path $nodeHome 'npm.cmd')) {
    $env:Path = "$nodeHome;$env:Path"
    Join-Path $nodeHome 'npm.cmd'
} else {
    (Get-Command npm -ErrorAction Stop).Source
}

$python = Join-Path $root 'ai-service-python\.venv\Scripts\python.exe'
if (-not (Test-Path $python)) {
    $python = (Get-Command python -ErrorAction Stop).Source
}

$php = Join-Path $root '.tools\php-8.5.8\php.exe'
if (-not (Test-Path $php)) {
    $php = (Get-Command php -ErrorAction Stop).Source
}

Invoke-Checked $npm @('run', 'lint') (Join-Path $root 'frontend')
Invoke-Checked $npm @('test') (Join-Path $root 'frontend')
Invoke-Checked $npm @('run', 'build') (Join-Path $root 'frontend')

Invoke-Checked $python @('-m', 'ruff', 'check', '.') (Join-Path $root 'ai-service-python')
Invoke-Checked $python @('-m', 'ruff', 'format', '--check', '.') (Join-Path $root 'ai-service-python')
$pytestTemp = Join-Path $root ".tmp\pytest-$([Guid]::NewGuid().ToString('N'))"
Invoke-Checked $python @('-m', 'pytest', '--basetemp', $pytestTemp) (Join-Path $root 'ai-service-python')

Invoke-Checked $php @('vendor\bin\pint', '--test') (Join-Path $root 'backend-laravel')
Invoke-Checked $php @('artisan', 'test') (Join-Path $root 'backend-laravel')

if (-not $SkipDocker) {
    $dockerCandidates = @(
        (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin\docker.exe'),
        (Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin\docker.exe')
    )
    $docker = $dockerCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $docker) {
        $docker = (Get-Command docker -ErrorAction Stop).Source
    }

    Invoke-Checked $docker @('compose', 'config', '--quiet') $root
    Invoke-Checked $docker @('compose', 'up', '--build', '--detach') $root

    $deadline = (Get-Date).AddMinutes(4)
    do {
        try {
            $frontend = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:5173/' -TimeoutSec 4
            $laravel = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/health' -TimeoutSec 4
            $fastApi = Invoke-RestMethod -Uri 'http://127.0.0.1:8001/health' -TimeoutSec 4
            $ready = $frontend.StatusCode -eq 200 -and
                $laravel.data.status -eq 'ok' -and
                $laravel.data.checks.database.ready -and
                $laravel.data.checks.redis.ready -and
                $fastApi.status -eq 'ok' -and
                $fastApi.checks.redis.ready
        }
        catch {
            $ready = $false
        }

        if (-not $ready) {
            Start-Sleep -Seconds 5
        }
    } while (-not $ready -and (Get-Date) -lt $deadline)

    if (-not $ready) {
        & $docker compose ps
        & $docker compose logs --tail 120
        throw 'Compose services did not become ready within four minutes.'
    }

    & $docker compose ps
    Write-Host 'Compose startup and PostgreSQL/Redis dependency checks passed.'
}

Write-Host 'Phase 1 verification passed.'
