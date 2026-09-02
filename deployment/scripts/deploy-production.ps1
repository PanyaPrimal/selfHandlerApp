[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseManifestPath,

    [Parameter(Mandatory = $true)]
    [ValidateLength(1, 512)]
    [string]$BackupReference,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9_.-]{1,100}$')]
    [string]$Actor,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[A-Za-z0-9._-]{8,128}$')]
    [string]$AttemptId
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "shared.ps1")

$script:TerminalOutcomes = @("succeeded", "rejected", "failed_before_replace", "rolled_back", "recovery_required")
$script:StableFailureCodes = @(
    "dependency_unavailable",
    "capacity_insufficient",
    "local_port_conflict",
    "duplicate_release",
    "pending_release_invalid",
    "revision_mismatch",
    "quality_evidence_failed",
    "current_health_failed",
    "backup_not_off_host",
    "migration_failed",
    "replacement_failed",
    "rollback_failed",
    "preflight_failed"
)

function Set-CandidateEnvironment {
    param([Parameter(Mandatory = $true)][psobject]$Manifest)

    $env:SELFHANDLER_WEB_IMAGE = Get-ImageReference -Service web -Digest ([string]$Manifest.web_image.digest)
    $env:SELFHANDLER_APP_IMAGE = Get-ImageReference -Service app -Digest ([string]$Manifest.app_image.digest)
    $env:APP_RELEASE_SHA = [string]$Manifest.source_revision
}

function Set-PreviousEnvironment {
    param([Parameter(Mandatory = $true)][psobject]$PreviousRelease)

    $env:SELFHANDLER_WEB_IMAGE = Get-ImageReference -Service web -Digest ([string]$PreviousRelease.web_digest)
    $env:SELFHANDLER_APP_IMAGE = Get-ImageReference -Service app -Digest ([string]$PreviousRelease.app_digest)
    $env:APP_RELEASE_SHA = [string]$PreviousRelease.source_revision
}

function Restore-PreviousActiveRelease {
    param([AllowNull()][psobject]$PreviousRelease)

    if ($null -eq $PreviousRelease) {
        $activePath = Join-Path $script:SelfHandlerStateRoot "active-release.json"
        if ([IO.File]::Exists($activePath)) {
            [IO.File]::Delete($activePath)
        }
        return
    }
    Set-ActiveRelease -Release $PreviousRelease
}

function Assert-ImageRevision {
    param(
        [Parameter(Mandatory = $true)][string]$Reference,
        [Parameter(Mandatory = $true)][string]$ExpectedRevision
    )

    $inspection = @(& docker image inspect $Reference | ConvertFrom-Json)[0]
    if ($LASTEXITCODE -ne 0 -or $inspection.Config.Labels.'org.opencontainers.image.revision' -ne $ExpectedRevision) {
        throw "revision_mismatch"
    }
}

function Assert-ExpectedVolumes {
    foreach ($volume in @($script:SelfHandlerDatabaseVolume, $script:SelfHandlerPrivateFilesVolume)) {
        & docker volume inspect $volume *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "current_health_failed"
        }
    }
    $dealFlowMatches = @(& docker volume ls --filter "name=dealflow_" --format "{{.Name}}")
    if ($LASTEXITCODE -ne 0) {
        throw "current_health_failed"
    }
    foreach ($volume in @($script:SelfHandlerDatabaseVolume, $script:SelfHandlerPrivateFilesVolume)) {
        if ($dealFlowMatches -contains $volume) {
            throw "current_health_failed"
        }
    }
}

function Assert-ActualReleasePair {
    param([Parameter(Mandatory = $true)][psobject]$Release)

    foreach ($service in @("web", "app")) {
        $container = Get-SelfHandlerContainerId -Service $service -RunningOnly
        $inspection = Get-DockerInspection -ContainerId $container
        $digestName = "${service}_digest"
        $expected = Get-ImageReference -Service $service -Digest ([string]$Release.$digestName)
        if ([string]$inspection.Config.Image -ne $expected) {
            throw "replacement_failed"
        }
    }
}

