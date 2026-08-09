[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet("Drill", "Production", "BootstrapReset")]
    [string]$RecoveryMode,

    [Parameter(Mandatory = $true)]
    [string]$BundlePath,

    [Parameter(Mandatory = $true)]
    [string]$IdentityPath,

    [string]$DisposableProject,

    [ValidateSet("selfhandler-production")]
    [string]$Target,

    [ValidateSet("selfhandler-production")]
    [string]$ConfirmTarget,

    [string]$SafetyBackupReference,

    [ValidateSet("RESET selfhandler-production TO EMPTY BOOTSTRAP BASELINE")]
    [string]$ConfirmBootstrapReset,

    [ValidateSet("RESTORE selfhandler-production BACKUP OLDER THAN 24 HOURS")]
    [string]$ConfirmStaleBackup,

    [switch]$OriginalHostUnavailable
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "shared.ps1")

$script:SelfHandlerMySqlImage = "mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb"

function Assert-DisposableRestoreProject {
    param([Parameter(Mandatory = $true)][string]$Project)

    if ($Project -notmatch '^selfhandler-drill-[a-f0-9]{8,32}$') {
        throw "Disposable restore project identity is invalid."
    }
    if ($Project -in @("selfhandler", "dealflow") -or $Project.StartsWith("dealflow", [StringComparison]::OrdinalIgnoreCase)) {
        throw "Production projects are forbidden restore-drill targets."
    }
    foreach ($productionVolume in @($script:SelfHandlerDatabaseVolume, $script:SelfHandlerPrivateFilesVolume, "dealflow_mysql_data", "dealflow_media_data")) {
        if ("${Project}_mysql_data" -eq $productionVolume -or "${Project}_private_files" -eq $productionVolume) {
            throw "Disposable restore storage conflicts with production."
        }
    }
}

function New-RandomSecretText {
    param([ValidateRange(24, 128)][int]$Bytes = 48)

    $buffer = New-Object byte[] $Bytes
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($buffer)
    }
    finally {
        $generator.Dispose()
    }
    return [Convert]::ToBase64String($buffer).Replace("+", "-").Replace("/", "_").TrimEnd("=")
}

function Get-FreeLoopbackPort {
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try {
        return ([Net.IPEndPoint]$listener.LocalEndpoint).Port
    }
    finally {
        $listener.Stop()
    }
}

function Invoke-DisposableCompose {
    param(
        [Parameter(Mandatory = $true)][string]$Project,
        [Parameter(Mandatory = $true)][string]$EnvironmentPath,
        [Parameter(Mandatory = $true)][string]$OverridePath,
        [Parameter(Mandatory = $true, ValueFromRemainingArguments = $true)][string[]]$Arguments
    )

    Assert-DisposableRestoreProject -Project $Project
    & docker compose -p $Project --env-file $EnvironmentPath -f $script:SelfHandlerComposePath -f $OverridePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Disposable restore Compose operation failed."
    }
}

function Get-ProjectServiceContainer {
    param(
        [Parameter(Mandatory = $true)][string]$Project,
        [Parameter(Mandatory = $true)][ValidateSet("web", "app", "db")][string]$Service
    )

    $containers = @(& docker ps --filter "label=com.docker.compose.project=$Project" --filter "label=com.docker.compose.service=$Service" --format "{{.ID}}")
    if ($LASTEXITCODE -ne 0 -or $containers.Count -ne 1) {
        throw "Disposable restore service '$Service' is unavailable."
    }
    return $containers[0].Trim()
}

function Wait-DatabaseContainer {
    param([Parameter(Mandatory = $true)][string]$Container)

    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $status = (& docker inspect --format "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}" $Container).Trim()
        if ($LASTEXITCODE -eq 0 -and $status -eq "healthy") {
            return
        }
        Start-Sleep -Seconds 2
    }
    throw "Restored database did not become healthy."
}

function Restore-DatabasePayload {
    param(
        [Parameter(Mandatory = $true)][string]$Container,
        [Parameter(Mandatory = $true)][string]$DatabasePath,
        [switch]$ReplaceExisting
    )

    $importErrorPath = Join-Path (Split-Path -Parent ([IO.Path]::GetFullPath($DatabasePath))) ("database-import-{0}.error" -f [Guid]::NewGuid().ToString("N"))
    try {
        if ($ReplaceExisting) {
            & docker exec $Container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mysql -uroot -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"' *> $null
            if ($LASTEXITCODE -ne 0) {
                throw "Confirmed production database replacement failed."
            }
        }
        # Stream exact bytes over docker exec stdin. This avoids the database
        # container's bounded /tmp and keeps SQL diagnostics (which may quote
        # protected row values) in a sensitive file that is never logged.
        $dockerPath = (Get-Command docker -ErrorAction Stop).Source
        $importScript = 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot "$MYSQL_DATABASE"'
        $importExit = Invoke-NativeProcessRedirected `
            -FilePath $dockerPath `
            -Arguments @("exec", "-i", $Container, "sh", "-c", $importScript) `
            -StandardInputPath $DatabasePath `
            -StandardErrorPath $importErrorPath
        if ($importExit -ne 0) {
            throw "Validated database payload import failed."
        }
    }
    finally {
        if ([IO.File]::Exists($importErrorPath)) { [IO.File]::Delete($importErrorPath) }
    }
}

