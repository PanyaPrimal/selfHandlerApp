[CmdletBinding()]
param(
    [ValidateSet("predeploy", "scheduled", "manual", "pre-restore", "bootstrap-baseline", "bootstrap")]
    [string]$Reason = "manual",

    [Parameter(Mandatory = $true)]
    [string]$OutputDirectory
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "shared.ps1")
$script:SelfHandlerBackupMySqlImage = "mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb"

function Wait-BackupDatabaseHealthy {
    param([Parameter(Mandatory = $true)][string]$Container)

    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $status = (& docker inspect --format "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}" $Container).Trim()
        if ($LASTEXITCODE -eq 0 -and $status -eq "healthy") {
            return
        }
        if ($LASTEXITCODE -eq 0 -and $status -eq "running") {
            & docker exec $Container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot -e "SELECT 1"' *> $null
            if ($LASTEXITCODE -eq 0) { return }
        }
        Start-Sleep -Seconds 2
    }
    throw "Bootstrap database did not become healthy."
}

function Initialize-EmptyBootstrapStores {
    [CmdletBinding()]
    param()

    # This must precede database/volume initialization. A first deployment has
    # no previous release to roll back after a later Docker port-bind failure.
    Assert-BootstrapLoopbackPortAvailable

    if ([string]$env:SELFHANDLER_APP_IMAGE -notmatch ('^' + [regex]::Escape($script:SelfHandlerAppRepository) + '@sha256:[0-9a-f]{64}$') -or
        [string]$env:SELFHANDLER_WEB_IMAGE -notmatch ('^' + [regex]::Escape($script:SelfHandlerWebRepository) + '@sha256:[0-9a-f]{64}$') -or
        [string]$env:APP_RELEASE_SHA -notmatch '^[0-9a-f]{40}$') {
        throw "Bootstrap requires the trusted workflow's verified local candidate environment."
    }
    foreach ($service in @("web", "app")) {
        if ($null -ne (Get-SelfHandlerContainerId -Service $service -AllowMissing)) {
            throw "Bootstrap requires app and web containers to be absent."
        }
    }
    $appInspection = @(& docker image inspect ([string]$env:SELFHANDLER_APP_IMAGE) | ConvertFrom-Json)[0]
    if ($LASTEXITCODE -ne 0 -or [string]$appInspection.Config.Labels.'org.opencontainers.image.revision' -ne [string]$env:APP_RELEASE_SHA) {
        throw "Bootstrap candidate app image is not the verified local revision."
    }

    # Start only the pinned database. Creating (not starting) the app container
    # performs Docker volume copy-up so the empty private store has UID/GID 82
    # and mode 0750 before the stopped helper is removed.
    Invoke-SelfHandlerCompose up --detach --no-build --pull never db
    $database = Get-SelfHandlerContainerId -Service db -RunningOnly
    Wait-BackupDatabaseHealthy -Container $database
    $stoppedApp = $null
    try {
        Invoke-SelfHandlerCompose create --no-build --pull never --no-deps app
        $stoppedApp = Get-SelfHandlerContainerId -Service app
        $stoppedInspection = Get-DockerInspection -ContainerId $stoppedApp
        if ($stoppedInspection.State.Running) {
            throw "Bootstrap private-store initializer unexpectedly started application code."
        }
    }
    finally {
        if (-not $stoppedApp) {
            $stoppedApp = Get-SelfHandlerContainerId -Service app -AllowMissing
        }
        if ($stoppedApp) {
            & docker rm --force $stoppedApp *> $null
            if ($LASTEXITCODE -ne 0) {
                throw "Bootstrap private-store initializer could not be removed."
            }
        }
    }
    $privateStoreMode = [string](& docker run --rm --pull never --network none --read-only --cap-drop ALL --security-opt "no-new-privileges:true" --mount "type=volume,source=$script:SelfHandlerPrivateFilesVolume,target=/source,readonly" --entrypoint /bin/sh ([string]$env:SELFHANDLER_APP_IMAGE) -c 'stat -c "%u:%g:%a" /source' 2>$null)
    if ($LASTEXITCODE -ne 0 -or $privateStoreMode.Trim() -ne "82:82:750") {
        throw "Bootstrap private store ownership or mode is not 82:82:0750."
    }
    return $database
}