function Assert-RuntimeIsolation {
    $inspections = @{}
    foreach ($service in @("web", "app", "db")) {
        $container = Get-SelfHandlerContainerId -Service $service -RunningOnly
        $inspections[$service] = Get-DockerInspection -ContainerId $container
    }
    foreach ($service in @("web", "app", "db")) {
        $inspection = $inspections[$service]
        if ([string]$inspection.Config.User -in @("", "0", "root")) {
            throw "replacement_failed"
        }
        if (-not $inspection.HostConfig.ReadonlyRootfs) {
            throw "replacement_failed"
        }
        if (@($inspection.HostConfig.CapDrop) -notcontains "ALL") {
            throw "replacement_failed"
        }
        if (-not (Test-SelfHandlerNoNewPrivileges -Inspection $inspection) -or
            -not (Test-SelfHandlerMountContract -Service $service -Inspection $inspection)) {
            throw "replacement_failed"
        }
        if (
            [Int64]$inspection.HostConfig.Memory -le 0 -or
            [Int64]$inspection.HostConfig.NanoCpus -le 0 -or
            [Int64]$inspection.HostConfig.PidsLimit -le 0
        ) {
            throw "replacement_failed"
        }
        foreach ($mount in @($inspection.Mounts)) {
            if ([string]$mount.Destination -eq "/var/run/docker.sock") {
                throw "replacement_failed"
            }
        }
    }
    $webPort = $inspections["web"].NetworkSettings.Ports.'8080/tcp'
    if (@($webPort).Count -ne 1 -or $webPort[0].HostIp -ne "127.0.0.1" -or [int]$webPort[0].HostPort -ne 18080) {
        throw "replacement_failed"
    }
    if ($inspections["app"].NetworkSettings.Ports.'9000/tcp') {
        throw "replacement_failed"
    }
    if ($inspections["db"].NetworkSettings.Ports.'3306/tcp') {
        throw "replacement_failed"
    }
    $webNetworks = @($inspections["web"].NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
    $appNetworks = @($inspections["app"].NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
    $dbNetworks = @($inspections["db"].NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
    if (($webNetworks -join ",") -ne $script:SelfHandlerAppNetwork) {
        throw "replacement_failed"
    }
    if (($appNetworks -join ",") -ne (($script:SelfHandlerAppNetwork, $script:SelfHandlerDataNetwork | Sort-Object) -join ",")) {
        throw "replacement_failed"
    }
    if (($dbNetworks -join ",") -ne $script:SelfHandlerDataNetwork) {
        throw "replacement_failed"
    }
}

function Test-ReleaseHealth {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$ExpectedRevision,
        [ValidateRange(1, 900)][int]$TimeoutSeconds = 600,
        [switch]$Bootstrap
    )

    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
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
    $public = Test-SelfHandlerReadiness -Scope Public -ExpectedRevision $ExpectedRevision -TimeoutSeconds 20
    if ($public.status -ne "healthy") {
        return $false
    }
    try {
        $arguments = @("-NoLogo", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", (Join-Path $PSScriptRoot "auth-smoke.ps1"), "-ExpectedRevision", $ExpectedRevision)
        if ($Bootstrap) {
            # A host interruption can occur after the probe account was created
            # but before the deployment journal reached awaiting_completion.
            # First try the normal login path; register only when it is absent.
            & powershell @arguments *> $null
            if ($LASTEXITCODE -eq 0) {
                return $true
            }
            $arguments += "-Bootstrap"
        }
        & powershell @arguments *> $null
        return $LASTEXITCODE -eq 0
    }
    catch {
        return $false
    }
}

function Complete-ReleaseRecord {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [AllowNull()][psobject]$PreviousRelease,
        [Parameter(Mandatory = $true)][string]$SchemaBefore,
        [Parameter(Mandatory = $true)][string]$SchemaAfter,
        [AllowNull()][string]$ValidatedBackupReference,
        [Parameter(Mandatory = $true)][string]$StartedAt,
        [Parameter(Mandatory = $true)][ValidateSet("succeeded", "rejected", "failed_before_replace", "rolled_back", "recovery_required")][string]$Outcome,
        [AllowNull()][psobject]$RestoredRelease,
        [AllowNull()][string]$FailureCode,
        [Parameter(Mandatory = $true)][hashtable]$Checks
    )

    return Complete-SelfHandlerReleaseRecord `
        -Manifest $Manifest `
        -PreviousRelease $PreviousRelease `
        -SchemaBefore $SchemaBefore `
        -SchemaAfter $SchemaAfter `
        -ValidatedBackupReference $ValidatedBackupReference `
        -StartedAt $StartedAt `
        -Outcome $Outcome `
        -RestoredRelease $RestoredRelease `
        -FailureCode $FailureCode `
        -Checks ([pscustomobject]$Checks) `
        -Actor $Actor `
        -AttemptId $AttemptId
}

function Assert-PendingReleaseMatchesRequest {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Pending,
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [Parameter(Mandatory = $true)][string]$ManifestPath,
        [Parameter(Mandatory = $true)][string]$OffHostReference
    )

    $manifestSha256 = (Get-FileHash -LiteralPath $ManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ([int]$Pending.schema_version -ne 1 -or
        [string]$Pending.state -notin @("deploying", "awaiting_completion", "completion_validated") -or
        [string]$Pending.attempt_id -ne $AttemptId -or
        [string]$Pending.deployment_id -ne $script:SelfHandlerDeploymentId -or
        [string]$Pending.actor -ne $Actor -or
        [string]$Pending.source_revision -ne [string]$Manifest.source_revision -or
        [string]$Pending.web_digest -ne [string]$Manifest.web_image.digest -or
        [string]$Pending.app_digest -ne [string]$Manifest.app_image.digest -or
        [string]$Pending.workflow_repository -ne [string]$Manifest.workflow_identity.repository -or
        [string]$Pending.workflow_ref -ne [string]$Manifest.workflow_identity.workflow_ref -or
        [string]$Pending.workflow_event -ne [string]$Manifest.workflow_identity.event -or
        [int64]$Pending.workflow_run_id -ne [int64]$Manifest.workflow_identity.run_id -or
        [int]$Pending.workflow_run_attempt -ne [int]$Manifest.workflow_identity.run_attempt -or
        [string]$Pending.bundle_sha256 -ne [string]$Manifest.deployment_bundle.sha256 -or
        [string]$Pending.manifest_sha256 -ne $manifestSha256 -or
        [string]$Pending.predeploy_backup_reference -ne $OffHostReference -or
        [string]$Pending.predeploy_backup_bundle_id -notmatch '^selfhandler-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8,64}$' -or
        -not ($Pending.bootstrap -is [bool])) {
        throw "pending_release_invalid"
    }
    $actualActive = Get-ActiveRelease
    $candidate = [pscustomobject]@{
        source_revision = [string]$Pending.source_revision
        web_digest = [string]$Pending.web_digest
        app_digest = [string]$Pending.app_digest
    }
    $activeIsPrevious = Test-ReleaseIdentityEqual -Left $Pending.previous_release -Right $actualActive
    $activeIsCandidate = Test-ReleaseIdentityEqual -Left $candidate -Right $actualActive
    if (-not $activeIsPrevious -and
        -not ([string]$Pending.state -eq "completion_validated" -and $activeIsCandidate)) {
        throw "pending_release_invalid"
    }
    if (([bool]$Pending.bootstrap) -ne ($null -eq $Pending.previous_release)) {
        throw "pending_release_invalid"
    }
    $backup = Read-JsonFile -Path (Join-Path (Join-Path $script:SelfHandlerStateRoot "backups") (([string]$Pending.predeploy_backup_bundle_id) + ".json"))
    if ([string]$backup.status -ne "valid" -or
        [string]$backup.off_host_reference -ne $OffHostReference -or
        [string]$backup.bundle_id -ne [string]$Pending.predeploy_backup_bundle_id) {
        throw "pending_release_invalid"
    }
    return $backup
}

function Resolve-DeploymentAttemptId {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [Parameter(Mandatory = $true)][string]$ManifestPath,
        [Parameter(Mandatory = $true)][string]$OffHostReference,
        [Parameter(Mandatory = $true)][string]$RequestedAttemptId
    )

    $allPending = @(Get-UnfinishedPendingReleaseRecords)
    if ($allPending.Count -gt 1) { throw "pending_release_invalid" }
    if ($allPending.Count -eq 1 -and [string]$allPending[0].attempt_id -eq $RequestedAttemptId) {
        return $RequestedAttemptId
    }
    $manifestSha256 = (Get-FileHash -LiteralPath $ManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $matches = @($allPending | Where-Object {
        [string]$_.source_revision -eq [string]$Manifest.source_revision -and
        [string]$_.web_digest -eq [string]$Manifest.web_image.digest -and
        [string]$_.app_digest -eq [string]$Manifest.app_image.digest -and
        [string]$_.manifest_sha256 -eq $manifestSha256 -and
        [string]$_.bundle_sha256 -eq [string]$Manifest.deployment_bundle.sha256 -and
        [string]$_.predeploy_backup_reference -eq $OffHostReference -and
        [string]$_.actor -eq $Actor -and
        [string]$_.workflow_repository -eq [string]$Manifest.workflow_identity.repository -and
        [string]$_.workflow_ref -eq [string]$Manifest.workflow_identity.workflow_ref -and
        [string]$_.workflow_event -eq [string]$Manifest.workflow_identity.event -and
        [int64]$_.workflow_run_id -eq [int64]$Manifest.workflow_identity.run_id -and
        [int]$_.workflow_run_attempt -eq [int]$Manifest.workflow_identity.run_attempt
    })
    if ($matches.Count -gt 1) {
        throw "pending_release_invalid"
    }
    if ($matches.Count -eq 1) {
        return [string]$matches[0].attempt_id
    }
    if ($allPending.Count -ne 0) {
        throw "pending_release_invalid"
    }
    $terminalMatches = @()
    $recordsRoot = Join-Path $script:SelfHandlerStateRoot "releases"
    if (Test-Path -LiteralPath $recordsRoot -PathType Container) {
        Assert-TrustedIntegrityPath -Path $recordsRoot -Type directory -RequireProtectedAcl | Out-Null
        foreach ($file in @(Get-ChildItem -LiteralPath $recordsRoot -File -Filter "*.json")) {
            Assert-TrustedIntegrityPath -Path $file.FullName -Type file -RequireProtectedAcl | Out-Null
            $record = Read-JsonFile -Path $file.FullName
            if ([string]$record.outcome -eq "succeeded" -and
                [string]$record.source_revision -eq [string]$Manifest.source_revision -and
                [string]$record.web_digest -eq [string]$Manifest.web_image.digest -and
                [string]$record.app_digest -eq [string]$Manifest.app_image.digest -and
                [string]$record.actor -eq $Actor -and
                [string]$record.backup_reference -eq $OffHostReference) {
                $terminalMatches += $record
            }
        }
    }
    if ($terminalMatches.Count -gt 1) { throw "pending_release_invalid" }
    if ($terminalMatches.Count -eq 1) { return [string]$terminalMatches[0].attempt_id }
    $expectedAttemptId = "gh-$([int64]$Manifest.workflow_identity.run_id)-$([int]$Manifest.workflow_identity.run_attempt)"
    if ($RequestedAttemptId -ne $expectedAttemptId) {
        throw "pending_release_invalid"
    }
    return $RequestedAttemptId
}

function Invoke-DeploymentPreflight {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$ManifestPath,
        [Parameter(Mandatory = $true)][string]$OffHostReference
    )

    Assert-RequiredCommands -Names @("docker", "powershell")
    Assert-SelfHandlerCapacity | Out-Null
    if (-not (Test-Path -LiteralPath $script:SelfHandlerEnvironmentPath -PathType Leaf)) {
        throw "dependency_unavailable"
    }
    $manifest = Read-ValidatedReleaseManifest -Path $ManifestPath
    $pending = Get-PendingRelease -AttemptId $AttemptId -AllowMissing
    if ($null -ne $pending) {
        if ($null -ne (Get-ReleaseAttemptRecord -AttemptId $AttemptId -AllowMissing)) {
            throw "duplicate_release"
        }
        $resumeBackup = Assert-PendingReleaseMatchesRequest -Pending $pending -Manifest $manifest -ManifestPath $ManifestPath -OffHostReference $OffHostReference
        Set-CandidateEnvironment -Manifest $manifest
        Invoke-SelfHandlerCompose config --quiet
        foreach ($image in @(
            (Get-ImageReference -Service web -Digest ([string]$manifest.web_image.digest)),
            (Get-ImageReference -Service app -Digest ([string]$manifest.app_image.digest))
        )) {
            & docker image inspect $image *> $null
            if ($LASTEXITCODE -ne 0) { throw "dependency_unavailable" }
            Assert-ImageRevision -Reference $image -ExpectedRevision ([string]$manifest.source_revision)
        }
        $candidate = [pscustomobject][ordered]@{
            source_revision = [string]$manifest.source_revision
            web_digest = [string]$manifest.web_image.digest
            app_digest = [string]$manifest.app_image.digest
        }
        $readyForCompletion = [string]$pending.state -in @("awaiting_completion", "completion_validated")
        if ($readyForCompletion) {
            Assert-ActualReleasePair -Release $candidate
            Assert-RuntimeIsolation
            if (-not (Test-ReleaseHealth -ExpectedRevision $candidate.source_revision)) {
                throw "current_health_failed"
            }
            $currentSchema = Get-SelfHandlerSchemaFingerprint
            if ([string]$pending.schema_after -ne $currentSchema) {
                throw "pending_release_invalid"
            }
        }
        return [pscustomobject][ordered]@{
            Manifest = $manifest
            PreviousRelease = $pending.previous_release
            Bootstrap = [bool]$pending.bootstrap
            Backup = $resumeBackup
            SchemaBefore = [string]$pending.schema_before
            SchemaAfter = [string]$pending.schema_after
            Resume = $true
            ReadyForCompletion = $readyForCompletion
            Pending = $pending
        }
    }
    Assert-ReleaseIsNew -Manifest $manifest
    Set-CandidateEnvironment -Manifest $manifest
    Invoke-SelfHandlerCompose config --quiet

    $active = Get-ActiveRelease
    $bootstrap = $null -eq $active
    $reason = "predeploy"
    if ($bootstrap) {
        $reason = "bootstrap-baseline"
        # Repeat the bootstrap-baseline bind probe while holding the deployment
        # lock and before migration changes the empty database.
        Assert-BootstrapLoopbackPortAvailable
        $db = Get-SelfHandlerContainerId -Service db -RunningOnly
        if (-not $db) {
            throw "current_health_failed"
        }
        foreach ($service in @("web", "app")) {
            if ($null -ne (Get-SelfHandlerContainerId -Service $service -AllowMissing)) {
                throw "current_health_failed"
            }
        }
    } else {
        Assert-ExpectedVolumes
        Assert-ActualReleasePair -Release $active
        $local = Test-SelfHandlerReadiness -Scope Local -ExpectedRevision ([string]$active.source_revision)
        $public = Test-SelfHandlerReadiness -Scope Public -ExpectedRevision ([string]$active.source_revision) -TimeoutSeconds 20
        if ($local.status -ne "healthy" -or $public.status -ne "healthy") {
            throw "current_health_failed"
        }
    }

    $pendingBackup = Get-LatestPendingBackup -Reason $reason
    if (-not (Test-Path -LiteralPath ([string]$pendingBackup.ciphertext_path) -PathType Leaf)) {
        throw "backup_not_off_host"
    }
    $actualCiphertextHash = (Get-FileHash -LiteralPath ([string]$pendingBackup.ciphertext_path) -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualCiphertextHash -ne [string]$pendingBackup.ciphertext_sha256) {
        throw "backup_not_off_host"
    }
    if ($bootstrap) {
        if ($null -ne $pendingBackup.source_release) {
            throw "backup_not_off_host"
        }
    } else {
        if (
            $pendingBackup.source_release.source_revision -ne $active.source_revision -or
            $pendingBackup.source_release.web_digest -ne $active.web_digest -or
            $pendingBackup.source_release.app_digest -ne $active.app_digest
        ) {
            throw "backup_not_off_host"
        }
    }
    $completedBackup = Bind-OffHostBackupReference -Evidence $pendingBackup -Reference $OffHostReference

    foreach ($image in @(
        (Get-ImageReference -Service web -Digest ([string]$manifest.web_image.digest)),
        (Get-ImageReference -Service app -Digest ([string]$manifest.app_image.digest))
    )) {
        # The trusted private workflow pre-pulls and verifies both digests, then logs out
        # of GHCR before this public deployment bundle is allowed to execute.
        & docker image inspect $image *> $null
        if ($LASTEXITCODE -ne 0) {
            throw "dependency_unavailable"
        }
        Assert-ImageRevision -Reference $image -ExpectedRevision ([string]$manifest.source_revision)
    }
    $schemaBefore = Get-SelfHandlerSchemaFingerprint -AllowEmpty:$bootstrap
    return [pscustomobject][ordered]@{
        Manifest = $manifest
        PreviousRelease = $active
        Bootstrap = $bootstrap
        Backup = $completedBackup
        SchemaBefore = $schemaBefore
        Resume = $false
        ReadyForCompletion = $false
        Pending = $null
    }
}

function Invoke-CandidateMigration {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject]$Manifest)

    Set-CandidateEnvironment -Manifest $Manifest
    # The one-shot candidate migration is the only production migration invocation.
    Invoke-SelfHandlerCompose run --rm -T --no-deps --pull never app php artisan migrate --force
    return Get-SelfHandlerSchemaFingerprint
}

