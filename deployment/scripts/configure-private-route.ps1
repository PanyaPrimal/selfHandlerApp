[CmdletBinding()]
param(
    [ValidateSet("Verify", "Apply")]
    [string]$Mode = "Verify"
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "shared.ps1")

function ConvertTo-CanonicalJsonValue {
    param(
        [AllowNull()][object]$Value,
        [switch]$WithoutSelfHandlerPort
    )

    if ($null -eq $Value) { return $null }
    if ($Value -is [Management.Automation.PSCustomObject]) {
        $ordered = [ordered]@{}
        foreach ($property in @($Value.PSObject.Properties | Sort-Object Name)) {
            if ($WithoutSelfHandlerPort -and ([string]$property.Name -eq "8443" -or [string]$property.Name -match ':8443$')) {
                continue
            }
            $ordered[[string]$property.Name] = ConvertTo-CanonicalJsonValue -Value $property.Value -WithoutSelfHandlerPort:$WithoutSelfHandlerPort
        }
        return $ordered
    }
    if ($Value -is [Collections.IDictionary]) {
        $ordered = [ordered]@{}
        foreach ($key in @($Value.Keys | ForEach-Object { [string]$_ } | Sort-Object)) {
            if ($WithoutSelfHandlerPort -and ($key -eq "8443" -or $key -match ':8443$')) {
                continue
            }
            $ordered[$key] = ConvertTo-CanonicalJsonValue -Value $Value[$key] -WithoutSelfHandlerPort:$WithoutSelfHandlerPort
        }
        return $ordered
    }
    if ($Value -is [Collections.IEnumerable] -and $Value -isnot [string]) {
        $items = @()
        foreach ($item in $Value) {
            $items += ,(ConvertTo-CanonicalJsonValue -Value $item -WithoutSelfHandlerPort:$WithoutSelfHandlerPort)
        }
        return ,$items
    }
    return $Value
}

function ConvertTo-NormalizedJsonText {
    param(
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$Json,
        [switch]$WithoutSelfHandlerPort
    )

    if ([String]::IsNullOrWhiteSpace($Json)) { return "{}" }
    try {
        $value = $Json | ConvertFrom-Json
        $canonical = ConvertTo-CanonicalJsonValue -Value $value -WithoutSelfHandlerPort:$WithoutSelfHandlerPort
        return ($canonical | ConvertTo-Json -Depth 20 -Compress)
    }
    catch {
        throw "Tailscale returned invalid JSON status."
    }
}

function ConvertFrom-TailscaleStatusJson {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Json)

    if ([String]::IsNullOrWhiteSpace($Json)) { return [pscustomobject]@{} }
    try {
        return ($Json | ConvertFrom-Json)
    }
    catch {
        throw "Tailscale returned invalid JSON status."
    }
}

function Get-JsonPropertyValue {
    param(
        [AllowNull()][object]$Value,
        [Parameter(Mandatory = $true)][string]$Name
    )

    if ($null -eq $Value) { return $null }
    $property = $Value.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Get-AllowFunnelProjection {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Json)

    $status = ConvertFrom-TailscaleStatusJson -Json $Json
    $allowFunnel = Get-JsonPropertyValue -Value $status -Name "AllowFunnel"
    if ($null -eq $allowFunnel) { return "{}" }
    $canonical = ConvertTo-CanonicalJsonValue -Value $allowFunnel
    return ($canonical | ConvertTo-Json -Depth 20 -Compress)
}

function Test-HasSelfHandlerPortProperty {
    param([AllowNull()][object]$Value)

    if ($null -eq $Value) { return $false }
    if ($Value -is [Management.Automation.PSCustomObject]) {
        foreach ($property in @($Value.PSObject.Properties)) {
            if ([string]$property.Name -eq "8443" -or [string]$property.Name -match ':8443$') {
                return $true
            }
            if (Test-HasSelfHandlerPortProperty -Value $property.Value) { return $true }
        }
    } elseif ($Value -is [Collections.IEnumerable] -and $Value -isnot [string]) {
        foreach ($item in $Value) {
            if (Test-HasSelfHandlerPortProperty -Value $item) { return $true }
        }
    }
    return $false
}

