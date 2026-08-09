[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseManifestPath,

    [Parameter(Mandatory = $true)]
    [ValidateLength(1, 512)]
    [string]$CompletionBackupReference,

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
. (Join-Path $PSScriptRoot "configure-private-route.ps1")

function Get-FinalizerCandidate {
    param([Parameter(Mandatory = $true)][psobject]$Manifest)

    return [pscustomobject][ordered]@{
        source_revision = [string]$Manifest.source_revision
        web_digest = [string]$Manifest.web_image.digest
        app_digest = [string]$Manifest.app_image.digest
    }
}

function Assert-FinalizerImageRevision {
    param(
        [Parameter(Mandatory = $true)][string]$Reference,
        [Parameter(Mandatory = $true)][string]$ExpectedRevision
    )

    $inspection = @(& docker image inspect $Reference 2>$null | ConvertFrom-Json)[0]
    if ($LASTEXITCODE -ne 0 -or [string]$inspection.Config.Labels.'org.opencontainers.image.revision' -ne $ExpectedRevision) {
        throw "finalization_image_mismatch"
    }
}

function Assert-FinalizerRuntime {
    param([Parameter(Mandatory = $true)][psobject]$Candidate)

    $inspections = @{}
    foreach ($service in @("web", "app", "db")) {
        $container = Get-SelfHandlerContainerId -Service $service -RunningOnly
        $inspection = Get-DockerInspection -ContainerId $container
        $inspections[$service] = $inspection
        if ([string]$inspection.Config.User -in @("", "0", "root") -or
            -not [bool]$inspection.HostConfig.ReadonlyRootfs -or
            @($inspection.HostConfig.CapDrop) -notcontains "ALL" -or
            -not (Test-SelfHandlerNoNewPrivileges -Inspection $inspection) -or
            -not (Test-SelfHandlerMountContract -Service $service -Inspection $inspection) -or
            [int64]$inspection.HostConfig.Memory -le 0 -or
            [int64]$inspection.HostConfig.NanoCpus -le 0 -or
            [int64]$inspection.HostConfig.PidsLimit -le 0) {
            throw "finalization_runtime_mismatch"
        }
        foreach ($mount in @($inspection.Mounts)) {
            if ([string]$mount.Destination -eq "/var/run/docker.sock") {
                throw "finalization_runtime_mismatch"
            }
        }
    }
    foreach ($service in @("web", "app")) {
        $digestField = "${service}_digest"
        $expected = Get-ImageReference -Service $service -Digest ([string]$Candidate.$digestField)
        if ([string]$inspections[$service].Config.Image -ne $expected) {
            throw "finalization_runtime_mismatch"
        }
    }
    $webPort = $inspections["web"].NetworkSettings.Ports.'8080/tcp'
    if (@($webPort).Count -ne 1 -or [string]$webPort[0].HostIp -ne "127.0.0.1" -or [int]$webPort[0].HostPort -ne 18080 -or
        $inspections["app"].NetworkSettings.Ports.'9000/tcp' -or
        $inspections["db"].NetworkSettings.Ports.'3306/tcp') {
        throw "finalization_runtime_mismatch"
    }
    $webNetworks = @($inspections["web"].NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
    $appNetworks = @($inspections["app"].NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
    $dbNetworks = @($inspections["db"].NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
    if (($webNetworks -join ",") -ne $script:SelfHandlerAppNetwork -or
        ($appNetworks -join ",") -ne (($script:SelfHandlerAppNetwork, $script:SelfHandlerDataNetwork | Sort-Object) -join ",") -or
        ($dbNetworks -join ",") -ne $script:SelfHandlerDataNetwork) {
        throw "finalization_runtime_mismatch"
    }
}

function Assert-FinalizerHealth {
    param([Parameter(Mandatory = $true)][string]$ExpectedRevision)

    $local = Test-SelfHandlerReadiness -Scope Local -ExpectedRevision $ExpectedRevision
    if ([string]$local.status -ne "healthy") {
        throw "finalization_health_failed"
    }
    Invoke-ConfigureSelfHandlerPrivateRoute -Mode Verify -LockAlreadyHeld | Out-Null
    $private = Test-SelfHandlerReadiness -Scope Private -ExpectedRevision $ExpectedRevision -TimeoutSeconds 20
    if ([string]$private.status -ne "healthy") {
        throw "finalization_health_failed"
    }
    $arguments = @(
        "-NoLogo", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
        "-File", (Join-Path $PSScriptRoot "auth-smoke.ps1"),
        "-ExpectedRevision", $ExpectedRevision
    )
    & powershell @arguments *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "finalization_authentication_failed"
    }
}

function Assert-QualifiedOperationsPointer {
    param(
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [Parameter(Mandatory = $true)][string]$ManifestSha256,
        [Parameter(Mandatory = $true)][string]$EffectiveAttemptId
    )

    $pointerPath = Join-Path $script:SelfHandlerStateRoot "active-operations.json"
    $qualifiedBundlesRoot = Join-Path $script:SelfHandlerStateRoot "qualified-bundles"
    $qualifiedRoot = Join-Path $qualifiedBundlesRoot ([string]$Manifest.source_revision)
    $expectedBundle = [IO.Path]::GetFullPath((Join-Path $qualifiedRoot "deployment-bundle.zip"))
    $expectedManifest = [IO.Path]::GetFullPath((Join-Path $qualifiedRoot "release-manifest.json"))
    $expectedTrust = [IO.Path]::GetFullPath((Join-Path $qualifiedRoot "trust-metadata.json"))
    Assert-TrustedIntegrityPath -Path $pointerPath -Type file -RequireProtectedAcl | Out-Null
    Assert-TrustedIntegrityPath -Path $qualifiedBundlesRoot -Type directory -RequireProtectedAcl | Out-Null
    Assert-TrustedIntegrityPath -Path $qualifiedRoot -Type directory -RequireProtectedAcl | Out-Null
    $pointer = Read-JsonFile -Path $pointerPath
    $pointerFields = @($pointer.PSObject.Properties.Name | Sort-Object)
    $expectedPointerFields = @(
        "actor", "attempt_id", "bundle_path", "bundle_sha256", "installed_at",
        "manifest_path", "manifest_sha256", "schema_version", "source_revision",
        "trust_metadata_path", "trust_metadata_sha256", "workflow_ref",
        "workflow_repository", "workflow_run_attempt", "workflow_run_id", "workflow_sha"
    ) | Sort-Object
    if (($pointerFields -join ",") -ne ($expectedPointerFields -join ",") -or
        [int]$pointer.schema_version -ne 2 -or
        [string]$pointer.source_revision -ne [string]$Manifest.source_revision -or
        [string]$pointer.bundle_sha256 -ne [string]$Manifest.deployment_bundle.sha256 -or
        [string]$pointer.manifest_sha256 -ne $ManifestSha256 -or
        [string]$pointer.attempt_id -ne $EffectiveAttemptId -or
        [string]$pointer.actor -ne $Actor -or
        [string]$pointer.workflow_repository -ne "PanyaPrimal/selfhandler-ops" -or
        [string]$pointer.workflow_repository -ne [string]$Manifest.workflow_identity.repository -or
        [string]$pointer.workflow_ref -ne [string]$Manifest.workflow_identity.workflow_ref -or
        [string]$pointer.workflow_sha -notmatch '^[0-9a-f]{40}$' -or
        [int64]$pointer.workflow_run_id -ne [int64]$Manifest.workflow_identity.run_id -or
        [int]$pointer.workflow_run_attempt -ne [int]$Manifest.workflow_identity.run_attempt -or
        [string]$pointer.installed_at -notmatch '^\d{4}-\d{2}-\d{2}T' -or
        [IO.Path]::GetFullPath([string]$pointer.bundle_path) -ne $expectedBundle -or
        [IO.Path]::GetFullPath([string]$pointer.manifest_path) -ne $expectedManifest -or
        [IO.Path]::GetFullPath([string]$pointer.trust_metadata_path) -ne $expectedTrust -or
        [string]$pointer.trust_metadata_sha256 -notmatch '^[0-9a-f]{64}$') {
        throw "operations_pointer_invalid"
    }
    foreach ($path in @($expectedBundle, $expectedManifest, $expectedTrust)) {
        Assert-TrustedIntegrityPath -Path $path -Type file -RequireProtectedAcl | Out-Null
    }
    $bundle = Get-Item -LiteralPath $expectedBundle
    if ([int64]$bundle.Length -ne [int64]$Manifest.deployment_bundle.bytes -or
        (Get-FileHash -LiteralPath $expectedBundle -Algorithm SHA256).Hash.ToLowerInvariant() -ne [string]$Manifest.deployment_bundle.sha256 -or
        (Get-FileHash -LiteralPath $expectedManifest -Algorithm SHA256).Hash.ToLowerInvariant() -ne $ManifestSha256 -or
        (Get-FileHash -LiteralPath $expectedTrust -Algorithm SHA256).Hash.ToLowerInvariant() -ne [string]$pointer.trust_metadata_sha256) {
        throw "operations_pointer_invalid"
    }
    $installedManifest = Read-ValidatedReleaseManifest -Path $expectedManifest
    if ([string]$installedManifest.source_revision -ne [string]$Manifest.source_revision -or
        [string]$installedManifest.web_image.digest -ne [string]$Manifest.web_image.digest -or
        [string]$installedManifest.app_image.digest -ne [string]$Manifest.app_image.digest) {
        throw "operations_pointer_invalid"
    }
    $trust = Read-JsonFile -Path $expectedTrust
    $trustFields = @($trust.PSObject.Properties.Name | Sort-Object)
    $expectedTrustFields = @(
        "actor", "attempt_id", "bundle_sha256", "deployment_id", "manifest_sha256",
        "schema_version", "source_revision", "workflow_ref", "workflow_repository",
        "workflow_run_attempt", "workflow_run_id", "workflow_sha"
    ) | Sort-Object
    if (($trustFields -join ",") -ne ($expectedTrustFields -join ",") -or
        [int]$trust.schema_version -ne 1 -or
        [string]$trust.deployment_id -ne $script:SelfHandlerDeploymentId -or
        [string]$trust.source_revision -ne [string]$Manifest.source_revision -or
        [string]$trust.attempt_id -ne $EffectiveAttemptId -or
        [string]$trust.actor -ne $Actor -or
        [string]$trust.manifest_sha256 -ne $ManifestSha256 -or
        [string]$trust.bundle_sha256 -ne [string]$Manifest.deployment_bundle.sha256 -or
        [string]$trust.workflow_repository -ne [string]$Manifest.workflow_identity.repository -or
        [string]$trust.workflow_ref -ne [string]$Manifest.workflow_identity.workflow_ref -or
        [string]$trust.workflow_sha -ne [string]$pointer.workflow_sha -or
        [int64]$trust.workflow_run_id -ne [int64]$Manifest.workflow_identity.run_id -or
        [int]$trust.workflow_run_attempt -ne [int]$Manifest.workflow_identity.run_attempt) {
        throw "operations_pointer_invalid"
    }
}

function Resolve-FinalizerAttemptId {
    param(
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [Parameter(Mandatory = $true)][string]$ManifestSha256,
        [Parameter(Mandatory = $true)][string]$RequestedAttemptId
    )

    $allPending = @(Get-UnfinishedPendingReleaseRecords)
    if ($allPending.Count -gt 1) { throw "pending_release_invalid" }
    if (($allPending.Count -eq 1 -and [string]$allPending[0].attempt_id -eq $RequestedAttemptId) -or
        $null -ne (Get-ReleaseAttemptRecord -AttemptId $RequestedAttemptId -AllowMissing)) {
        return $RequestedAttemptId
    }
    $pendingMatches = @($allPending | Where-Object {
        [string]$_.source_revision -eq [string]$Manifest.source_revision -and
        [string]$_.web_digest -eq [string]$Manifest.web_image.digest -and
        [string]$_.app_digest -eq [string]$Manifest.app_image.digest -and
        [string]$_.manifest_sha256 -eq $ManifestSha256 -and
        [string]$_.bundle_sha256 -eq [string]$Manifest.deployment_bundle.sha256 -and
        [string]$_.actor -eq $Actor -and
        [string]$_.workflow_repository -eq [string]$Manifest.workflow_identity.repository -and
        [string]$_.workflow_ref -eq [string]$Manifest.workflow_identity.workflow_ref -and
        [int64]$_.workflow_run_id -eq [int64]$Manifest.workflow_identity.run_id -and
        [int]$_.workflow_run_attempt -eq [int]$Manifest.workflow_identity.run_attempt
    })
    if ($pendingMatches.Count -gt 1) { throw "pending_release_invalid" }
    if ($pendingMatches.Count -eq 1) { return [string]$pendingMatches[0].attempt_id }
    if ($allPending.Count -ne 0) { throw "pending_release_invalid" }

    $recordsRoot = Join-Path $script:SelfHandlerStateRoot "releases"
    $terminalMatches = @()
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
                [string]$record.backup_reference -eq $CompletionBackupReference) {
                $terminalMatches += $record
            }
        }
    }
    if ($terminalMatches.Count -ne 1) { throw "pending_release_invalid" }
    return [string]$terminalMatches[0].attempt_id
}