function Restore-PrivateFilesPayload {
    param(
        [Parameter(Mandatory = $true)][string]$Project,
        [Parameter(Mandatory = $true)][string]$Volume,
        [Parameter(Mandatory = $true)][string]$ApplicationImage,
        [Parameter(Mandatory = $true)][string]$ArchivePath,
        [switch]$ReplaceExisting
    )

    $helper = "$Project-private-restore-$([Guid]::NewGuid().ToString('N').Substring(0, 8))"
    $importErrorPath = Join-Path (Split-Path -Parent ([IO.Path]::GetFullPath($ArchivePath))) ("private-import-{0}.error" -f [Guid]::NewGuid().ToString("N"))
    $controlledCount = $null
    try {
        # A fresh Docker volume root is owned by root. Use a short-lived root
        # helper with only the capabilities required to replace/chown the fixed
        # volume; keep its rootfs read-only and network disabled.
        & docker create --name $helper --user "0:0" --read-only --cap-drop ALL --cap-add CHOWN --cap-add DAC_OVERRIDE --cap-add FOWNER --security-opt "no-new-privileges:true" --pids-limit 64 --memory 128m --cpus 0.25 --network none --entrypoint /bin/sh --mount "type=volume,source=$Volume,target=/target" $ApplicationImage -c 'sleep 300' *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "Private-file restore helper could not be created."
        }
        & docker start $helper *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "Private-file restore helper could not be started."
        }
        if ($ReplaceExisting) {
            & docker exec $helper sh -c 'find /target -mindepth 1 -delete' *> $null
            if ($LASTEXITCODE -ne 0) {
                throw "Confirmed private-file replacement failed."
            }
        } else {
            & docker exec $helper sh -c 'test -z "$(find /target -mindepth 1 -print -quit)"' *> $null
            if ($LASTEXITCODE -ne 0) {
                throw "Disposable private-file volume is not clean."
            }
        }
        # Stream exact archive bytes over stdin: recovery must not depend on a
        # bounded helper /tmp. Tar diagnostics can contain protected paths, so
        # capture them in the sensitive extraction root and never log them.
        $dockerPath = (Get-Command docker -ErrorAction Stop).Source
        $extractScript = 'set -eu; tar -C /target -xf -; chown -R 82:82 /target; chmod 0750 /target'
        $extractExit = Invoke-NativeProcessRedirected `
            -FilePath $dockerPath `
            -Arguments @("exec", "-i", $helper, "sh", "-c", $extractScript) `
            -StandardInputPath $ArchivePath `
            -StandardErrorPath $importErrorPath
        if ($extractExit -ne 0) {
            throw "Validated private-file payload import failed."
        }
        $controlledCountText = [string](& docker exec $helper sh -c 'find /target -type f -print | wc -l')
        $controlledCountText = $controlledCountText.Trim()
        if ($LASTEXITCODE -ne 0 -or $controlledCountText -notmatch '^[0-9]+$') {
            throw "Restored private-file controlled count could not be measured."
        }
        $controlledCount = [int64]$controlledCountText
    }
    finally {
        & docker rm --force $helper *> $null
        if ([IO.File]::Exists($importErrorPath)) { [IO.File]::Delete($importErrorPath) }
    }
    return $controlledCount
}