function Test-ExactSelfHandlerServeRoute {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Json)

    $status = ConvertFrom-TailscaleStatusJson -Json $Json
    $tcp = Get-JsonPropertyValue -Value $status -Name "TCP"
    $port = Get-JsonPropertyValue -Value $tcp -Name "8443"
    if ($null -eq $port -or (Get-JsonPropertyValue -Value $port -Name "HTTPS") -ne $true) {
        return $false
    }
    $web = Get-JsonPropertyValue -Value $status -Name "Web"
    if ($null -eq $web) { return $false }
    $portWebProperties = @($web.PSObject.Properties | Where-Object { [string]$_.Name -eq "8443" -or [string]$_.Name -match ':8443$' })
    if ($portWebProperties.Count -ne 1) { return $false }
    $handlers = Get-JsonPropertyValue -Value $portWebProperties[0].Value -Name "Handlers"
    if ($null -eq $handlers -or @($handlers.PSObject.Properties).Count -ne 1) { return $false }
    $rootHandler = Get-JsonPropertyValue -Value $handlers -Name "/"
    if ($null -eq $rootHandler -or [string](Get-JsonPropertyValue -Value $rootHandler -Name "Proxy") -ne "http://127.0.0.1:18080") {
        return $false
    }
    $allowFunnel = Get-JsonPropertyValue -Value $status -Name "AllowFunnel"
    if ($null -ne $allowFunnel) {
        foreach ($property in @($allowFunnel.PSObject.Properties)) {
            if (([string]$property.Name -eq "8443" -or [string]$property.Name -match ':8443$') -and [bool]$property.Value) {
                return $false
            }
        }
    }
    return $true
}

function Assert-SelfHandlerTailscaleDelta {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$BeforeServeJson,
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$AfterServeJson,
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$BeforeFunnelJson,
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$AfterFunnelJson
    )

    # Current Tailscale clients expose Serve and Funnel through one ServeConfig;
    # compare only AllowFunnel, because the legitimate 8443 Serve delta also
    # appears in `tailscale funnel status --json`.
    $beforeFunnel = Get-AllowFunnelProjection -Json $BeforeFunnelJson
    $afterFunnel = Get-AllowFunnelProjection -Json $AfterFunnelJson
    if ($beforeFunnel -ne $afterFunnel) {
        throw "DealFlow Funnel configuration changed unexpectedly."
    }
    $beforeServeWithoutSelfHandler = ConvertTo-NormalizedJsonText -Json $BeforeServeJson -WithoutSelfHandlerPort
    $afterServeWithoutSelfHandler = ConvertTo-NormalizedJsonText -Json $AfterServeJson -WithoutSelfHandlerPort
    if ($beforeServeWithoutSelfHandler -ne $afterServeWithoutSelfHandler) {
        throw "Pre-existing Tailscale Serve configuration changed unexpectedly."
    }
    if (-not (Test-ExactSelfHandlerServeRoute -Json $AfterServeJson)) {
        throw "SelfHandler private Serve endpoint is not the exact expected mapping."
    }
    return $true
}

function Assert-WindowsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw "Tailscale Serve Apply mode requires an Administrator terminal on Windows."
    }
}

function Get-LatestAppliedTailscaleSnapshot {
    $snapshotRoot = Join-Path $script:SelfHandlerStateRoot "tailscale"
    if (-not (Test-Path -LiteralPath $snapshotRoot -PathType Container)) {
        throw "The Administrator-applied Tailscale baseline snapshot is missing."
    }
    foreach ($candidate in @(Get-ChildItem -LiteralPath $snapshotRoot -File -Filter "*-after.json" | Sort-Object LastWriteTimeUtc -Descending)) {
        $snapshot = Read-JsonFile -Path $candidate.FullName
        if ([string]$snapshot.mode -eq "apply") {
            return $snapshot
        }
    }
    throw "The Administrator-applied Tailscale baseline snapshot is missing."
}