function Assert-PendingFinalizationRequest {
    param(
        [Parameter(Mandatory = $true)][psobject]$Pending,
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [Parameter(Mandatory = $true)][string]$ManifestSha256,
        [Parameter(Mandatory = $true)][psobject]$Candidate
    )

    if ([int]$Pending.schema_version -ne 1 -or
        [string]$Pending.state -notin @("awaiting_completion", "completion_validated") -or
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
        [string]$Pending.manifest_sha256 -ne $ManifestSha256 -or
        [string]$Pending.schema_before -notmatch '^[0-9a-f]{64}$' -or
        [string]$Pending.schema_after -notmatch '^[0-9a-f]{64}$' -or
        [string]$Pending.predeploy_backup_reference -match '[\r\n]' -or
        [string]$Pending.predeploy_backup_bundle_id -notmatch '^selfhandler-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8,64}$' -or
        -not ($Pending.bootstrap -is [bool]) -or
        ([bool]$Pending.bootstrap) -ne ($null -eq $Pending.previous_release)) {
        throw "pending_release_invalid"
    }
    $actualActive = Get-ActiveRelease
    $activeIsPrevious = Test-ReleaseIdentityEqual -Left $actualActive -Right $Pending.previous_release
    $activeIsCandidate = Test-ReleaseIdentityEqual -Left $actualActive -Right $Candidate
    if (-not $activeIsPrevious -and
        -not ([string]$Pending.state -eq "completion_validated" -and $activeIsCandidate)) {
        throw "pending_release_invalid"
    }
    if ([string]$Pending.state -eq "completion_validated" -and
        ([string]$Pending.completion_backup_bundle_id -notmatch '^selfhandler-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8,64}$' -or
         [string]$Pending.completion_backup_reference -ne $CompletionBackupReference)) {
        throw "pending_release_invalid"
    }
    $predeploy = Read-JsonFile -Path (Join-Path (Join-Path $script:SelfHandlerStateRoot "backups") (([string]$Pending.predeploy_backup_bundle_id) + ".json"))
    if ([string]$predeploy.status -ne "valid" -or
        [string]$predeploy.bundle_id -ne [string]$Pending.predeploy_backup_bundle_id -or
        [string]$predeploy.off_host_reference -ne [string]$Pending.predeploy_backup_reference) {
        throw "pending_release_invalid"
    }
}

