param(
    [switch]$SkipDocker
)

$ErrorActionPreference = 'Stop'
$phase10Verifier = Join-Path $PSScriptRoot 'verify-phase10.ps1'

if ($SkipDocker) {
    & $phase10Verifier -SkipDocker
    Write-Host 'Phase 11 native integration verification passed. Docker acceptance was skipped.'
    exit 0
}

& $phase10Verifier

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
        Start-Sleep -Milliseconds 350
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
$password = 'PhaseElevenPass2026'
$registration = Invoke-JsonMutation -Session $session -Path '/api/auth/register' -Body @{
    name = 'Phase 11 Integration Owner'
    email = "phase11-$([Guid]::NewGuid().ToString('N'))@example.test"
    password = $password
    password_confirmation = $password
    preferred_locale = 'en'
}
$userId = $registration.data.user.id
$driveToken = "phase11-drive-$([Guid]::NewGuid().ToString('N'))"
$driveConnection = Invoke-JsonMutation `
    -Session $session `
    -Path '/api/integrations/google_drive/connect' `
    -Body @{
        credentials = @{
            access_token = $driveToken
            refresh_token = "phase11-refresh-$([Guid]::NewGuid().ToString('N'))"
            folder_id = 'phase11_folder'
        }
    }
$safeDriveJson = $driveConnection | ConvertTo-Json -Depth 12
if (-not $driveConnection.data.integration.connected -or
    $safeDriveJson.Contains($driveToken) -or
    $safeDriveJson -match 'phase11-refresh-') {
    throw 'The integration response did not connect safely or exposed a credential.'
}

$smtpConnection = Invoke-JsonMutation `
    -Session $session `
    -Path '/api/integrations/smtp/connect' `
    -Body @{
        credentials = @{
            host = 'smtp.example.test'
            port = 587
            username = 'phase11-mailer'
            password = "phase11-smtp-$([Guid]::NewGuid().ToString('N'))"
            from_email = 'noreply@example.test'
            from_name = 'Nasaq Phase 11'
            encryption = 'tls'
        }
    }
if (-not $smtpConnection.data.integration.connected -or
    $smtpConnection.data.integration.metadata.host -ne 'smtp.example.test') {
    throw 'The safe SMTP settings summary was not persisted.'
}

$connections = Invoke-RestMethod `
    -Uri "$laravelUrl/api/integrations" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
if (@($connections.data.integrations).Count -ne 3 -or
    @($connections.data.integrations | Where-Object { $_.connected }).Count -ne 2 -or
    ($connections | ConvertTo-Json -Depth 12).Contains($driveToken)) {
    throw 'Integration listing did not return three redacted owner-scoped provider states.'
}

$docker = (Get-Command docker -ErrorAction Stop).Source
$encryptedValue = & $docker compose exec -T postgres `
    psql -U nasaq -d nasaq -tAc `
    "SELECT encrypted_credentials FROM integration_connections WHERE user_id = $userId AND provider = 'google_drive';"
if ($LASTEXITCODE -ne 0 -or -not $encryptedValue -or $encryptedValue.Contains($driveToken)) {
    throw 'The live database did not contain an encrypted-at-rest integration payload.'
}

try {
    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl/api/internal/users/$userId/integrations/google_drive" `
        -Headers @{ Accept = 'application/json' } `
        -TimeoutSec 10 | Out-Null
    throw 'The internal credential endpoint accepted a request without its service token.'
}
catch {
    if ($_.Exception.Response.StatusCode.value__ -ne 401) {
        throw
    }
}

$graph = @{
    version = 1
    name = 'Phase 11 simulated distribution'
    description = 'Research, write, simulate Drive publication, and simulate email delivery'
    nodes = @(
        @{
            id = 'researcher_01'
            type = 'researcher'
            position = @{ x = 100; y = 180 }
            config = @{
                topic = 'Secure bilingual workflow integrations'
                source_count = 3
                language = 'en'
                search_depth = 'basic'
            }
        },
        @{
            id = 'writer_01'
            type = 'writer'
            position = @{ x = 380; y = 180 }
            config = @{
                style = 'professional'
                length = 'short'
                format = 'article'
                language = 'same_as_input'
            }
        },
        @{
            id = 'publisher_01'
            type = 'publisher'
            position = @{ x = 660; y = 180 }
            config = @{
                destination = 'google_drive'
                privacy_status = 'private'
            }
        },
        @{
            id = 'email_01'
            type = 'email'
            position = @{ x = 940; y = 180 }
            config = @{
                recipients = @('reviewer@example.test')
                subject = 'Nasaq Phase 11 demo result'
                body_template = "Your generated publication:`n{links}"
            }
        }
    )
    edges = @(
        @{ id = 'edge_research_writer'; source = 'researcher_01'; target = 'writer_01' },
        @{ id = 'edge_writer_publisher'; source = 'writer_01'; target = 'publisher_01' },
        @{ id = 'edge_publisher_email'; source = 'publisher_01'; target = 'email_01' }
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
    throw "The queued Phase 11 demo workflow failed: $($messages -join ' | ')"
}

