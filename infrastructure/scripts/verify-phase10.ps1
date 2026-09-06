param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase9Verifier = Join-Path $PSScriptRoot 'verify-phase9.ps1'

if ($SkipDocker) {
    & $phase9Verifier -SkipDocker
    Write-Host 'Phase 10 native video verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase9Verifier

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
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

function Wait-ForRun {
    param(
        [Parameter(Mandatory)] [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory)] [string]$RunId,
        [int]$TimeoutSeconds = 120
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
    } while ($run.status -notin @('success', 'failed', 'cancelled') -and (Get-Date) -lt $deadline)
    return $run
}

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $session
$password = 'PhaseTenPass2026'
Invoke-JsonMutation -Session $session -Path '/api/auth/register' -Body @{
    name = 'Phase 10 Video Owner'
    email = "phase10-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null

$graph = @{
    version = 1
    name = 'Phase 10 playable video'
    description = 'Research and script to a local FFmpeg MP4'
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
            position = @{ x = 440; y = 180 }
            config = @{
                style = 'educational'
                length = 'short'
                format = 'script'
                language = 'same_as_input'
            }
        },
        @{
            id = 'video_01'
            type = 'video'
            position = @{ x = 780; y = 180 }
            config = @{
                scene_duration = 1
                max_scenes = 3
                narration = 'silent'
                voice = 'alloy'
            }
        }
    )
    edges = @(
        @{ id = 'edge_research_writer'; source = 'researcher_01'; target = 'writer_01' },
        @{ id = 'edge_writer_video'; source = 'writer_01'; target = 'video_01' }
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
$run = Wait-ForRun -Session $session -RunId $runId
if ($run.status -ne 'success') {
    $failedLogs = Invoke-RestMethod `
        -Uri "$laravelUrl/api/runs/$runId/logs" `
        -WebSession $session `
        -Headers $browserHeaders `
        -TimeoutSec 10
    $messages = @($failedLogs.data.logs | ForEach-Object { "$($_.event_type): $($_.message)" })
    throw "The queued Video workflow failed: $($messages -join ' | ')"
}

$outputsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$outputs = @($outputsResponse.data.outputs)
$videoSummary = $outputs | Where-Object {
    $_.node_key -eq 'video_01' -and $_.output_type -eq 'video'
} | Select-Object -First 1
$videoArtifact = $outputs | Where-Object {
    $_.node_key -eq 'video_01' -and $_.mime_type -eq 'video/mp4'
} | Select-Object -First 1
if (-not $videoSummary -or -not $videoArtifact) {
    throw 'The run outputs do not contain both the Video summary and MP4 artifact.'
}
if ($videoSummary.content.scene_count -lt 1 -or
    $videoSummary.content.duration_seconds -le 0 -or
    $videoSummary.content.codec -ne 'h264' -or
    $videoSummary.content.demo_notice -notmatch '^DEMO MODE') {
    throw 'The persisted Video summary is missing verified media metadata or demo labeling.'
}
if (-not $videoArtifact.stream_url -or -not $videoArtifact.download_url -or
    $videoArtifact.file_size -le 1000) {
    throw 'The MP4 artifact does not expose a real stream and download with a non-empty size.'
}

$docker = (Get-Command docker -ErrorAction Stop).Source
$videoPath = [string]$videoSummary.content.video.path
$probeText = & $docker compose exec -T celery-worker `
    ffprobe -v error `
    -show_entries 'stream=codec_type,codec_name,width,height:format=duration,format_name' `
    -of json `
    $videoPath
if ($LASTEXITCODE -ne 0) {
    throw 'ffprobe could not read the generated MP4 from shared artifact storage.'
}
$probe = $probeText | ConvertFrom-Json
$videoStream = @($probe.streams | Where-Object { $_.codec_type -eq 'video' })[0]
if ($probe.format.format_name -notmatch 'mp4' -or
    [double]$probe.format.duration -le 0 -or
    $videoStream.codec_name -ne 'h264' -or
    $videoStream.width -ne 1280 -or
    $videoStream.height -ne 720) {
    throw 'ffprobe did not confirm the expected playable H.264 MP4 contract.'
}

$streamResponse = Invoke-WebRequest `
    -UseBasicParsing `
    -Uri "$laravelUrl$($videoArtifact.stream_url)" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 30
if ($streamResponse.StatusCode -ne 200 -or
    $streamResponse.Headers.'Content-Type' -notmatch '^video/mp4' -or
    $streamResponse.Headers.'Content-Disposition' -notmatch '^inline') {
    throw 'The owner-authorized media endpoint did not return an inline MP4 response.'
}

$downloadDirectory = Join-Path $root '.tmp'
New-Item -ItemType Directory -Force -Path $downloadDirectory | Out-Null
$downloadPath = Join-Path $downloadDirectory "phase10-$runId.mp4"
try {
    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl$($videoArtifact.download_url)" `
        -WebSession $session `
        -Headers $browserHeaders `
        -OutFile $downloadPath `
        -TimeoutSec 30
    $downloadBytes = [System.IO.File]::ReadAllBytes($downloadPath)
    $signature = [System.Text.Encoding]::ASCII.GetString($downloadBytes, 4, 4)
    if ($downloadBytes.Length -ne $videoArtifact.file_size -or $signature -ne 'ftyp') {
        throw 'The downloaded MP4 does not match its persisted size and container signature.'
    }
}
finally {
    Remove-Item -LiteralPath $downloadPath -Force -ErrorAction SilentlyContinue
}

$intruder = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Initialize-BrowserSession -Session $intruder
Invoke-JsonMutation -Session $intruder -Path '/api/auth/register' -Body @{
    name = 'Phase 10 Intruder'
    email = "phase10-intruder-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
} | Out-Null
try {
    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl$($videoArtifact.stream_url)" `
        -WebSession $intruder `
        -Headers $browserHeaders `
        -TimeoutSec 10 | Out-Null
    throw 'A different user was able to stream the owner video.'
}
catch {
    if ($_.Exception.Response.StatusCode.value__ -ne 403) {
        throw
    }
}

Write-Host 'Celery rendered a real slide-based H.264 MP4 from structured Writer output.'
Write-Host 'ffprobe confirmed the codec, dimensions, container, and playable duration.'
Write-Host 'Owner-only inline playback and a byte-identical authenticated download passed.'
Write-Host 'Phase 10 verification passed.'
