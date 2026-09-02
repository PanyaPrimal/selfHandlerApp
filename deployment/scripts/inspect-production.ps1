Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "shared.ps1")

function New-SelfHandlerHealthReport {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$ObservedAt,
        [AllowNull()][psobject]$ActiveRelease,
        [Parameter(Mandatory = $true)][psobject]$LocalReadiness,
        [Parameter(Mandatory = $true)][psobject]$PublicRoute,
        [Parameter(Mandatory = $true)][ValidateSet("healthy", "unhealthy", "absent")][string]$DatabaseStatus,
        [Parameter(Mandatory = $true)][ValidateSet("present", "missing", "unexpected")][string]$DatabaseVolumeStatus,
        [Parameter(Mandatory = $true)][ValidateSet("present", "missing", "unexpected")][string]$PrivateFilesVolumeStatus,
        [Parameter(Mandatory = $true)][psobject]$LatestBackup,
        [Parameter(Mandatory = $true)][hashtable]$RuntimeIsolation,
        [Parameter(Mandatory = $true)][ValidateSet("sufficient", "insufficient", "unknown")][string]$CapacityStatus,
        [ValidateRange(0, 1024)][int]$PendingReleaseCount = 0,
        [bool]$PendingStateInvalid = $false
    )

    $alerts = New-Object System.Collections.Generic.List[string]
    if ($LocalReadiness.status -ne "healthy") { $alerts.Add("local_unhealthy") }
    if ($PublicRoute.status -eq "unreachable") {
        $alerts.Add("public_route_unreachable")
    } elseif ($PublicRoute.status -ne "healthy") {
        $alerts.Add("public_route_unhealthy")
    }
    if ($DatabaseStatus -eq "absent") {
        $alerts.Add("database_absent")
    } elseif ($DatabaseStatus -ne "healthy") {
        $alerts.Add("database_unhealthy")
    }
    if ($DatabaseVolumeStatus -ne "present") { $alerts.Add("database_volume_missing") }
    if ($PrivateFilesVolumeStatus -ne "present") { $alerts.Add("private_files_volume_missing") }
    switch ([string]$LatestBackup.status) {
        "missing" { $alerts.Add("backup_missing") }
        "invalid" { $alerts.Add("backup_invalid") }
        "overdue" { $alerts.Add("backup_overdue") }
    }
    if (@($RuntimeIsolation.Values | Where-Object { $_ -eq "failed" }).Count -gt 0) {
        $alerts.Add("runtime_isolation_failed")
    }
    if ($CapacityStatus -eq "insufficient") {
        $alerts.Add("capacity_insufficient")
    } elseif ($CapacityStatus -eq "unknown") {
        $alerts.Add("capacity_unknown")
    }
    if ($PendingReleaseCount -gt 0) {
        $alerts.Add("deployment_incomplete")
        $alerts.Add("pending_release")
    }
    if ($PendingStateInvalid) {
        $alerts.Add("pending_release_invalid")
    }
    return [pscustomobject][ordered]@{
        schema_version = 1
        deployment_id = $script:SelfHandlerDeploymentId
        observed_at = $ObservedAt
        active_release = $ActiveRelease
        local_readiness = [pscustomobject][ordered]@{
            status = [string]$LocalReadiness.status
            latency_ms = $LocalReadiness.latency_ms
        }
        public_route = [pscustomobject][ordered]@{
            status = [string]$PublicRoute.status
            latency_ms = $PublicRoute.latency_ms
        }
        database = $DatabaseStatus
        persistent_stores = [pscustomobject][ordered]@{
            database_volume = [pscustomobject][ordered]@{
                name = $script:SelfHandlerDatabaseVolume
                status = $DatabaseVolumeStatus
            }
            private_files_volume = [pscustomobject][ordered]@{
                name = $script:SelfHandlerPrivateFilesVolume
                status = $PrivateFilesVolumeStatus
            }
        }
        latest_backup = [pscustomobject][ordered]@{
            status = [string]$LatestBackup.status
            age_hours = $LatestBackup.age_hours
            reference = $LatestBackup.reference
        }
        runtime_isolation = [pscustomobject]$RuntimeIsolation
        capacity = $CapacityStatus
        alerts = @($alerts | Sort-Object -Unique)
    }
}