function Invoke-ConfigureSelfHandlerPrivateRoute {
    [CmdletBinding()]
    param(
        [ValidateSet("Verify", "Apply")][string]$Mode = "Verify",
        # deploy-production.ps1 already owns the fixed production lock. This is
        # an internal composition point, not a public target override.
        [switch]$LockAlreadyHeld
    )

    Assert-RequiredCommands -Names @("tailscale")
    if ($Mode -eq "Apply") {
        Assert-WindowsAdministrator
    }
    $lock = $null
    if (-not $LockAlreadyHeld) {
        $lock = Enter-SelfHandlerProductionLock
        Assert-SelfHandlerStateRootIntegrity | Out-Null
    }
    $configuredByThisRun = $false
    $beforeServe = ""
    $beforeFunnel = ""
    try {
        $beforeServe = (& tailscale serve status --json | Out-String).Trim()
        if ($LASTEXITCODE -ne 0) { throw "Unable to inspect Tailscale Serve configuration." }
        $beforeFunnel = (& tailscale funnel status --json | Out-String).Trim()
        if ($LASTEXITCODE -ne 0) { throw "Unable to inspect DealFlow Funnel configuration." }
        $beforeServeStatus = ConvertFrom-TailscaleStatusJson -Json $beforeServe
        $alreadyExpected = Test-ExactSelfHandlerServeRoute -Json $beforeServe

        if ($Mode -eq "Verify") {
            if (-not $alreadyExpected) {
                throw "The Administrator-managed SelfHandler 8443 Serve route is not configured exactly."
            }
            $baseline = Get-LatestAppliedTailscaleSnapshot
            $baselineServe = $baseline.serve | ConvertTo-Json -Depth 20 -Compress
            $baselineFunnel = $baseline.funnel | ConvertTo-Json -Depth 20 -Compress
            if ((ConvertTo-NormalizedJsonText -Json $beforeServe) -ne (ConvertTo-NormalizedJsonText -Json $baselineServe)) {
                throw "Tailscale Serve configuration drifted from the Administrator-applied baseline."
            }
            if ((Get-AllowFunnelProjection -Json $beforeFunnel) -ne (Get-AllowFunnelProjection -Json $baselineFunnel)) {
                throw "DealFlow Funnel configuration drifted from the Administrator-applied baseline."
            }
        } else {
            if ((Test-HasSelfHandlerPortProperty -Value $beforeServeStatus) -and -not $alreadyExpected) {
                throw "HTTPS 8443 already has a conflicting Tailscale Serve mapping."
            }
            $snapshotRoot = Join-Path $script:SelfHandlerStateRoot "tailscale"
            New-Item -ItemType Directory -Path $snapshotRoot -Force | Out-Null
            $snapshotId = "{0}-{1}" -f [DateTime]::UtcNow.ToString("yyyyMMddTHHmmssZ"), [Guid]::NewGuid().ToString("N").Substring(0, 8)
            Write-AtomicJson -Path (Join-Path $snapshotRoot ("$snapshotId-before.json")) -Value ([pscustomobject][ordered]@{
                mode = "apply"
                observed_at = [DateTime]::UtcNow.ToString("o")
                serve = $(if ([String]::IsNullOrWhiteSpace($beforeServe)) { [pscustomobject]@{} } else { $beforeServe | ConvertFrom-Json })
                funnel = $(if ([String]::IsNullOrWhiteSpace($beforeFunnel)) { [pscustomobject]@{} } else { $beforeFunnel | ConvertFrom-Json })
            })
            if (-not $alreadyExpected) {
                & tailscale serve --bg --yes --https=8443 http://127.0.0.1:18080 *> $null
                if ($LASTEXITCODE -ne 0) { throw "Tailscale Serve configuration failed." }
                $configuredByThisRun = $true
            }
            $afterServe = (& tailscale serve status --json | Out-String).Trim()
            $afterFunnel = (& tailscale funnel status --json | Out-String).Trim()
            Assert-SelfHandlerTailscaleDelta -BeforeServeJson $beforeServe -AfterServeJson $afterServe -BeforeFunnelJson $beforeFunnel -AfterFunnelJson $afterFunnel | Out-Null
            Write-AtomicJson -Path (Join-Path $snapshotRoot ("$snapshotId-after.json")) -Value ([pscustomobject][ordered]@{
                mode = "apply"
                observed_at = [DateTime]::UtcNow.ToString("o")
                serve = $afterServe | ConvertFrom-Json
                funnel = $afterFunnel | ConvertFrom-Json
            })
        }

        $dealFlow = Invoke-RestMethod -Uri ($script:SelfHandlerDealFlowOrigin + "/api/health/") -Method Get -TimeoutSec 20
        if ($dealFlow.status -ne "ok") {
            throw "DealFlow HTTPS 443 health verification failed."
        }
        if ($Mode -eq "Verify") {
            $selfHandler = Invoke-RestMethod -Uri ($script:SelfHandlerPrivateOrigin + "/api/health") -Method Get -TimeoutSec 20
            if ($selfHandler.status -ne "ok") {
                throw "SelfHandler private HTTPS health verification failed."
            }
        }
        return [pscustomobject][ordered]@{
            status = $(if ($Mode -eq "Apply") { "configured" } else { "verified" })
            private_origin = $script:SelfHandlerPrivateOrigin
            dealflow_443 = "preserved"
            changed = $configuredByThisRun
        }
    }
    catch {
        if ($Mode -eq "Apply" -and $configuredByThisRun) {
            # Scoped rollback: remove only the SelfHandler HTTPS 8443 endpoint.
            & tailscale serve --yes --https=8443 off *> $null
            $rollbackServe = (& tailscale serve status --json | Out-String).Trim()
            $rollbackFunnel = (& tailscale funnel status --json | Out-String).Trim()
            if ((ConvertTo-NormalizedJsonText -Json $rollbackServe) -ne (ConvertTo-NormalizedJsonText -Json $beforeServe)) {
                throw "SelfHandler route rollback did not preserve the prior Serve configuration."
            }
            if ((Get-AllowFunnelProjection -Json $rollbackFunnel) -ne (Get-AllowFunnelProjection -Json $beforeFunnel)) {
                throw "SelfHandler route rollback completed but DealFlow Funnel state changed."
            }
        }
        throw
    }
    finally {
        if ($lock) {
            Exit-SelfHandlerProductionLock -Lock $lock
        }
    }
}

if ($MyInvocation.InvocationName -ne ".") {
    $result = Invoke-ConfigureSelfHandlerPrivateRoute -Mode $Mode
    Write-Output ($result | ConvertTo-Json -Compress)
}