function Resolve-CompletionBackup {
    param(
        [Parameter(Mandatory = $true)][psobject]$Pending,
        [Parameter(Mandatory = $true)][psobject]$Candidate
    )

    if ([string]$Pending.state -eq "completion_validated") {
        $stored = Read-JsonFile -Path (Join-Path (Join-Path $script:SelfHandlerStateRoot "backups") (([string]$Pending.completion_backup_bundle_id) + ".json"))
        if ([string]$stored.status -ne "valid" -or
            [string]$stored.off_host_reference -ne $CompletionBackupReference -or
            -not (Test-ReleaseIdentityEqual -Left $stored.source_release -Right $Candidate)) {
            throw "completion_backup_invalid"
        }
        return [pscustomobject]@{ Pending = $Pending; Backup = $stored }
    }

    if (-not [bool]$Pending.bootstrap) {
        if ($CompletionBackupReference -ne [string]$Pending.predeploy_backup_reference) {
            throw "completion_backup_invalid"
        }
        $routineBackup = Read-JsonFile -Path (Join-Path (Join-Path $script:SelfHandlerStateRoot "backups") (([string]$Pending.predeploy_backup_bundle_id) + ".json"))
        $updatedRoutine = Set-PendingReleaseCompletion -Record $Pending -Backup $routineBackup -Reference $CompletionBackupReference
        return [pscustomobject]@{ Pending = $updatedRoutine; Backup = $routineBackup }
    }

    if ($CompletionBackupReference -match '[\r\n]') {
        throw "completion_backup_invalid"
    }
    $matched = @()
    $pendingRoot = Join-Path $script:SelfHandlerStateRoot "pending-backups"
    if (Test-Path -LiteralPath $pendingRoot -PathType Container) {
        foreach ($file in @(Get-ChildItem -LiteralPath $pendingRoot -File -Filter "*.json" | Sort-Object LastWriteTimeUtc -Descending)) {
            $evidence = Read-JsonFile -Path $file.FullName
            if ([string]$evidence.reason -eq "bootstrap" -and
                $CompletionBackupReference.Contains([string]$evidence.bundle_id) -and
                (Test-ReleaseIdentityEqual -Left $evidence.source_release -Right $Candidate)) {
                $matched += $evidence
            }
        }
    }
    if ($matched.Count -ne 1) {
        throw "completion_backup_invalid"
    }
    $selected = $matched[0]
    if (-not (Test-Path -LiteralPath ([string]$selected.ciphertext_path) -PathType Leaf) -or
        (Get-FileHash -LiteralPath ([string]$selected.ciphertext_path) -Algorithm SHA256).Hash.ToLowerInvariant() -ne [string]$selected.ciphertext_sha256) {
        throw "completion_backup_invalid"
    }
    $bound = Bind-OffHostBackupReference -Evidence $selected -Reference $CompletionBackupReference
    $updated = Set-PendingReleaseCompletion -Record $Pending -Backup $bound -Reference $CompletionBackupReference
    return [pscustomobject]@{ Pending = $updated; Backup = $bound }
}