function Get-VolumeInspectionStatus {
    param([Parameter(Mandatory = $true)][string]$Name)

    & docker volume inspect $Name *> $null
    if ($LASTEXITCODE -eq 0) { return "present" }
    return "missing"
}

function Get-BackupInspection {
    $latest = Read-JsonFile -Path (Join-Path $script:SelfHandlerStateRoot "latest-backup.json") -AllowMissing
    if ($null -eq $latest) {
        return [pscustomobject][ordered]@{ status = "missing"; age_hours = $null; reference = $null }
    }
    try {
        if (
            $latest.status -ne "valid" -or
            [string]$latest.ciphertext_sha256 -notmatch '^[0-9a-f]{64}$' -or
            [String]::IsNullOrWhiteSpace([string]$latest.off_host_reference)
        ) {
            throw "invalid"
        }
        $created = [DateTimeOffset]::Parse([string]$latest.created_at).ToUniversalTime()
        $age = [Math]::Max(0, ([DateTimeOffset]::UtcNow - $created).TotalHours)
        $status = "valid"
        if ($age -gt 24) { $status = "overdue" }
        return [pscustomobject][ordered]@{
            status = $status
            age_hours = [Math]::Round($age, 3)
            reference = [string]$latest.off_host_reference
        }
    }
    catch {
        return [pscustomobject][ordered]@{ status = "invalid"; age_hours = $null; reference = $null }
    }
}