function Get-RestoredSchemaFingerprint {
    param([Parameter(Mandatory = $true)][string]$Container)

    $rows = @(& docker exec $Container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT migration, batch FROM migrations ORDER BY migration;"' 2>$null)
    if ($LASTEXITCODE -ne 0) {
        return $script:SelfHandlerEmptySchemaFingerprint
    }
    return Get-Sha256Text -Text (($rows | ForEach-Object { $_.TrimEnd() }) -join "`n")
}

function Get-RestoredDatabaseControlledCount {
    param(
        [Parameter(Mandatory = $true)][string]$Container,
        [switch]$AllowMissingUsersTable
    )

    $countText = [string](& docker exec $Container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM users;"' 2>$null)
    $countText = $countText.Trim()
    if ($LASTEXITCODE -ne 0) {
        if ($AllowMissingUsersTable) {
            return [int64]0
        }
        throw "Restored database controlled count could not be measured."
    }
    if ($countText -notmatch '^[0-9]+$') {
        throw "Restored database controlled count is invalid."
    }
    return [int64]$countText
}

function Assert-RestoredDatabaseSchemaEmpty {
    param([Parameter(Mandatory = $true)][string]$Container)

    $countText = [string](& docker exec $Container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"')
    $countText = $countText.Trim()
    if ($LASTEXITCODE -ne 0 -or $countText -notmatch '^[0-9]+$' -or [int64]$countText -ne 0) {
        throw "Bootstrap baseline did not restore an empty database schema."
    }
}

function Assert-RestoredControlledCounts {
    param(
        [Parameter(Mandatory = $true)][string]$DatabaseContainer,
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [Parameter(Mandatory = $true)][int64]$PrivateFileCount
    )

    $allowMissingUsers = $null -eq $Manifest.source_release
    $databaseCount = Get-RestoredDatabaseControlledCount -Container $DatabaseContainer -AllowMissingUsersTable:$allowMissingUsers
    if ($databaseCount -ne [int64]$Manifest.database.controlled_count) {
        throw "Restored database controlled count does not match the authenticated manifest."
    }
    if ($PrivateFileCount -ne [int64]$Manifest.private_files.controlled_count) {
        throw "Restored private-file controlled count does not match the authenticated manifest."
    }
}

function Assert-LocalRecoveryImage {
    param(
        [Parameter(Mandatory = $true)][string]$Reference,
        [Parameter(Mandatory = $true)][string]$ExpectedRevision
    )

    $raw = & docker image inspect $Reference
    if ($LASTEXITCODE -ne 0) {
        throw "Authenticated recovery source image is not available locally."
    }
    $inspection = @($raw | ConvertFrom-Json)[0]
    $observedRevision = $null
    if ($inspection.Config.Labels) {
        $observedRevision = [string]$inspection.Config.Labels.'org.opencontainers.image.revision'
    }
    if ($observedRevision -ne $ExpectedRevision) {
        throw "Authenticated recovery source image revision does not match."
    }
}

function Get-ValidatedPreRestoreSafetyBackup {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$Reference)

    $active = Get-ActiveRelease
    if ($null -eq $active) {
        throw "A pre-restore safety backup requires a current active release."
    }
    $evidence = Get-LatestPendingBackup -Reason "pre-restore"
    $ciphertextPath = [string]$evidence.ciphertext_path
    if (-not (Test-Path -LiteralPath $ciphertextPath -PathType Leaf)) {
        throw "The pre-restore safety backup ciphertext is unavailable."
    }
    $ciphertext = Get-Item -LiteralPath $ciphertextPath
    $ciphertextHash = (Get-FileHash -LiteralPath $ciphertextPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ([int64]$ciphertext.Length -ne [int64]$evidence.ciphertext_bytes -or $ciphertextHash -ne [string]$evidence.ciphertext_sha256) {
        throw "The pre-restore safety backup ciphertext does not match its evidence."
    }
    if ($null -eq $evidence.source_release -or
        [string]$evidence.source_release.source_revision -ne [string]$active.source_revision -or
        [string]$evidence.source_release.web_digest -ne [string]$active.web_digest -or
        [string]$evidence.source_release.app_digest -ne [string]$active.app_digest) {
        throw "The pre-restore safety backup does not match the active release."
    }
    return Bind-OffHostBackupReference -Evidence $evidence -Reference $Reference
}

function Assert-BootstrapResetHistoryEligible {
    [CmdletBinding()]
    param()

    if ($null -ne (Get-ActiveRelease)) {
        throw "Bootstrap reset is forbidden after an active release exists."
    }
    $records = @()
    $recordsRoot = Join-Path $script:SelfHandlerStateRoot "releases"
    if (Test-Path -LiteralPath $recordsRoot -PathType Container) {
        foreach ($file in @(Get-ChildItem -LiteralPath $recordsRoot -File -Filter "*.json" | Sort-Object Name)) {
            try {
                $record = Read-JsonFile -Path $file.FullName
                if ($record.deployment_id -ne $script:SelfHandlerDeploymentId -or
                    [string]$record.source_revision -notmatch '^[0-9a-f]{40}$' -or
                    [string]$record.web_digest -notmatch '^sha256:[0-9a-f]{64}$' -or
                    [string]$record.app_digest -notmatch '^sha256:[0-9a-f]{64}$' -or
                    [string]$record.outcome -notin @("succeeded", "rejected", "failed_before_replace", "rolled_back", "recovery_required")) {
                    throw "invalid"
                }
                $records += $record
            }
            catch {
                throw "Bootstrap reset refuses malformed canonical release history."
            }
        }
    }
    if (@($records | Where-Object { $_.outcome -in @("succeeded", "rolled_back") }).Count -gt 0) {
        throw "Bootstrap reset is forbidden after any successful production release."
    }

    $app = Get-SelfHandlerContainerId -Service app -AllowMissing
    $web = Get-SelfHandlerContainerId -Service web -AllowMissing
    if ([bool]$app -ne [bool]$web) {
        throw "Bootstrap reset refuses an unpaired application container state."
    }
    if ($app -and $web) {
        $appInspection = Get-DockerInspection -ContainerId $app
        $webInspection = Get-DockerInspection -ContainerId $web
        $matchingFailure = @($records | Where-Object {
            $_.outcome -eq "recovery_required" -and
            $null -eq $_.previous_release -and
            [string]$appInspection.Config.Image -eq (Get-ImageReference -Service app -Digest ([string]$_.app_digest)) -and
            [string]$webInspection.Config.Image -eq (Get-ImageReference -Service web -Digest ([string]$_.web_digest)) -and
            [string]$appInspection.Config.Labels.'org.opencontainers.image.revision' -eq [string]$_.source_revision -and
            [string]$webInspection.Config.Labels.'org.opencontainers.image.revision' -eq [string]$_.source_revision
        })
        if ($matchingFailure.Count -ne 1) {
            throw "Bootstrap reset cannot prove app/web belong to one failed first-bootstrap attempt."
        }
    }
}

function Wait-DisposableRestoreDatabaseReady {
    param([Parameter(Mandatory = $true)][string]$Container)

    for ($attempt = 1; $attempt -le 120; $attempt++) {
        & docker exec $Container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot -e "SELECT 1"' *> $null
        if ($LASTEXITCODE -eq 0) { return }
        Start-Sleep -Seconds 1
    }
    throw "Disposable restore preflight database did not become ready."
}

function Assert-DisposablePreflightResourceLabel {
    param(
        [Parameter(Mandatory = $true)][ValidateSet("container", "volume")][string]$Type,
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Project
    )

    $template = '{{ index .Config.Labels "selfhandler.validation-project" }}'
    $arguments = @("inspect", "--format", $template, $Name)
    if ($Type -eq "volume") {
        $template = '{{ index .Labels "selfhandler.validation-project" }}'
        $arguments = @("volume", "inspect", "--format", $template, $Name)
    }
    $observed = [string](& docker @arguments)
    if ($LASTEXITCODE -ne 0 -or $observed.Trim() -ne $Project) {
        throw "Refusing cleanup outside the generated disposable restore preflight."
    }
}

function Invoke-DisposableProductionRestorePreflight {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [Parameter(Mandatory = $true)][string]$DatabasePath,
        [Parameter(Mandatory = $true)][string]$PrivateArchivePath,
        [Parameter(Mandatory = $true)][string]$ApplicationImage,
        [Parameter(Mandatory = $true)][string]$WorkingRoot
    )

    $project = "selfhandler-drill-$([Guid]::NewGuid().ToString('N').Substring(0, 12))"
    Assert-DisposableRestoreProject -Project $project
    $databaseContainer = "$project-db"
    $databaseVolume = "$project-mysql"
    $privateVolume = "$project-private"
    $environmentPath = Join-Path $WorkingRoot "$project.env"
    $createdContainer = $false
    $createdDatabaseVolume = $false
    $createdPrivateVolume = $false
    try {
        & docker image inspect $script:SelfHandlerMySqlImage *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "Pinned disposable MySQL image is not available locally."
        }
        $environment = @(
            "MYSQL_ROOT_PASSWORD=$(New-RandomSecretText)",
            "MYSQL_DATABASE=selfhandler",
            "MYSQL_USER=selfhandler",
            "MYSQL_PASSWORD=$(New-RandomSecretText)"
        ) -join "`n"
        [IO.File]::WriteAllText($environmentPath, $environment + "`n", (New-Object Text.UTF8Encoding($false)))

        & docker volume create --label "selfhandler.validation-project=$project" $databaseVolume *> $null
        if ($LASTEXITCODE -ne 0) { throw "Disposable database volume creation failed." }
        $createdDatabaseVolume = $true
        & docker volume create --label "selfhandler.validation-project=$project" $privateVolume *> $null
        if ($LASTEXITCODE -ne 0) { throw "Disposable private volume creation failed." }
        $createdPrivateVolume = $true
        & docker run -d --name $databaseContainer --label "selfhandler.validation-project=$project" --network none --read-only --user "999:999" --cap-drop ALL --security-opt "no-new-privileges:true" --pids-limit 256 --memory 768m --cpus "0.75" --tmpfs "/run/mysqld:rw,nosuid,nodev,size=16m,uid=999,gid=999,mode=0750" --tmpfs "/tmp:rw,nosuid,nodev,size=64m,uid=999,gid=999,mode=1770" --env-file $environmentPath --mount "type=volume,source=$databaseVolume,target=/var/lib/mysql" $script:SelfHandlerMySqlImage *> $null
        if ($LASTEXITCODE -ne 0) { throw "Disposable restore preflight database could not start." }
        $createdContainer = $true
        Wait-DisposableRestoreDatabaseReady -Container $databaseContainer

        # This import and the authenticated semantic probes must all pass before
        # production web/app stop or database/private-store replacement.
        Restore-DatabasePayload -Container $databaseContainer -DatabasePath $DatabasePath
        $privateCount = Restore-PrivateFilesPayload -Project $project -Volume $privateVolume -ApplicationImage $ApplicationImage -ArchivePath $PrivateArchivePath
        $schemaFingerprint = Get-RestoredSchemaFingerprint -Container $databaseContainer
        if ($schemaFingerprint -ne [string]$Manifest.schema_fingerprint) {
            throw "Disposable restore schema does not match the authenticated manifest."
        }
        Assert-RestoredControlledCounts -DatabaseContainer $databaseContainer -Manifest $Manifest -PrivateFileCount $privateCount
        if ($null -eq $Manifest.source_release) {
            Assert-RestoredDatabaseSchemaEmpty -Container $databaseContainer
        }
        return [pscustomobject][ordered]@{
            project = $project
            schema_fingerprint = $schemaFingerprint
            database_controlled_count = [int64]$Manifest.database.controlled_count
            private_file_count = $privateCount
        }
    }
    finally {
        if ($createdContainer) {
            Assert-DisposablePreflightResourceLabel -Type container -Name $databaseContainer -Project $project
            & docker rm --force $databaseContainer *> $null
            if ($LASTEXITCODE -ne 0) { throw "Disposable restore preflight container cleanup failed." }
        }
        if ($createdPrivateVolume) {
            Assert-DisposablePreflightResourceLabel -Type volume -Name $privateVolume -Project $project
            & docker volume rm $privateVolume *> $null
            if ($LASTEXITCODE -ne 0) { throw "Disposable restore preflight private-volume cleanup failed." }
        }
        if ($createdDatabaseVolume) {
            Assert-DisposablePreflightResourceLabel -Type volume -Name $databaseVolume -Project $project
            & docker volume rm $databaseVolume *> $null
            if ($LASTEXITCODE -ne 0) { throw "Disposable restore preflight database-volume cleanup failed." }
        }
        if ([IO.File]::Exists($environmentPath)) {
            [IO.File]::Delete($environmentPath)
        }
    }
}

function Assert-ProductionRestoreVolumes {
    foreach ($volume in @($script:SelfHandlerDatabaseVolume, $script:SelfHandlerPrivateFilesVolume)) {
        & docker volume inspect $volume *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "Required fixed production volume is missing."
        }
    }
}

