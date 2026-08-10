# Copy the PRODUCTION database into the local DEV MySQL.
#
#   powershell -ExecutionPolicy Bypass -File _local-deploy\dump-prod-to-dev.ps1
#
# - Dumps the production DB from its container (no host port needed).
# - REPLACES the dev database contents with that snapshot.
# - Both run MySQL 8.4 with the same schema, so the dump loads directly.
#
# The dev DB is the standalone container from compose.devdb.yaml (port 13306).
# Production is never modified.

[CmdletBinding()]
param(
    [switch]$KeepDumpFile
)

$ErrorActionPreference = 'Stop'

$scriptDir   = $PSScriptRoot
$repoRoot    = Split-Path -Parent $scriptDir
$prodCompose = Join-Path $scriptDir 'compose.local.yaml'
$prodEnv     = Join-Path $scriptDir '.env.funnel'
$devCompose  = Join-Path $scriptDir 'compose.devdb.yaml'
$devEnv      = Join-Path $scriptDir '.env.devdb'

$prodDb   = 'selfhandler'
$prodUser = 'root'

$devUser = 'root'
$devDb   = 'selfhandler'

# Read secrets from the git-ignored env files instead of hardcoding them.
function Get-EnvValue {
    param([string]$File, [string]$Key)
    if (-not (Test-Path $File)) { throw "Missing env file: $File" }
    $line = Get-Content $File | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -First 1
    if (-not $line) { throw "$Key not found in $File" }
    return ($line -replace "^\s*$Key\s*=\s*", '').Trim().Trim('"')
}

$prodPass = Get-EnvValue $prodEnv 'DB_ROOT_PASSWORD'
$devPass  = Get-EnvValue $devEnv  'MYSQL_ROOT_PASSWORD'

$dumpFile = Join-Path $scriptDir 'prod-snapshot.sql'

Push-Location $repoRoot
try {
    Write-Host 'Checking dev MySQL is running...' -ForegroundColor Cyan
    docker compose --env-file $devEnv -f $devCompose up -d | Out-Null

    Write-Host 'Dumping production database from its container...' -ForegroundColor Cyan
    # --single-transaction: consistent snapshot without locking the live DB.
    $dumpCmd = "mysqldump --single-transaction --no-tablespaces -u$prodUser -p'$prodPass' $prodDb"
    docker compose --env-file $prodEnv -f $prodCompose exec -T db sh -c $dumpCmd |
        Out-File -FilePath $dumpFile -Encoding utf8
    if ($LASTEXITCODE -ne 0) { throw 'Production dump failed.' }

    $size = (Get-Item $dumpFile).Length
    if ($size -lt 100) { throw "Dump looks empty ($size bytes)." }
    Write-Host ("Dump captured: {0:N0} bytes" -f $size) -ForegroundColor Green

    Write-Host 'Loading snapshot into dev MySQL (replaces current dev data)...' -ForegroundColor Cyan
    # Recreate the dev schema clean by piping the SQL through stdin (avoids
    # fragile nested quoting through PowerShell -> docker -> sh).
    $reset = "DROP DATABASE IF EXISTS ``$devDb``; CREATE DATABASE ``$devDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
    $reset | docker compose --env-file $devEnv -f $devCompose exec -T devdb mysql "-u$devUser" "-p$devPass"
    if ($LASTEXITCODE -ne 0) { throw 'Dev database reset failed.' }

    Get-Content $dumpFile -Raw |
        docker compose --env-file $devEnv -f $devCompose exec -T devdb mysql "-u$devUser" "-p$devPass" $devDb
    if ($LASTEXITCODE -ne 0) { throw 'Loading the snapshot into dev failed.' }

    Write-Host ''
    Write-Host 'Done. Dev now mirrors production. Your prod account and its data are available locally.' -ForegroundColor Green
    Write-Host 'Run the dev app against 127.0.0.1:13306 as usual.' -ForegroundColor DarkGray
}
finally {
    if (-not $KeepDumpFile -and (Test-Path $dumpFile)) {
        Remove-Item $dumpFile -Force
    }
    Pop-Location
}