function Restore-FinalizerActivePointer {
    param([AllowNull()][psobject]$PreviousRelease)

    if ($null -eq $PreviousRelease) {
        $activePath = Join-Path $script:SelfHandlerStateRoot "active-release.json"
        if ([IO.File]::Exists($activePath)) { [IO.File]::Delete($activePath) }
    } else {
        Set-ActiveRelease -Release $PreviousRelease
    }
}

$lock = $null
try {
    $lock = Enter-SelfHandlerProductionLock
    Assert-SelfHandlerStateRootIntegrity | Out-Null
    Assert-RequiredCommands -Names @("docker", "tailscale", "powershell")
    $manifest = Read-ValidatedReleaseManifest -Path $ReleaseManifestPath
    $manifestSha256 = (Get-FileHash -LiteralPath $ReleaseManifestPath -Algorithm SHA256).Hash.ToLowerInvariant()
    $candidate = Get-FinalizerCandidate -Manifest $manifest
    $AttemptId = Resolve-FinalizerAttemptId -Manifest $manifest -ManifestSha256 $manifestSha256 -RequestedAttemptId $AttemptId
    Assert-QualifiedOperationsPointer -Manifest $manifest -ManifestSha256 $manifestSha256 -EffectiveAttemptId $AttemptId

    $terminal = Get-ReleaseAttemptRecord -AttemptId $AttemptId -AllowMissing
    if ($null -ne $terminal) {
        if ([string]$terminal.outcome -ne "succeeded" -or
            [string]$terminal.actor -ne $Actor -or
            [string]$terminal.source_revision -ne [string]$candidate.source_revision -or
            [string]$terminal.web_digest -ne [string]$candidate.web_digest -or
            [string]$terminal.app_digest -ne [string]$candidate.app_digest -or
            [string]$terminal.backup_reference -ne $CompletionBackupReference -or
            -not (Test-ReleaseIdentityEqual -Left (Get-ActiveRelease) -Right $candidate)) {
            throw "terminal_release_conflict"
        }
        Assert-FinalizerRuntime -Candidate $candidate
        Assert-FinalizerHealth -ExpectedRevision ([string]$candidate.source_revision)
        try { Remove-PendingRelease -AttemptId $AttemptId } catch {
            Write-SafeOperationMessage -Code "release.pending_cleanup_degraded" -Detail "Terminal evidence remains authoritative."
        }
        Write-Output ($terminal | ConvertTo-Json -Depth 20 -Compress)
        return
    }

    $pending = Get-PendingRelease -AttemptId $AttemptId
    Assert-PendingFinalizationRequest -Pending $pending -Manifest $manifest -ManifestSha256 $manifestSha256 -Candidate $candidate
    foreach ($service in @("web", "app")) {
        $digestField = "${service}_digest"
        $reference = Get-ImageReference -Service $service -Digest ([string]$candidate.$digestField)
        Assert-FinalizerImageRevision -Reference $reference -ExpectedRevision ([string]$candidate.source_revision)
    }
    Assert-FinalizerRuntime -Candidate $candidate
    Assert-FinalizerHealth -ExpectedRevision ([string]$candidate.source_revision)
    $schemaAfter = Get-SelfHandlerSchemaFingerprint
    if ($schemaAfter -ne [string]$pending.schema_after) {
        throw "finalization_schema_mismatch"
    }

    # Bootstrap cannot become active or terminally succeeded until the first
    # authenticated candidate backup has an immutable off-host reference.
    $completion = Resolve-CompletionBackup -Pending $pending -Candidate $candidate
    $pending = $completion.Pending
    $completionBackup = $completion.Backup
    if ([string]$completionBackup.status -ne "valid" -or
        [string]$completionBackup.off_host_reference -ne $CompletionBackupReference) {
        throw "completion_backup_invalid"
    }
    $checks = @{}
    foreach ($property in @($pending.checks.PSObject.Properties)) {
        $checks[$property.Name] = $property.Value
    }
    $checks.operations_bundle = "passed"
    $checks.completion_backup = "passed"

    Set-ActiveRelease -Release $candidate
    try {
        $record = Complete-SelfHandlerReleaseRecord `
            -Manifest $manifest `
            -PreviousRelease $pending.previous_release `
            -SchemaBefore ([string]$pending.schema_before) `
            -SchemaAfter $schemaAfter `
            -ValidatedBackupReference $CompletionBackupReference `
            -StartedAt ([string]$pending.started_at) `
            -Outcome "succeeded" `
            -RestoredRelease $null `
            -FailureCode $null `
            -Checks ([pscustomobject]$checks) `
            -Actor $Actor `
            -AttemptId $AttemptId
    }
    catch {
        Restore-FinalizerActivePointer -PreviousRelease $pending.previous_release
        throw
    }
    try { Remove-PendingRelease -AttemptId $AttemptId } catch {
        Write-SafeOperationMessage -Code "release.pending_cleanup_degraded" -Detail "Terminal evidence remains authoritative."
    }
    Write-Output ($record | ConvertTo-Json -Depth 20 -Compress)
}
finally {
    if ($lock) {
        Exit-SelfHandlerProductionLock -Lock $lock
    }
}