function Test-RestoredProductionHealth {
    param([Parameter(Mandatory = $true)][string]$ExpectedRevision)

    $deadline = [DateTime]::UtcNow.AddMinutes(10)
    do {
        $local = Test-SelfHandlerReadiness -Scope Local -ExpectedRevision $ExpectedRevision -TimeoutSeconds 5
        if ($local.status -eq "healthy") {
            break
        }
        Start-Sleep -Seconds 3
    } while ([DateTime]::UtcNow -lt $deadline)
    if ($local.status -ne "healthy") {
        return $false
    }
    $private = Test-SelfHandlerReadiness -Scope Private -ExpectedRevision $ExpectedRevision -TimeoutSeconds 20
    return $private.status -eq "healthy"
}

$bundle = [IO.Path]::GetFullPath($BundlePath)
$identity = [IO.Path]::GetFullPath($IdentityPath)
if (-not (Test-Path -LiteralPath $bundle -PathType Leaf) -or -not $bundle.EndsWith(".age", [StringComparison]::OrdinalIgnoreCase)) {
    throw "Encrypted recovery bundle path is invalid."
}
if (-not (Test-Path -LiteralPath $identity -PathType Leaf)) {
    throw "Operator-held age identity was not found."
}
$identity = Assert-ProtectedSecretFile -Path $identity
if ($RecoveryMode -eq "Drill") {
    if ([String]::IsNullOrWhiteSpace($DisposableProject)) {
        throw "Drill mode requires a generated disposable project."
    }
    Assert-DisposableRestoreProject -Project $DisposableProject
    if ($Target -or $ConfirmTarget -or $SafetyBackupReference -or $ConfirmBootstrapReset -or $ConfirmStaleBackup -or $OriginalHostUnavailable) {
        throw "Production authorization values are forbidden in drill mode."
    }
} else {
    if ($Target -ne $script:SelfHandlerDeploymentId -or $ConfirmTarget -ne $script:SelfHandlerDeploymentId) {
        throw "Production recovery requires exact target confirmation."
    }
    if ($DisposableProject) {
        throw "Disposable project is not accepted in production recovery mode."
    }
    if ($RecoveryMode -eq "Production") {
        if (-not $OriginalHostUnavailable -and [String]::IsNullOrWhiteSpace($SafetyBackupReference)) {
            throw "Production recovery requires a validated pre-restore safety backup."
        }
        if ($ConfirmBootstrapReset) {
            throw "Bootstrap-reset confirmation is forbidden in normal production recovery."
        }
    } else {
        if ($ConfirmBootstrapReset -ne "RESET selfhandler-production TO EMPTY BOOTSTRAP BASELINE") {
            throw "Bootstrap reset requires its exact destructive confirmation phrase."
        }
        if ($SafetyBackupReference -or $OriginalHostUnavailable) {
            throw "Production safety-backup overrides are not accepted for bootstrap reset."
        }
    }
}