function Invoke-PairedReplacement {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject]$Manifest)

    Set-CandidateEnvironment -Manifest $Manifest
    Invoke-SelfHandlerCompose up -d --no-build --pull never app web
}

function Invoke-PairedRollback {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject]$PreviousRelease)

    Set-PreviousEnvironment -PreviousRelease $PreviousRelease
    Invoke-SelfHandlerCompose up -d --no-build --pull never app web
}

$startedAt = [DateTime]::UtcNow.ToString("o")
$lock = $null
$context = $null
$manifestForFailure = $null
$schemaBefore = $script:SelfHandlerEmptySchemaFingerprint
$schemaAfter = $script:SelfHandlerEmptySchemaFingerprint
$replacementAttempted = $false
$activePointerChanged = $false
$existingJournal = $null
$checks = @{
    preflight = "not_run"
    backup = "not_run"
    migration = "not_run"
    replacement = "not_run"
    local_readiness = "not_run"
    public_route = "not_run"
    runtime_isolation = "not_run"
    authentication = "not_run"
    operations_bundle = "not_run"
    completion_backup = "not_run"
    rollback = "not_run"
}

try {
    $lock = Enter-SelfHandlerProductionLock
    Assert-SelfHandlerStateRootIntegrity | Out-Null
    $requestManifest = Read-ValidatedReleaseManifest -Path $ReleaseManifestPath
    $AttemptId = Resolve-DeploymentAttemptId -Manifest $requestManifest -ManifestPath $ReleaseManifestPath -OffHostReference $BackupReference -RequestedAttemptId $AttemptId
    $existingJournal = Get-PendingRelease -AttemptId $AttemptId -AllowMissing
    $existingTerminal = Get-ReleaseAttemptRecord -AttemptId $AttemptId -AllowMissing
    if ($null -ne $existingTerminal) {
        if ([string]$existingTerminal.source_revision -ne [string]$requestManifest.source_revision -or
            [string]$existingTerminal.web_digest -ne [string]$requestManifest.web_image.digest -or
            [string]$existingTerminal.app_digest -ne [string]$requestManifest.app_image.digest) {
            throw "pending_release_invalid"
        }
        if ($null -ne $existingJournal) {
            try { Remove-PendingRelease -AttemptId $AttemptId } catch {
                Write-SafeOperationMessage -Code "release.pending_cleanup_degraded" -Detail "Canonical terminal evidence remains authoritative."
            }
        }
        if ([string]$existingTerminal.outcome -eq "succeeded" -and
            [string]$existingTerminal.actor -eq $Actor -and
            [string]$existingTerminal.backup_reference -eq $BackupReference) {
            $terminalCandidate = [pscustomobject][ordered]@{
                source_revision = [string]$existingTerminal.source_revision
                web_digest = [string]$existingTerminal.web_digest
                app_digest = [string]$existingTerminal.app_digest
            }
            if (-not (Test-ReleaseIdentityEqual -Left (Get-ActiveRelease) -Right $terminalCandidate)) {
                throw "pending_release_invalid"
            }
            Set-CandidateEnvironment -Manifest $requestManifest
            foreach ($service in @("web", "app")) {
                $digestField = "${service}_digest"
                $reference = Get-ImageReference -Service $service -Digest ([string]$terminalCandidate.$digestField)
                & docker image inspect $reference *> $null
                if ($LASTEXITCODE -ne 0) { throw "dependency_unavailable" }
                Assert-ImageRevision -Reference $reference -ExpectedRevision ([string]$terminalCandidate.source_revision)
            }
            Assert-ActualReleasePair -Release $terminalCandidate
            Assert-RuntimeIsolation
            if ((Get-SelfHandlerSchemaFingerprint) -ne [string]$existingTerminal.schema_after) {
                throw "pending_release_invalid"
            }
            if (-not (Test-ReleaseHealth -ExpectedRevision ([string]$terminalCandidate.source_revision))) {
                throw "current_health_failed"
            }
            Write-Output ($existingTerminal | ConvertTo-Json -Depth 20 -Compress)
            return
        }
        throw "duplicate_release"
    }
    try {
        $context = Invoke-DeploymentPreflight -ManifestPath $ReleaseManifestPath -OffHostReference $BackupReference
        $manifestForFailure = $context.Manifest
        $schemaBefore = [string]$context.SchemaBefore
        $schemaAfter = $schemaBefore
        $checks.preflight = "passed"
        $checks.backup = "passed"
    }
    catch {
        $failure = [string]$_.Exception.Message
        if ($script:StableFailureCodes -notcontains $failure) {
            $failure = "preflight_failed"
        }
        if ($null -ne $existingJournal) {
            # A durable pre-mutation journal is authoritative for an interrupted
            # attempt. A failed resume check must never be rewritten as a fresh
            # terminal rejection while candidate state may exist.
            throw $failure
        }
        if ($null -eq $manifestForFailure) {
            try {
                $manifestForFailure = Read-ValidatedReleaseManifest -Path $ReleaseManifestPath
            } catch {
                throw $failure
            }
        }
        $checks.preflight = "failed"
        Complete-ReleaseRecord -Manifest $manifestForFailure -PreviousRelease $null -SchemaBefore $schemaBefore -SchemaAfter $schemaAfter -ValidatedBackupReference $null -StartedAt $startedAt -Outcome "rejected" -RestoredRelease $null -FailureCode $failure -Checks $checks | Out-Null
        throw $failure
    }

    if ($context.Resume -and $context.ReadyForCompletion) {
        $checks.preflight = "passed"
        $checks.backup = "passed"
        $checks.migration = "passed"
        $checks.replacement = "passed"
        $checks.local_readiness = "passed"
        $checks.public_route = "passed"
        $checks.runtime_isolation = "passed"
        $checks.authentication = "passed"
        Write-Output ($context.Pending | ConvertTo-Json -Depth 20 -Compress)
        return
    }

    if ($context.Resume) {
        $startedAt = [string]$context.Pending.started_at
        $pendingRecord = $context.Pending
    } else {
        # The journal is durable before the first migration/replacement
        # mutation. Every operation below is safe to replay for this exact
        # immutable candidate and already-bound recovery point.
        $pendingRecord = [pscustomobject][ordered]@{
            schema_version = 1
            state = "deploying"
            attempt_id = $AttemptId
            deployment_id = $script:SelfHandlerDeploymentId
            source_revision = [string]$context.Manifest.source_revision
            web_digest = [string]$context.Manifest.web_image.digest
            app_digest = [string]$context.Manifest.app_image.digest
            previous_release = $context.PreviousRelease
            schema_before = $schemaBefore
            schema_after = $schemaBefore
            predeploy_backup_bundle_id = [string]$context.Backup.bundle_id
            predeploy_backup_reference = [string]$context.Backup.off_host_reference
            actor = $Actor
            started_at = $startedAt
            prepared_at = [DateTime]::UtcNow.ToString("o")
            bootstrap = [bool]$context.Bootstrap
            bundle_sha256 = [string]$context.Manifest.deployment_bundle.sha256
            manifest_sha256 = (Get-FileHash -LiteralPath $ReleaseManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
            workflow_repository = [string]$context.Manifest.workflow_identity.repository
            workflow_ref = [string]$context.Manifest.workflow_identity.workflow_ref
            workflow_event = [string]$context.Manifest.workflow_identity.event
            workflow_run_id = [int64]$context.Manifest.workflow_identity.run_id
            workflow_run_attempt = [int]$context.Manifest.workflow_identity.run_attempt
            checks = [pscustomobject]$checks
        }
        Save-PendingRelease -Record $pendingRecord | Out-Null
    }

    try {
        $schemaAfter = Invoke-CandidateMigration -Manifest $context.Manifest
        $checks.migration = "passed"
        $pendingRecord = Set-PendingReleaseDeploymentState -Record $pendingRecord -State "deploying" -SchemaAfter $schemaAfter -Checks ([pscustomobject]$checks)
    }
    catch {
        $checks.migration = "failed"
        Complete-ReleaseRecord -Manifest $context.Manifest -PreviousRelease $context.PreviousRelease -SchemaBefore $schemaBefore -SchemaAfter $schemaAfter -ValidatedBackupReference ([string]$context.Backup.off_host_reference) -StartedAt $startedAt -Outcome "failed_before_replace" -RestoredRelease $null -FailureCode "migration_failed" -Checks $checks | Out-Null
        try { Remove-PendingRelease -AttemptId $AttemptId } catch {
            Write-SafeOperationMessage -Code "release.pending_cleanup_degraded" -Detail "Canonical terminal evidence remains authoritative."
        }
        throw "migration_failed"
    }

    try {
        $replacementAttempted = $true
        Invoke-PairedReplacement -Manifest $context.Manifest
        $checks.replacement = "passed"
        $candidateRelease = [pscustomobject][ordered]@{
            source_revision = [string]$context.Manifest.source_revision
            web_digest = [string]$context.Manifest.web_image.digest
            app_digest = [string]$context.Manifest.app_image.digest
        }
        if (-not (Test-ReleaseHealth -ExpectedRevision $candidateRelease.source_revision -Bootstrap:$context.Bootstrap)) {
            throw "replacement_failed"
        }
        $checks.local_readiness = "passed"
        $checks.public_route = "passed"
        $checks.authentication = "passed"
        Assert-ActualReleasePair -Release $candidateRelease
        Assert-RuntimeIsolation
        $checks.runtime_isolation = "passed"
        $pendingRecord = Set-PendingReleaseDeploymentState -Record $pendingRecord -State "awaiting_completion" -SchemaAfter $schemaAfter -Checks ([pscustomobject]$checks)
        Write-Output ($pendingRecord | ConvertTo-Json -Depth 20 -Compress)
    }
    catch {
        $checks.replacement = "failed"
        if ($replacementAttempted -and $null -ne $context.PreviousRelease) {
            try {
                Invoke-PairedRollback -PreviousRelease $context.PreviousRelease
                if (-not (Test-ReleaseHealth -ExpectedRevision ([string]$context.PreviousRelease.source_revision))) {
                    throw "rollback_failed"
                }
                Assert-ActualReleasePair -Release $context.PreviousRelease
                Assert-RuntimeIsolation
                Set-ActiveRelease -Release $context.PreviousRelease
                $activePointerChanged = $false
                $checks.rollback = "passed"
                $record = Complete-ReleaseRecord -Manifest $context.Manifest -PreviousRelease $context.PreviousRelease -SchemaBefore $schemaBefore -SchemaAfter $schemaAfter -ValidatedBackupReference ([string]$context.Backup.off_host_reference) -StartedAt $startedAt -Outcome "rolled_back" -RestoredRelease $context.PreviousRelease -FailureCode "replacement_failed" -Checks $checks
                try { Remove-PendingRelease -AttemptId $AttemptId } catch {
                    Write-SafeOperationMessage -Code "release.pending_cleanup_degraded" -Detail "Canonical terminal evidence remains authoritative."
                }
                Write-Output ($record | ConvertTo-Json -Depth 20 -Compress)
                throw "replacement_failed_rolled_back"
            }
            catch {
                if ([string]$_.Exception.Message -eq "replacement_failed_rolled_back") {
                    throw
                }
                $checks.rollback = "failed"
            }
        }
        if ($activePointerChanged) {
            Restore-PreviousActiveRelease -PreviousRelease $context.PreviousRelease
            $activePointerChanged = $false
        }
        $record = Complete-ReleaseRecord -Manifest $context.Manifest -PreviousRelease $context.PreviousRelease -SchemaBefore $schemaBefore -SchemaAfter $schemaAfter -ValidatedBackupReference ([string]$context.Backup.off_host_reference) -StartedAt $startedAt -Outcome "recovery_required" -RestoredRelease $null -FailureCode "rollback_failed" -Checks $checks
        try { Remove-PendingRelease -AttemptId $AttemptId } catch {
            Write-SafeOperationMessage -Code "release.pending_cleanup_degraded" -Detail "Canonical terminal evidence remains authoritative."
        }
        Write-Output ($record | ConvertTo-Json -Depth 20 -Compress)
        throw "rollback_failed"
    }
}
finally {
    if ($lock) {
        Exit-SelfHandlerProductionLock -Lock $lock
    }
}