$outputsResponse = Invoke-RestMethod `
    -Uri "$laravelUrl/api/runs/$runId/outputs" `
    -WebSession $session `
    -Headers $browserHeaders `
    -TimeoutSec 10
$outputs = @($outputsResponse.data.outputs)
$publication = $outputs | Where-Object {
    $_.node_key -eq 'publisher_01' -and $_.output_type -eq 'publication'
} | Select-Object -First 1
$delivery = $outputs | Where-Object {
    $_.node_key -eq 'email_01' -and $_.output_type -eq 'email_delivery'
} | Select-Object -First 1
$preview = $outputs | Where-Object {
    $_.node_key -eq 'email_01' -and $_.mime_type -eq 'message/rfc822'
} | Select-Object -First 1
if (-not $publication -or -not $delivery -or -not $preview -or
    $publication.content.simulated -ne $true -or
    $publication.public_url -notmatch '^https://drive\.example\.invalid/' -or
    $delivery.content.simulated -ne $true -or
    $delivery.content.recipients[0] -ne 'reviewer@example.test' -or
    -not $preview.download_url) {
    throw 'The demo run did not persist its clearly simulated publication, delivery, and preview.'
}

$idempotencyKeys = @(& $docker compose exec -T redis `
    redis-cli --scan --pattern "nasaq:side-effect:${runId}:*:result")
if ($LASTEXITCODE -ne 0 -or $idempotencyKeys.Count -ne 2) {
    throw 'Redis does not contain both completed run/node side-effect idempotency records.'
}

$downloadDirectory = Join-Path $root '.tmp'
New-Item -ItemType Directory -Force -Path $downloadDirectory | Out-Null
$previewPath = Join-Path $downloadDirectory "phase11-$runId.eml"
try {
    Invoke-WebRequest `
        -UseBasicParsing `
        -Uri "$laravelUrl$($preview.download_url)" `
        -WebSession $session `
        -Headers $browserHeaders `
        -OutFile $previewPath `
        -TimeoutSec 30
    $previewText = [System.IO.File]::ReadAllText($previewPath)
    if ($previewText -notmatch 'reviewer@example\.test' -or
        $previewText -notmatch 'drive\.example\.invalid' -or
        $previewText.Contains($driveToken)) {
        throw 'The owner-authorized email preview is missing its simulated link or contains a secret.'
    }
}
finally {
    Remove-Item -LiteralPath $previewPath -Force -ErrorAction SilentlyContinue
}

Write-Host 'Encrypted owner-scoped Drive and SMTP settings were saved and returned only as redacted state.'
Write-Host 'The worker-only credential boundary rejected an unauthenticated request.'
Write-Host 'Celery completed the credential-free demo Publisher and Email path with a downloadable preview.'
Write-Host 'Redis contains one completed idempotency record for each external side-effect node.'
Write-Host 'Phase 11 verification passed.'