function New-BackupValidationSecret {
    $bytes = New-Object byte[] 32
    $generator = New-Object Security.Cryptography.RNGCryptoServiceProvider
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).Replace("+", "A").Replace("/", "B").TrimEnd("=")
}

function Assert-BackupValidationResourceLabel {
    param(
        [Parameter(Mandatory = $true)][ValidateSet("container", "volume")][string]$Type,
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Project
    )

    $arguments = @("inspect", "--format", '{{ index .Config.Labels "selfhandler.validation-project" }}', $Name)
    if ($Type -eq "volume") {
        $arguments = @("volume", "inspect", "--format", '{{ index .Labels "selfhandler.validation-project" }}', $Name)
    }
    $observed = [string](& docker @arguments)
    if ($LASTEXITCODE -ne 0 -or $observed.Trim() -ne $Project) {
        throw "Refusing cleanup outside the generated backup validation project."
    }
}

function Get-DatabaseSnapshotEvidence {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$DatabasePath,
        [Parameter(Mandatory = $true)][string]$WorkingRoot,
        [switch]$AllowEmpty
    )

    $project = "selfhandler-backup-verify-$([Guid]::NewGuid().ToString('N').Substring(0, 12))"
    $container = "$project-db"
    $volume = "$project-mysql"
    $environmentPath = Join-Path $WorkingRoot "$project.env"
    $importErrorPath = Join-Path $WorkingRoot "$project-import.error"
    $createdContainer = $false
    $createdVolume = $false
    try {
        & docker image inspect $script:SelfHandlerBackupMySqlImage *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "Pinned disposable MySQL image is not available locally."
        }
        [IO.File]::WriteAllText(
            $environmentPath,
            "MYSQL_ROOT_PASSWORD=$(New-BackupValidationSecret)`nMYSQL_DATABASE=selfhandler`n",
            (New-Object Text.UTF8Encoding($false))
        )
        & docker volume create --label "selfhandler.validation-project=$project" $volume *> $null
        if ($LASTEXITCODE -ne 0) { throw "Disposable backup-validation volume creation failed." }
        $createdVolume = $true
        & docker run -d --name $container --label "selfhandler.validation-project=$project" --network none --read-only --user "999:999" --cap-drop ALL --security-opt "no-new-privileges:true" --pids-limit 256 --memory 768m --cpus "0.75" --tmpfs "/run/mysqld:rw,nosuid,nodev,size=16m,uid=999,gid=999,mode=0750" --tmpfs "/tmp:rw,nosuid,nodev,size=64m,uid=999,gid=999,mode=1770" --env-file $environmentPath --mount "type=volume,source=$volume,target=/var/lib/mysql" $script:SelfHandlerBackupMySqlImage *> $null
        if ($LASTEXITCODE -ne 0) { throw "Disposable backup-validation database could not start." }
        $createdContainer = $true
        Wait-BackupDatabaseHealthy -Container $container

        $dockerPath = (Get-Command docker -ErrorAction Stop).Source
        $importScript = 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot "$MYSQL_DATABASE"'
        $importExit = Invoke-NativeProcessRedirected `
            -FilePath $dockerPath `
            -Arguments @("exec", "-i", $container, "sh", "-c", $importScript) `
            -StandardInputPath $DatabasePath `
            -StandardErrorPath $importErrorPath
        if ($importExit -ne 0) {
            throw "The exact database snapshot failed disposable import validation."
        }
        if ([IO.File]::Exists($importErrorPath)) { [IO.File]::Delete($importErrorPath) }

        $objectCountText = [string](& docker exec $container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"' 2>$null)
        if ($LASTEXITCODE -ne 0 -or $objectCountText.Trim() -notmatch '^[0-9]+$') {
            throw "The exact database snapshot object-count probe failed."
        }
        $objectCount = [int64]$objectCountText.Trim()
        if ($AllowEmpty) {
            if ($objectCount -ne 0) { throw "The bootstrap database snapshot is not empty." }
            return [pscustomobject][ordered]@{
                controlled_count = 0
                schema_fingerprint = $script:SelfHandlerEmptySchemaFingerprint
            }
        }

        $countText = [string](& docker exec $container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM users;"' 2>$null)
        if ($LASTEXITCODE -ne 0 -or $countText.Trim() -notmatch '^[0-9]+$') {
            throw "The exact database snapshot controlled-count probe failed."
        }
        $migrationRows = @(& docker exec $container sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT migration, batch FROM migrations ORDER BY migration;"' 2>$null)
        if ($LASTEXITCODE -ne 0) {
            throw "The exact database snapshot schema probe failed."
        }
        return [pscustomobject][ordered]@{
            controlled_count = [int64]$countText.Trim()
            schema_fingerprint = Get-Sha256Text -Text (($migrationRows | ForEach-Object { $_.TrimEnd() }) -join "`n")
        }
    }
    finally {
        if ($createdContainer) {
            Assert-BackupValidationResourceLabel -Type container -Name $container -Project $project
            & docker rm --force $container *> $null
            if ($LASTEXITCODE -ne 0) { throw "Disposable backup-validation container cleanup failed." }
        }
        if ($createdVolume) {
            Assert-BackupValidationResourceLabel -Type volume -Name $volume -Project $project
            & docker volume rm $volume *> $null
            if ($LASTEXITCODE -ne 0) { throw "Disposable backup-validation volume cleanup failed." }
        }
        foreach ($sensitivePath in @($environmentPath, $importErrorPath)) {
            if ([IO.File]::Exists($sensitivePath)) { [IO.File]::Delete($sensitivePath) }
        }
    }
}

$outputRoot = Assert-SafeFilesystemTarget -Path $OutputDirectory
if (Test-Path -LiteralPath $outputRoot) {
    if (@(Get-ChildItem -LiteralPath $outputRoot -Force).Count -gt 0) {
        throw "Backup output directory already exists and is not empty."
    }
} else {
    New-Item -ItemType Directory -Path $outputRoot | Out-Null
}

$plainRoot = Join-Path ([IO.Path]::GetTempPath()) ("selfhandler-backup-{0}" -f [Guid]::NewGuid().ToString("N"))
$lock = $null
$databaseContainer = $null
$privateHelper = $null
$ciphertextPartial = $null
$result = $null

try {
    $lock = Enter-SelfHandlerProductionLock
    Assert-SelfHandlerStateRootIntegrity | Out-Null
    Assert-RequiredCommands -Names @("docker")
    Assert-ProtectedSecretFile -Path $script:SelfHandlerOpsConfigPath | Out-Null
    $ops = Read-KeyValueFile -Path $script:SelfHandlerOpsConfigPath
    foreach ($forbiddenOverride in @("PYTHON_EXECUTABLE", "AGE_EXECUTABLE")) {
        if ($ops.ContainsKey($forbiddenOverride)) {
            throw "Executable overrides are forbidden in production operations configuration."
        }
    }
    $pythonCommand = Get-Command python -ErrorAction SilentlyContinue
    $ageCommand = Get-Command age -ErrorAction SilentlyContinue
    if (-not $pythonCommand -or -not $ageCommand) {
        throw "Verified Python and age executables are required on PATH."
    }
    $python = $pythonCommand.Source
    $age = $ageCommand.Source
    if (-not $ops.ContainsKey("AGE_RECIPIENT") -or [string]$ops["AGE_RECIPIENT"] -notmatch '^age1[0-9a-z]{20,100}$') {
        throw "Configured age recipient is missing or invalid."
    }
    $ageRecipient = [string]$ops["AGE_RECIPIENT"]
    $recipientFingerprint = Get-Sha256Text -Text $ageRecipient
    if (-not $ops.ContainsKey("AGE_RECIPIENT_FINGERPRINT") -or
        [string]$ops["AGE_RECIPIENT_FINGERPRINT"] -ne $recipientFingerprint) {
        throw "Configured age recipient fingerprint does not match the recipient."
    }
    if (-not $ops.ContainsKey("BACKUP_HMAC_KEY_ID") -or [string]$ops["BACKUP_HMAC_KEY_ID"] -notmatch '^[A-Za-z0-9._-]{1,64}$') {
        throw "Backup authentication key identity is missing or invalid."
    }
    $keyId = [string]$ops["BACKUP_HMAC_KEY_ID"]
    $hmacKeyPath = Join-Path $script:SelfHandlerSecretRoot "backup-hmac.key"
    if (-not $ops.ContainsKey("BACKUP_HMAC_KEY_PATH") -or
        [IO.Path]::GetFullPath([string]$ops["BACKUP_HMAC_KEY_PATH"]) -ne [IO.Path]::GetFullPath($hmacKeyPath)) {
        throw "The backup authentication key path must equal the fixed protected path."
    }
    $hmacKeyPath = Assert-ProtectedSecretFile -Path $hmacKeyPath -MinimumBytes 32
    $pendingReleases = @(Get-UnfinishedPendingReleaseRecords)
    if ($Reason -ne "bootstrap" -and $pendingReleases.Count -gt 0) {
        throw "backup_refused_pending_release"
    }
    $activeRelease = Get-ActiveRelease
    $bootstrapPending = $null
    if ($Reason -eq "bootstrap-baseline") {
        if ($null -ne $activeRelease) {
            throw "bootstrap-baseline is permitted only before the first active release."
        }
        $databaseContainer = Initialize-EmptyBootstrapStores
    } elseif ($Reason -eq "bootstrap") {
        if ($null -ne $activeRelease) {
            throw "bootstrap completion backup is permitted only before release finalization."
        }
        $pendingCandidates = @($pendingReleases)
        if ($pendingCandidates.Count -ne 1) {
            throw "bootstrap completion backup requires exactly one pending release."
        }
        $bootstrapPending = $pendingCandidates[0]
        if ([string]$bootstrapPending.state -ne "awaiting_completion" -or
            -not ($bootstrapPending.bootstrap -is [bool]) -or
            -not [bool]$bootstrapPending.bootstrap -or
            $null -ne $bootstrapPending.previous_release -or
            [string]$bootstrapPending.source_revision -ne [string]$env:APP_RELEASE_SHA -or
            (Get-ImageReference -Service app -Digest ([string]$bootstrapPending.app_digest)) -ne [string]$env:SELFHANDLER_APP_IMAGE -or
            (Get-ImageReference -Service web -Digest ([string]$bootstrapPending.web_digest)) -ne [string]$env:SELFHANDLER_WEB_IMAGE) {
            throw "bootstrap completion backup does not match the pending candidate."
        }
        $activeRelease = [pscustomobject][ordered]@{
            source_revision = [string]$bootstrapPending.source_revision
            web_digest = [string]$bootstrapPending.web_digest
            app_digest = [string]$bootstrapPending.app_digest
        }
        foreach ($service in @("web", "app")) {
            $container = Get-SelfHandlerContainerId -Service $service -RunningOnly
            $inspection = Get-DockerInspection -ContainerId $container
            $digestField = "${service}_digest"
            $expectedImage = Get-ImageReference -Service $service -Digest ([string]$activeRelease.$digestField)
            if ([string]$inspection.Config.Image -ne $expectedImage) {
                throw "bootstrap completion backup runtime does not match the pending candidate."
            }
        }
        $databaseContainer = Get-SelfHandlerContainerId -Service db -RunningOnly
    } else {
        $databaseContainer = Get-SelfHandlerContainerId -Service db -RunningOnly
    }
    foreach ($volume in @($script:SelfHandlerDatabaseVolume, $script:SelfHandlerPrivateFilesVolume)) {
        & docker volume inspect $volume *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "Required fixed production volume is missing."
        }
    }

    if ($Reason -eq "bootstrap-baseline") {
        # A missing active-release pointer is not sufficient proof of a new
        # target: a failed bootstrap may already have migrated the database.
        $bootstrapObjectCountText = [string](& docker exec $databaseContainer sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"')
        $bootstrapObjectCountText = $bootstrapObjectCountText.Trim()
        if (
            $LASTEXITCODE -ne 0 -or
            $bootstrapObjectCountText -notmatch '^[0-9]+$' -or
            [int64]$bootstrapObjectCountText -ne 0
        ) {
            throw "bootstrap-baseline requires an empty database schema."
        }
    } elseif ($null -eq $activeRelease) {
        throw "A non-baseline backup requires an active release."
    }

    New-Item -ItemType Directory -Path $plainRoot | Out-Null
    $databasePath = Join-Path $plainRoot "database.sql"
    $privateArchivePath = Join-Path $plainRoot "private-files.tar"
    $sourceReleasePath = Join-Path $plainRoot "source-release.json"
    $plaintextBundlePath = Join-Path $plainRoot "recovery.tar"
    $databaseDumpErrorPath = Join-Path $plainRoot "database-dump.error"
    $bundleId = "selfhandler-$([DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ'))-$([Guid]::NewGuid().ToString('N').Substring(0, 8))"

    Write-SafeOperationMessage -Code "backup.database" -Detail "Creating a transaction-consistent logical snapshot."
    $dockerPath = (Get-Command docker -ErrorAction Stop).Source
    $dumpScript = 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump --comments --single-transaction --routines --triggers --events -uroot "$MYSQL_DATABASE"'
    $dumpExit = Invoke-NativeProcessRedirected `
        -FilePath $dockerPath `
        -Arguments @("exec", $databaseContainer, "sh", "-c", $dumpScript) `
        -StandardOutputPath $databasePath `
        -StandardErrorPath $databaseDumpErrorPath
    if ($dumpExit -ne 0) {
        throw "The database snapshot failed."
    }
    if (-not (Test-Path -LiteralPath $databasePath -PathType Leaf) -or (Get-Item -LiteralPath $databasePath).Length -le 0) {
        throw "The database snapshot failed its content precheck."
    }
    if ([IO.File]::Exists($databaseDumpErrorPath)) { [IO.File]::Delete($databaseDumpErrorPath) }

    # The authenticated count and schema are derived from an import of these
    # exact dump bytes. Never query live state after mysqldump: a concurrent
    # registration may commit after the transaction snapshot was established.
    $snapshotEvidence = Get-DatabaseSnapshotEvidence `
        -DatabasePath $databasePath `
        -WorkingRoot $plainRoot `
        -AllowEmpty:($Reason -eq "bootstrap-baseline")
    $databaseCount = [int64]$snapshotEvidence.controlled_count

    if ($Reason -eq "bootstrap-baseline") {
        Write-SafeOperationMessage -Code "backup.private" -Detail "Verifying the new private-file volume is empty."
        $databaseImage = (& docker inspect --format "{{.Image}}" $databaseContainer).Trim()
        $privateHelper = "selfhandler-private-backup-$([Guid]::NewGuid().ToString('N'))"
        & docker create --name $privateHelper --entrypoint /bin/sh --mount "type=volume,source=$script:SelfHandlerPrivateFilesVolume,target=/source,readonly" $databaseImage -c 'test -z "$(find /source -mindepth 1 -print -quit)"' *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "The empty private-file volume verifier could not be created."
        }
        & docker start --attach $privateHelper *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "bootstrap-baseline requires an empty private-file volume."
        }
        & $python (Join-Path $script:SelfHandlerDeploymentRoot "recovery.py") create-empty-private --output $privateArchivePath *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "The empty private-file archive could not be created."
        }
    } else {
        Write-SafeOperationMessage -Code "backup.private" -Detail "Creating a read-only private-file snapshot."
        $applicationContainer = Get-SelfHandlerContainerId -Service app -RunningOnly
        $applicationImage = (& docker inspect --format "{{.Image}}" $applicationContainer).Trim()
        $privateHelper = "selfhandler-private-backup-$([Guid]::NewGuid().ToString('N'))"
        & docker create --name $privateHelper --entrypoint /bin/sh --mount "type=volume,source=$script:SelfHandlerPrivateFilesVolume,target=/source,readonly" $applicationImage -c 'set -eu; umask 077; tar -C /source -cf /tmp/private-files.tar .; tar -tf /tmp/private-files.tar >/dev/null' *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "The private-file snapshot helper could not be created."
        }
        & docker start --attach $privateHelper *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "The private-file snapshot failed."
        }
        & docker cp ("{0}:/tmp/private-files.tar" -f $privateHelper) $privateArchivePath *> $null
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $privateArchivePath -PathType Leaf)) {
            throw "The private-file snapshot could not be staged."
        }
    }

    $privateCountJson = & $python (Join-Path $script:SelfHandlerDeploymentRoot "recovery.py") validate-private --archive $privateArchivePath 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "The private-file snapshot failed safe-path validation."
    }
    $privateCount = [int](($privateCountJson | ConvertFrom-Json).private_file_count)
    $schemaFingerprint = [string]$snapshotEvidence.schema_fingerprint
    if ($Reason -eq "bootstrap" -and [string]$bootstrapPending.schema_after -ne $schemaFingerprint) {
        throw "bootstrap completion backup schema does not match the pending candidate."
    }
    $sourceArguments = @()
    $sourceRelease = $null
    if ($null -ne $activeRelease) {
        $sourceRelease = [pscustomobject][ordered]@{
            source_revision = [string]$activeRelease.source_revision
            web_digest = [string]$activeRelease.web_digest
            app_digest = [string]$activeRelease.app_digest
        }
        [IO.File]::WriteAllText($sourceReleasePath, (ConvertTo-CompactJson -Value $sourceRelease), (New-Object Text.UTF8Encoding($false)))
        $sourceArguments = @("--source-release-json", $sourceReleasePath)
    }

    $createArguments = @(
        (Join-Path $script:SelfHandlerDeploymentRoot "recovery.py"), "create",
        "--database", $databasePath,
        "--private-archive", $privateArchivePath,
        "--output", $plaintextBundlePath,
        "--hmac-key-file", $hmacKeyPath,
        "--key-id", $keyId,
        "--recipient-fingerprint", $recipientFingerprint,
        "--reason", $Reason,
        "--schema-fingerprint", $schemaFingerprint,
        "--database-count", [string]$databaseCount,
        "--private-count", [string]$privateCount,
        "--bundle-id", $bundleId
    ) + $sourceArguments
    & $python @createArguments *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "Recovery manifest or plaintext bundle creation failed."
    }

    # Stable contract marker used by tests and trusted workflow review:
    # recovery.py validate --bundle runs before age --encrypt.
    & $python (Join-Path $script:SelfHandlerDeploymentRoot "recovery.py") validate --bundle $plaintextBundlePath --hmac-key-file $hmacKeyPath *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "The authenticated plaintext recovery bundle is invalid."
    }

    $ciphertextPath = Join-Path $outputRoot ("{0}.tar.age" -f $bundleId)
    $ciphertextPartial = "$ciphertextPath.partial"
    & $age --encrypt --recipient $ageRecipient --output $ciphertextPartial $plaintextBundlePath
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $ciphertextPartial -PathType Leaf)) {
        throw "Recovery bundle encryption failed."
    }
    if ((Get-Item -LiteralPath $ciphertextPartial).Length -le 0) {
        throw "Encrypted recovery bundle is empty."
    }
    [IO.File]::Move($ciphertextPartial, $ciphertextPath)
    $ciphertextPartial = $null
    $ciphertextSha256 = (Get-FileHash -LiteralPath $ciphertextPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $createdAt = [DateTime]::UtcNow.ToString("o")
    $pendingEvidence = [pscustomobject][ordered]@{
        schema_version = 1
        bundle_id = $bundleId
        deployment_id = $script:SelfHandlerDeploymentId
        created_at = $createdAt
        reason = $Reason
        source_release = $sourceRelease
        schema_fingerprint = $schemaFingerprint
        ciphertext_path = [IO.Path]::GetFullPath($ciphertextPath)
        ciphertext_bytes = [Int64](Get-Item -LiteralPath $ciphertextPath).Length
        ciphertext_sha256 = $ciphertextSha256
        plaintext_validated = $true
        encryption_status = "succeeded"
        off_host_reference = $null
    }
    Save-PendingBackupEvidence -Evidence $pendingEvidence | Out-Null
    $result = [pscustomobject][ordered]@{
        CiphertextPath = [IO.Path]::GetFullPath($ciphertextPath)
        Sha256 = $ciphertextSha256
        BundleId = $bundleId
    }
}
finally {
    if ($privateHelper) {
        & docker rm --force $privateHelper *> $null
    }
    if ($ciphertextPartial -and [IO.File]::Exists($ciphertextPartial)) {
        [IO.File]::Delete($ciphertextPartial)
    }
    if (Test-Path -LiteralPath $plainRoot) {
        Remove-SensitivePath -Path $plainRoot
    }
    if ($lock) {
        Exit-SelfHandlerProductionLock -Lock $lock
    }
}

if ($null -eq $result) {
    throw "Backup did not produce encrypted recovery evidence."
}
Write-Output ($result | ConvertTo-Json -Compress)
