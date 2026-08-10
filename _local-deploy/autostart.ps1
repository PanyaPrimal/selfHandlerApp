# Autostart helper for SelfHandler on this mini-PC.
#
# Run at logon by a Scheduled Task. Waits for the Docker engine to be ready,
# then brings the Funnel (public) stack up. Containers use restart:unless-stopped,
# so this is mainly a safety net for a cold boot where the engine starts late.
#
# Tailscale Funnel is a Windows service and restores its own config on boot,
# so it is NOT re-applied here.

$ErrorActionPreference = 'Stop'

$repoRoot    = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $PSScriptRoot 'compose.local.yaml'
$envFile     = Join-Path $PSScriptRoot '.env.funnel'
$logFile     = Join-Path $PSScriptRoot 'autostart.log'

function Write-Log($msg) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    Add-Content -Path $logFile -Value $line
}

Write-Log 'Autostart triggered.'

# Wait up to ~5 minutes for the Docker engine to answer.
$ready = $false
for ($i = 0; $i -lt 60; $i++) {
    try {
        docker info *> $null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    } catch { }
    Start-Sleep -Seconds 5
}

if (-not $ready) {
    Write-Log 'Docker engine did not become ready in time; giving up.'
    exit 1
}

Write-Log 'Docker engine ready. Bringing the stack up.'
Push-Location $repoRoot
try {
    # docker compose writes progress to stderr; capture everything as plain text
    # so PowerShell does not turn it into error records.
    $output = & cmd /c "docker compose --env-file `"$envFile`" -f `"$composeFile`" up -d 2>&1"
    foreach ($line in @($output)) { Write-Log ([string]$line) }
    Write-Log ("Stack up command finished (exit {0})." -f $LASTEXITCODE)
}
finally {
    Pop-Location
}

# Tailscale (a Windows service) normally restores Funnel on boot. As a safety
# net, re-assert the public Funnel route if it is not already advertised.
try {
    $funnel = & tailscale funnel status 2>&1 | Out-String
    if ($funnel -notmatch 'Funnel on') {
        Write-Log 'Funnel not active; re-applying public route.'
        & tailscale funnel --bg --https=443 http://127.0.0.1:18080 2>&1 |
            ForEach-Object { Write-Log ([string]$_) }
    } else {
        Write-Log 'Funnel already active.'
    }
}
catch {
    Write-Log ("Funnel check failed: {0}" -f $_.Exception.Message)
}