function Get-RuntimeIsolationInspection {
    param([ValidateRange(0, 1024)][int]$PendingReleaseCount = 0)

    $checks = @{
        non_root = "not_applicable"
        read_only = "not_applicable"
        capabilities_dropped = "not_applicable"
        no_new_privileges = "not_applicable"
        expected_mounts = "not_applicable"
        resource_limits = "not_applicable"
        docker_socket_absent = "not_applicable"
        web_loopback_port = "not_applicable"
        internal_ports_unpublished = "not_applicable"
        expected_networks = "not_applicable"
        release_pair_matches = "not_applicable"
    }
    try {
        $inspections = @{}
        foreach ($service in @("web", "app", "db")) {
            $container = Get-SelfHandlerContainerId -Service $service -RunningOnly
            $inspections[$service] = Get-DockerInspection -ContainerId $container
        }
        $checks.non_root = $(if (@($inspections.Values | Where-Object { [string]$_.Config.User -in @("", "0", "root") }).Count -eq 0) { "passed" } else { "failed" })
        $checks.read_only = $(if (@($inspections.Values | Where-Object { -not $_.HostConfig.ReadonlyRootfs }).Count -eq 0) { "passed" } else { "failed" })
        $checks.capabilities_dropped = $(if (@($inspections.Values | Where-Object { @($_.HostConfig.CapDrop) -notcontains "ALL" }).Count -eq 0) { "passed" } else { "failed" })
        $checks.no_new_privileges = $(if (@(@("web", "app", "db") | Where-Object { -not (Test-SelfHandlerNoNewPrivileges -Inspection $inspections[$_]) }).Count -eq 0) { "passed" } else { "failed" })
        $checks.expected_mounts = $(if (@(@("web", "app", "db") | Where-Object { -not (Test-SelfHandlerMountContract -Service $_ -Inspection $inspections[$_]) }).Count -eq 0) { "passed" } else { "failed" })
        $checks.resource_limits = $(if (@($inspections.Values | Where-Object { [Int64]$_.HostConfig.Memory -le 0 -or [Int64]$_.HostConfig.NanoCpus -le 0 -or [Int64]$_.HostConfig.PidsLimit -le 0 }).Count -eq 0) { "passed" } else { "failed" })
        $socketMounts = @($inspections.Values | ForEach-Object { @($_.Mounts) } | Where-Object { [string]$_.Destination -eq "/var/run/docker.sock" })
        $checks.docker_socket_absent = $(if ($socketMounts.Count -eq 0) { "passed" } else { "failed" })
        $webPort = $inspections.web.NetworkSettings.Ports.'8080/tcp'
        $checks.web_loopback_port = $(if (@($webPort).Count -eq 1 -and $webPort[0].HostIp -eq "127.0.0.1" -and [int]$webPort[0].HostPort -eq 18080) { "passed" } else { "failed" })
        $internalPublished = $inspections.app.NetworkSettings.Ports.'9000/tcp' -or $inspections.db.NetworkSettings.Ports.'3306/tcp'
        $checks.internal_ports_unpublished = $(if (-not $internalPublished) { "passed" } else { "failed" })
        $webNetworks = @($inspections.web.NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
        $appNetworks = @($inspections.app.NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
        $dbNetworks = @($inspections.db.NetworkSettings.Networks.psobject.Properties.Name | Sort-Object)
        $expectedApp = @(($script:SelfHandlerAppNetwork, $script:SelfHandlerDataNetwork) | Sort-Object)
        $networkPass = ($webNetworks -join ",") -eq $script:SelfHandlerAppNetwork -and ($appNetworks -join ",") -eq ($expectedApp -join ",") -and ($dbNetworks -join ",") -eq $script:SelfHandlerDataNetwork
        $checks.expected_networks = $(if ($networkPass) { "passed" } else { "failed" })
        $active = Get-ActiveRelease
        if ($null -ne $active) {
            $expectedWeb = Get-ImageReference -Service web -Digest ([string]$active.web_digest)
            $expectedAppImage = Get-ImageReference -Service app -Digest ([string]$active.app_digest)
            $pairPass = [string]$inspections.web.Config.Image -eq $expectedWeb -and [string]$inspections.app.Config.Image -eq $expectedAppImage
            $checks.release_pair_matches = $(if ($pairPass) { "passed" } else { "failed" })
        } elseif ($PendingReleaseCount -gt 0) {
            $checks.release_pair_matches = "failed"
        }
    }
    catch {
        foreach ($key in @($checks.Keys)) {
            if ($checks[$key] -eq "not_applicable") { $checks[$key] = "failed" }
        }
    }
    return $checks
}

function Invoke-SelfHandlerInspection {
    [CmdletBinding()]
    param()

    Assert-SelfHandlerStateRootIntegrity | Out-Null
    $observedAt = [DateTime]::UtcNow.ToString("o")
    $pendingReleaseCount = 0
    $pendingStateInvalid = $false
    try { $pendingReleaseCount = @(Get-PendingReleaseRecords).Count }
    catch {
        $pendingReleaseCount = 1
        $pendingStateInvalid = $true
    }
    $active = Get-ActiveRelease
    $expectedRevision = $null
    if ($null -ne $active) { $expectedRevision = [string]$active.source_revision }
    $local = Test-SelfHandlerReadiness -Scope Local -ExpectedRevision $expectedRevision
    $public = Test-SelfHandlerReadiness -Scope Public -ExpectedRevision $expectedRevision -TimeoutSeconds 20
    $database = "absent"
    try {
        $db = Get-SelfHandlerContainerId -Service db -AllowMissing -RunningOnly
        if ($db) {
            $status = (& docker inspect --format "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}" $db).Trim()
            $database = $(if ($status -eq "healthy") { "healthy" } else { "unhealthy" })
        }
    } catch { $database = "unhealthy" }
    $capacity = "unknown"
    try {
        Assert-SelfHandlerCapacity | Out-Null
        $capacity = "sufficient"
    } catch {
        if ([string]$_.Exception.Message -eq "capacity_insufficient") { $capacity = "insufficient" }
    }
    return New-SelfHandlerHealthReport `
        -ObservedAt $observedAt `
        -ActiveRelease $active `
        -LocalReadiness $local `
        -PublicRoute $public `
        -DatabaseStatus $database `
        -DatabaseVolumeStatus (Get-VolumeInspectionStatus -Name $script:SelfHandlerDatabaseVolume) `
        -PrivateFilesVolumeStatus (Get-VolumeInspectionStatus -Name $script:SelfHandlerPrivateFilesVolume) `
        -LatestBackup (Get-BackupInspection) `
        -RuntimeIsolation (Get-RuntimeIsolationInspection -PendingReleaseCount $pendingReleaseCount) `
        -CapacityStatus $capacity `
        -PendingReleaseCount $pendingReleaseCount `
        -PendingStateInvalid $pendingStateInvalid
}

if ($MyInvocation.InvocationName -ne ".") {
    $report = Invoke-SelfHandlerInspection
    Write-Host ("SelfHandler inspection: local={0}; public={1}; database={2}; backup={3}; alerts={4}" -f $report.local_readiness.status, $report.public_route.status, $report.database, $report.latest_backup.status, @($report.alerts).Count)
    Write-Output ($report | ConvertTo-Json -Depth 20 -Compress)
}
