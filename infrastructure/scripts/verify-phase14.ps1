param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$phase13Verifier = Join-Path $PSScriptRoot 'verify-phase13.ps1'

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
    & $phase13Verifier -SkipDocker
}
else {
    & $phase13Verifier
}

$nodeHome = Join-Path $root '.tools\node-v24.18.0-win-x64'
$npm = if (Test-Path (Join-Path $nodeHome 'npm.cmd')) {
    $env:Path = "$nodeHome;$env:Path"
    Join-Path $nodeHome 'npm.cmd'
}
else {
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

$composer = Join-Path $root '.tools\downloads\composer.phar'
if (-not (Test-Path $composer)) {
    throw 'Composer is required for the clean-install verification.'
}

Invoke-Checked $npm @('ci', '--dry-run', '--ignore-scripts', '--offline') (Join-Path $root 'frontend')
Invoke-Checked $python @(
    '-m', 'pip', 'install', '--dry-run', '--no-index',
    '-r', 'requirements.txt', '-r', 'requirements-dev.txt'
) (Join-Path $root 'ai-service-python')
Invoke-Checked $python @('-m', 'pip', 'check') (Join-Path $root 'ai-service-python')
Invoke-Checked $php @($composer, 'validate', '--strict', '--no-interaction') (Join-Path $root 'backend-laravel')

$previousComposerNetwork = $env:COMPOSER_DISABLE_NETWORK
$env:COMPOSER_DISABLE_NETWORK = '1'
try {
    Invoke-Checked $php @(
        $composer, 'install', '--dry-run', '--no-interaction', '--no-progress', '--prefer-dist'
    ) (Join-Path $root 'backend-laravel')
}
finally {
    $env:COMPOSER_DISABLE_NETWORK = $previousComposerNetwork
}

$tempRoot = Join-Path $root '.tmp'
New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
$cleanDb = Join-Path $tempRoot "phase14-clean-$([Guid]::NewGuid().ToString('N')).sqlite"
New-Item -ItemType File -Path $cleanDb | Out-Null

try {
    $env:APP_ENV = 'testing'
    $env:APP_KEY = 'base64:fqPkTGMI1WcSTo1d9/pQcIHodhRPqp9FKWeSd+pHkQQ='
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $cleanDb
    $env:CACHE_STORE = 'array'
    $env:SESSION_DRIVER = 'array'
    $env:QUEUE_CONNECTION = 'sync'
    $env:HEALTH_CHECK_DEPENDENCIES = 'false'

    $backend = Join-Path $root 'backend-laravel'
    Invoke-Checked $php @('artisan', 'migrate', '--force') $backend
    Invoke-Checked $php @('artisan', 'db:seed', '--class=SampleWorkflowSeeder', '--force') $backend
    Invoke-Checked $php @('artisan', 'migrate:status') $backend

    $autoload = (Join-Path $backend 'vendor\autoload.php').Replace('\', '/')
    $bootstrap = (Join-Path $backend 'bootstrap\app.php').Replace('\', '/')
    $probe = @"
require '$autoload';
`$app = require '$bootstrap';
`$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
`$user = App\Models\User::where('email', 'test@example.com')->first();
`$valid = `$user !== null
    && `$user->workflows()->count() === 5
    && Illuminate\Support\Facades\Hash::check('NasaqDemo2026', `$user->password);
if (! `$valid) {
    fwrite(STDERR, 'Clean database sample verification failed.'.PHP_EOL);
    exit(1);
}
echo 'Clean database sample verification passed.'.PHP_EOL;
"@
    Invoke-Checked $php @('-r', $probe) $root
}
finally {
    $resolvedTempRoot = [IO.Path]::GetFullPath($tempRoot).TrimEnd('\') + '\'
    $resolvedDb = [IO.Path]::GetFullPath($cleanDb)
    if (-not $resolvedDb.StartsWith($resolvedTempRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to remove an unexpected clean-database path: $resolvedDb"
    }
    if (Test-Path -LiteralPath $resolvedDb -PathType Leaf) {
        Remove-Item -LiteralPath $resolvedDb -Force
    }
}

Write-Host 'Phase 14 automated, dependency-resolution, and clean-database verification passed.'
if ($SkipDocker) {
    Write-Host 'Docker acceptance was skipped; browser acceptance is recorded separately.'
}