$hmacKeyPath = Join-Path $script:SelfHandlerSecretRoot "backup-hmac.key"
if ($env:SELFHANDLER_RECOVERY_HMAC_KEY_PATH) {
    $hmacKeyPath = [IO.Path]::GetFullPath($env:SELFHANDLER_RECOVERY_HMAC_KEY_PATH)
}
$hmacKeyPath = Assert-ProtectedSecretFile -Path $hmacKeyPath -MinimumBytes 32
$age = (Get-Command age -ErrorAction Stop).Source
$python = (Get-Command python -ErrorAction Stop).Source
$plainRoot = Join-Path ([IO.Path]::GetTempPath()) ("selfhandler-restore-{0}" -f [Guid]::NewGuid().ToString("N"))
$lock = $null
$result = $null

try {
    $lock = Enter-SelfHandlerProductionLock
    Assert-SelfHandlerStateRootIntegrity | Out-Null
    if ($RecoveryMode -ne "Drill" -and @(Get-UnfinishedPendingReleaseRecords).Count -gt 0) {
        throw "restore_refused_pending_release"
    }
    New-Item -ItemType Directory -Path $plainRoot | Out-Null
    $plaintextBundle = Join-Path $plainRoot "recovery.tar"
    $payloadRoot = Join-Path $plainRoot "payloads"
    & $age --decrypt --identity $identity --output $plaintextBundle $bundle *> $null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $plaintextBundle -PathType Leaf)) {
        throw "Encrypted recovery bundle authentication or decryption failed."
    }
    $maximumAgeHours = $(if ($RecoveryMode -eq "Drill") { 24 } else { 720 })
    $validationJson = & $python (Join-Path $script:SelfHandlerDeploymentRoot "recovery.py") validate --bundle $plaintextBundle --hmac-key-file $hmacKeyPath --max-age-hours $maximumAgeHours --extract-to $payloadRoot 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Recovery bundle validation failed before target mutation."
    }
    $validation = $validationJson | ConvertFrom-Json
    $manifest = $null
    $manifestBytes = $null
    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
    # The Python validator is authoritative; read its already authenticated manifest without extraction.
    $manifestCommand = "import json,sys,tarfile; a=tarfile.open(sys.argv[1]); print(a.extractfile('manifest.json').read().decode('utf-8'))"
    $manifestBytes = & $python -c $manifestCommand $plaintextBundle 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Authenticated recovery manifest could not be read."
    }
    $manifest = $manifestBytes | ConvertFrom-Json
    $createdAt = [DateTimeOffset]::Parse([string]$manifest.created_at).ToUniversalTime()
    $recoveryAgeHours = ([DateTimeOffset]::UtcNow - $createdAt).TotalHours
    Assert-SelfHandlerRecoveryAgePolicy -RecoveryMode $RecoveryMode -AgeHours $recoveryAgeHours -StaleConfirmation $ConfirmStaleBackup
    if ($recoveryAgeHours -gt 24) {
        Write-SafeOperationMessage -Code "recovery.stale_authorized" -Detail "Authenticated backup is inside the fixed 30-day retention ceiling."
    }

    # Resolve and authenticate every production prerequisite before the first
    # stop/drop/import/private-volume mutation. A baseline is validation-only
    # and can never be promoted into an active production release.
    $validatedSourceRelease = $manifest.source_release
    $validatedAppImage = $null
    $validatedWebImage = $null
    $validatedSafetyBackup = $null
    $productionRestorePreflight = $null
    if ($RecoveryMode -eq "Production") {
        if ($null -eq $validatedSourceRelease) {
            throw "A bootstrap baseline cannot become an active production application release."
        }
        if ([string]$validatedSourceRelease.source_revision -notmatch '^[0-9a-f]{40}$') {
            throw "Authenticated recovery source revision is invalid."
        }
        $validatedAppImage = Get-ImageReference -Service app -Digest ([string]$validatedSourceRelease.app_digest)
        $validatedWebImage = Get-ImageReference -Service web -Digest ([string]$validatedSourceRelease.web_digest)
        Assert-LocalRecoveryImage -Reference $validatedAppImage -ExpectedRevision ([string]$validatedSourceRelease.source_revision)
        Assert-LocalRecoveryImage -Reference $validatedWebImage -ExpectedRevision ([string]$validatedSourceRelease.source_revision)
        Assert-ProductionRestoreVolumes
        if (-not $OriginalHostUnavailable) {
            $validatedSafetyBackup = Get-ValidatedPreRestoreSafetyBackup -Reference $SafetyBackupReference
        }
        $productionRestorePreflight = Invoke-DisposableProductionRestorePreflight `
            -Manifest $manifest `
            -DatabasePath (Join-Path $payloadRoot "database.sql") `
            -PrivateArchivePath (Join-Path $payloadRoot "private-files.tar") `
            -ApplicationImage $validatedAppImage `
            -WorkingRoot $plainRoot
    } elseif ($RecoveryMode -eq "BootstrapReset") {
        if ($null -ne $validatedSourceRelease -or
            [string]$manifest.backup_reason -ne "bootstrap-baseline" -or
            [string]$manifest.schema_fingerprint -ne $script:SelfHandlerEmptySchemaFingerprint -or
            [int64]$manifest.database.controlled_count -ne 0 -or
            [int64]$manifest.private_files.controlled_count -ne 0) {
            throw "Bootstrap reset accepts only an authenticated empty bootstrap baseline."
        }
        Assert-BootstrapResetHistoryEligible
        Assert-ProductionRestoreVolumes
        $productionRestorePreflight = Invoke-DisposableProductionRestorePreflight `
            -Manifest $manifest `
            -DatabasePath (Join-Path $payloadRoot "database.sql") `
            -PrivateArchivePath (Join-Path $payloadRoot "private-files.tar") `
            -ApplicationImage $script:SelfHandlerMySqlImage `
            -WorkingRoot $plainRoot
    }

    if ($RecoveryMode -eq "Drill") {
        $project = $DisposableProject
        foreach ($resourceName in @("${project}_mysql_data", "${project}_private_files", "${project}_app", "${project}_data")) {
            if ($resourceName -in @($script:SelfHandlerDatabaseVolume, $script:SelfHandlerPrivateFilesVolume, $script:SelfHandlerAppNetwork, $script:SelfHandlerDataNetwork) -or $resourceName.StartsWith("dealflow_")) {
                throw "Disposable restore resource conflicts with production."
            }
        }
        $existing = @(& docker ps -a --filter "label=com.docker.compose.project=$project" --format "{{.ID}}")
        if ($existing.Count -gt 0) {
            throw "Disposable restore project already exists."
        }
        $port = Get-FreeLoopbackPort
        $environmentPath = Join-Path $plainRoot "drill.env"
        $overridePath = Join-Path $plainRoot "drill.override.yaml"
        $appKeyBytes = New-Object byte[] 32
        $appKeyGenerator = [Security.Cryptography.RandomNumberGenerator]::Create()
        try { $appKeyGenerator.GetBytes($appKeyBytes) } finally { $appKeyGenerator.Dispose() }
        $appKey = "base64:" + [Convert]::ToBase64String($appKeyBytes)
        $sourceRelease = $manifest.source_release
        $releaseSha = "0" * 40
        $webImage = "$script:SelfHandlerWebRepository@sha256:" + ("0" * 64)
        $appImage = "$script:SelfHandlerAppRepository@sha256:" + ("0" * 64)
        if ($null -ne $sourceRelease) {
            $releaseSha = [string]$sourceRelease.source_revision
            $webImage = Get-ImageReference -Service web -Digest ([string]$sourceRelease.web_digest)
            $appImage = Get-ImageReference -Service app -Digest ([string]$sourceRelease.app_digest)
            Assert-LocalRecoveryImage -Reference $webImage -ExpectedRevision $releaseSha
            Assert-LocalRecoveryImage -Reference $appImage -ExpectedRevision $releaseSha
        }
        $environment = @(
            "SELFHANDLER_WEB_IMAGE=$webImage",
            "SELFHANDLER_APP_IMAGE=$appImage",
            "APP_KEY=$appKey",
            "APP_RELEASE_SHA=$releaseSha",
            "DB_DATABASE=selfhandler",
            "DB_USERNAME=selfhandler",
            "DB_PASSWORD=$(New-RandomSecretText)",
            "DB_ROOT_PASSWORD=$(New-RandomSecretText)"
        ) -join "`n"
        [IO.File]::WriteAllText($environmentPath, $environment + "`n", (New-Object Text.UTF8Encoding($false)))
        $override = @"
name: $project
services:
  db:
    restart: "no"
    labels:
      selfhandler.validation-project: $project
  app:
    image: $appImage
    environment:
      APP_URL: http://127.0.0.1:$port
      SESSION_SECURE_COOKIE: "false"
      SANCTUM_STATEFUL_DOMAINS: 127.0.0.1:$port
    restart: "no"
    labels:
      selfhandler.validation-project: $project
  web:
    image: $webImage
    restart: "no"
    ports: !override
      - "127.0.0.1:$port:8080"
    labels:
      selfhandler.validation-project: $project
networks:
  app:
    name: ${project}_app
  data:
    name: ${project}_data
volumes:
  mysql_data:
    name: ${project}_mysql_data
  private_files:
    name: ${project}_private_files
"@
        [IO.File]::WriteAllText($overridePath, $override, (New-Object Text.UTF8Encoding($false)))
        Invoke-DisposableCompose -Project $project -EnvironmentPath $environmentPath -OverridePath $overridePath up -d db
        $databaseContainer = Get-ProjectServiceContainer -Project $project -Service db
        Wait-DatabaseContainer -Container $databaseContainer
        Restore-DatabasePayload -Container $databaseContainer -DatabasePath (Join-Path $payloadRoot "database.sql")
        $privateHelperImage = $appImage
        if ($null -eq $sourceRelease) {
            $privateHelperImage = (& docker inspect --format "{{.Image}}" $databaseContainer).Trim()
        }
        $privateFileCount = Restore-PrivateFilesPayload -Project $project -Volume "${project}_private_files" -ApplicationImage $privateHelperImage -ArchivePath (Join-Path $payloadRoot "private-files.tar")
        $schemaFingerprint = Get-RestoredSchemaFingerprint -Container $databaseContainer
        if ($schemaFingerprint -ne [string]$manifest.schema_fingerprint) {
            throw "Restored schema fingerprint does not match the authenticated manifest."
        }
        Assert-RestoredControlledCounts -DatabaseContainer $databaseContainer -Manifest $manifest -PrivateFileCount $privateFileCount
        if ($null -ne $sourceRelease) {
            Invoke-DisposableCompose -Project $project -EnvironmentPath $environmentPath -OverridePath $overridePath up -d --no-build --pull never app web
            $deadline = [DateTime]::UtcNow.AddMinutes(10)
            $healthy = $false
            do {
                try {
                    $health = Invoke-RestMethod -Uri "http://127.0.0.1:$port/api/health" -TimeoutSec 5
                    if ($health.status -eq "ok" -and [string]$health.release -eq $releaseSha) {
                        $healthy = $true
                        break
                    }
                } catch { }
                Start-Sleep -Seconds 3
            } while ([DateTime]::UtcNow -lt $deadline)
            if (-not $healthy) {
                throw "Restored application did not become healthy."
            }
        }
        $result = [pscustomobject][ordered]@{
            status = "restored"
            mode = "drill"
            deployment_id = $script:SelfHandlerDeploymentId
            bundle_id = [string]$validation.bundle_id
            disposable_project = $project
            schema_fingerprint = $schemaFingerprint
            production_resources_touched = 0
        }
    } elseif ($RecoveryMode -eq "Production") {
        Invoke-SelfHandlerCompose stop web app
        $databaseContainer = Get-SelfHandlerContainerId -Service db -RunningOnly
        Restore-DatabasePayload -Container $databaseContainer -DatabasePath (Join-Path $payloadRoot "database.sql") -ReplaceExisting
        $privateFileCount = Restore-PrivateFilesPayload -Project $script:SelfHandlerComposeProject -Volume $script:SelfHandlerPrivateFilesVolume -ApplicationImage $validatedAppImage -ArchivePath (Join-Path $payloadRoot "private-files.tar") -ReplaceExisting
        $schemaFingerprint = Get-RestoredSchemaFingerprint -Container $databaseContainer
        if ($schemaFingerprint -ne [string]$manifest.schema_fingerprint) {
            throw "Restored production schema fingerprint does not match the authenticated manifest."
        }
        Assert-RestoredControlledCounts -DatabaseContainer $databaseContainer -Manifest $manifest -PrivateFileCount $privateFileCount
        $appImage = $validatedAppImage
        $webImage = $validatedWebImage
        $env:SELFHANDLER_APP_IMAGE = $appImage
        $env:SELFHANDLER_WEB_IMAGE = $webImage
        $env:APP_RELEASE_SHA = [string]$manifest.source_release.source_revision
        Invoke-SelfHandlerCompose up -d --no-build --pull never app web
        if (-not (Test-RestoredProductionHealth -ExpectedRevision ([string]$manifest.source_release.source_revision))) {
            throw "Production recovery completed storage replacement but final health failed."
        }
        $active = [pscustomobject][ordered]@{
            source_revision = [string]$manifest.source_release.source_revision
            web_digest = [string]$manifest.source_release.web_digest
            app_digest = [string]$manifest.source_release.app_digest
        }
        Set-ActiveRelease -Release $active
        $result = [pscustomobject][ordered]@{
            status = "restored"
            mode = "production"
            deployment_id = $script:SelfHandlerDeploymentId
            bundle_id = [string]$validation.bundle_id
            safety_backup = $(if ($OriginalHostUnavailable) { "unavailable_with_explicit_authorization" } else { $SafetyBackupReference })
            schema_fingerprint = [string]$manifest.schema_fingerprint
            disposable_preflight = [string]$productionRestorePreflight.project
        }
    } else {
        # Exact-confirmed recovery for a failed first deployment. The signed
        # empty baseline has already survived a disposable import/count/schema
        # preflight above. Never start a zero-digest application from it.
        Invoke-SelfHandlerCompose stop web app
        Invoke-SelfHandlerCompose rm -f -s web app
        Invoke-SelfHandlerCompose up -d --no-build --pull never db
        $databaseContainer = Get-SelfHandlerContainerId -Service db -RunningOnly
        Wait-DatabaseContainer -Container $databaseContainer
        Restore-DatabasePayload -Container $databaseContainer -DatabasePath (Join-Path $payloadRoot "database.sql") -ReplaceExisting
        $privateFileCount = Restore-PrivateFilesPayload -Project $script:SelfHandlerComposeProject -Volume $script:SelfHandlerPrivateFilesVolume -ApplicationImage $script:SelfHandlerMySqlImage -ArchivePath (Join-Path $payloadRoot "private-files.tar") -ReplaceExisting
        Assert-RestoredDatabaseSchemaEmpty -Container $databaseContainer
        Assert-RestoredControlledCounts -DatabaseContainer $databaseContainer -Manifest $manifest -PrivateFileCount $privateFileCount
        foreach ($service in @("app", "web")) {
            if ($null -ne (Get-SelfHandlerContainerId -Service $service -AllowMissing)) {
                throw "Bootstrap reset left an application container behind."
            }
        }
        $activePath = Join-Path $script:SelfHandlerStateRoot "active-release.json"
        if ([IO.File]::Exists($activePath)) {
            [IO.File]::Delete($activePath)
        }
        $result = [pscustomobject][ordered]@{
            status = "restored"
            mode = "bootstrap_reset"
            deployment_id = $script:SelfHandlerDeploymentId
            bundle_id = [string]$validation.bundle_id
            schema_fingerprint = $script:SelfHandlerEmptySchemaFingerprint
            database_controlled_count = 0
            private_file_count = 0
            application_started = $false
            active_release = $null
            disposable_preflight = [string]$productionRestorePreflight.project
        }
    }
}
finally {
    if (Test-Path -LiteralPath $plainRoot) {
        Remove-SensitivePath -Path $plainRoot
    }
    if ($lock) {
        Exit-SelfHandlerProductionLock -Lock $lock
    }
}

if ($null -eq $result) {
    throw "Recovery did not produce verified evidence."
}
Write-Output ($result | ConvertTo-Json -Depth 10 -Compress)
