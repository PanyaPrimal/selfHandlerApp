# One-command local deploy for SelfHandler on this machine.
#
#   powershell -ExecutionPolicy Bypass -File _local-deploy\run.ps1
#
# Builds the app/web images, runs DB migrations once, and starts the stack.
# Re-run any time after pulling new code; it rebuilds and re-migrates safely.

$ErrorActionPreference = 'Stop'

$repoRoot   = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $PSScriptRoot 'compose.local.yaml'
$envFile     = Join-Path $PSScriptRoot '.env'

Push-Location $repoRoot
try {
    Write-Host 'Building images and starting SelfHandler (this can take a few minutes the first time)...' -ForegroundColor Cyan
    docker compose --env-file $envFile -f $composeFile up -d --build
    if ($LASTEXITCODE -ne 0) { throw 'docker compose up failed.' }

    Write-Host ''
    Write-Host 'Waiting for the web container to report healthy...' -ForegroundColor Cyan
    $port = (Select-String -Path $envFile -Pattern '^WEB_PORT=(.+)$').Matches.Groups[1].Value
    if (-not $port) { $port = '18080' }

    $ok = $false
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Seconds 3
        try {
            $r = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$port/api/health" -TimeoutSec 5
            if ($r.Content -match '"status":"ok"') { $ok = $true; break }
        } catch { }
    }

    Write-Host ''
    if ($ok) {
        Write-Host "SelfHandler is up. Open:  http://localhost:$port" -ForegroundColor Green
        Write-Host "From another device on your LAN: http://<this-PC-IP>:$port" -ForegroundColor Green
        Write-Host '(register a new account on first visit)' -ForegroundColor DarkGray
    } else {
        Write-Host 'Stack started but health check did not pass yet. Check logs:' -ForegroundColor Yellow
        Write-Host "  docker compose --env-file `"$envFile`" -f `"$composeFile`" logs -f" -ForegroundColor Yellow
    }
}
finally {
    Pop-Location
}
