Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"

# Fixed production identity. Operation entry points never accept alternatives.
$script:SelfHandlerDeploymentId = "selfhandler-production"
$script:SelfHandlerComposeProject = "selfhandler"
$script:SelfHandlerProductionRoot = "C:\Homelab\SelfHandlerApp"
$script:SelfHandlerLocalOrigin = "http://127.0.0.1:18080"
$script:SelfHandlerPublicOrigin = "https://selfhandler.drpanya.uk"
$script:SelfHandlerDealFlowOrigin = "https://crm.drpanya.uk"
$script:SelfHandlerDatabaseVolume = "selfhandler_mysql_data"
$script:SelfHandlerPrivateFilesVolume = "selfhandler_private_files"
$script:SelfHandlerAppNetwork = "selfhandler_app"
$script:SelfHandlerDataNetwork = "selfhandler_data"
$script:SelfHandlerEnvironmentPath = Join-Path $script:SelfHandlerProductionRoot ".env"
$script:SelfHandlerOpsRoot = Join-Path $script:SelfHandlerProductionRoot "ops"
$script:SelfHandlerOpsConfigPath = Join-Path $script:SelfHandlerOpsRoot "config.env"
$script:SelfHandlerSecretRoot = Join-Path $script:SelfHandlerOpsRoot "secrets"
$script:SelfHandlerStateRoot = Join-Path $script:SelfHandlerProductionRoot "state"
$script:SelfHandlerLockPath = "C:\Homelab\SelfHandlerApp\.locks\selfhandler-production.lock"
$script:SelfHandlerDeploymentRoot = Split-Path -Parent $PSScriptRoot
$script:SelfHandlerComposePath = Join-Path $script:SelfHandlerDeploymentRoot "compose.production.yaml"
$script:SelfHandlerValidationComposePath = Join-Path $script:SelfHandlerDeploymentRoot "compose.validation.yaml"
$script:SelfHandlerWebRepository = "ghcr.io/panyaprimal/selfhandler-web"
$script:SelfHandlerAppRepository = "ghcr.io/panyaprimal/selfhandler-app"
$script:SelfHandlerEmptySchemaFingerprint = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

function Get-SelfHandlerConstants {
    [CmdletBinding()]
    param()

    return [pscustomobject][ordered]@{
        DeploymentId = $script:SelfHandlerDeploymentId
        ComposeProject = $script:SelfHandlerComposeProject
        ProductionRoot = $script:SelfHandlerProductionRoot
        LocalOrigin = $script:SelfHandlerLocalOrigin
        PublicOrigin = $script:SelfHandlerPublicOrigin
        DatabaseVolume = $script:SelfHandlerDatabaseVolume
        PrivateFilesVolume = $script:SelfHandlerPrivateFilesVolume
        AppNetwork = $script:SelfHandlerAppNetwork
        DataNetwork = $script:SelfHandlerDataNetwork
        EnvironmentPath = $script:SelfHandlerEnvironmentPath
        OpsConfigPath = $script:SelfHandlerOpsConfigPath
        StateRoot = $script:SelfHandlerStateRoot
        LockPath = $script:SelfHandlerLockPath
        ComposePath = $script:SelfHandlerComposePath
    }
}

function Write-SafeOperationMessage {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[a-z][a-z0-9_.-]{0,63}$')]
        [string]$Code,

        [string]$Detail = ""
    )

    if ($Detail -match '[\r\n]' -or $Detail -match '(?i)(password|secret|token|private.?key)\s*[:=]') {
        throw "Refusing to write potentially protected operation detail."
    }
    if ($Detail) {
        Write-Host ("[selfhandler:{0}] {1}" -f $Code, $Detail)
    } else {
        Write-Host ("[selfhandler:{0}]" -f $Code)
    }
}

function Read-KeyValueFile {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Required protected configuration file is missing."
    }
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines([IO.Path]::GetFullPath($Path))) {
        if ([String]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith("#")) {
            continue
        }
        $parts = $line -split "=", 2
        if ($parts.Count -ne 2 -or $parts[0].Trim() -notmatch '^[A-Za-z_][A-Za-z0-9_]*$') {
            throw "Protected configuration contains an invalid assignment."
        }
        $name = $parts[0].Trim()
        if ($values.ContainsKey($name)) {
            throw "Protected configuration contains a duplicate name."
        }
        $values[$name] = $parts[1].Trim()
    }
    return $values
}

function Get-EnvironmentVariableNames {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    $values = Read-KeyValueFile -Path $Path
    return @($values.Keys | Sort-Object)
}

function Get-ConfiguredExecutable {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [hashtable]$Configuration,
        [Parameter(Mandatory = $true)]
        [string]$Name,
        [Parameter(Mandatory = $true)]
        [string]$DefaultCommand
    )

    $candidate = $DefaultCommand
    if ($Configuration.ContainsKey($Name) -and -not [String]::IsNullOrWhiteSpace([string]$Configuration[$Name])) {
        $candidate = [string]$Configuration[$Name]
    }
    $command = Get-Command $candidate -ErrorAction SilentlyContinue
    if (-not $command) {
        throw "Required executable '$Name' is unavailable."
    }
    return $command.Source
}

function Assert-SafeFilesystemTarget {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [switch]$AllowProductionChild
    )

    $fullPath = [IO.Path]::GetFullPath($Path).TrimEnd("\")
    $root = [IO.Path]::GetPathRoot($fullPath).TrimEnd("\")
    if ($fullPath.Length -lt 8 -or $fullPath -eq $root) {
        throw "Refusing an unsafe broad filesystem target."
    }
    $productionRoot = [IO.Path]::GetFullPath($script:SelfHandlerProductionRoot).TrimEnd("\")
    if (-not $AllowProductionChild -and (
        $fullPath -eq $productionRoot -or
        $fullPath.StartsWith($productionRoot + "\", [StringComparison]::OrdinalIgnoreCase)
    )) {
        throw "Refusing to use a production path for transient plaintext."
    }
    return $fullPath
}

function Remove-SensitivePath {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    $fullPath = Assert-SafeFilesystemTarget -Path $Path
    if (Test-Path -LiteralPath $fullPath) {
        Remove-Item -LiteralPath $fullPath -Recurse -Force
    }
}

function Get-TrustedOperationSids {
    $runnerSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    return @($runnerSid, "S-1-5-32-544", "S-1-5-18")
}

function Get-WindowsPathAcl {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [switch]$Directory
    )

    if ($Directory) {
        return [IO.Directory]::GetAccessControl([IO.Path]::GetFullPath($Path))
    }
    return [IO.File]::GetAccessControl([IO.Path]::GetFullPath($Path))
}

function Set-WindowsPathAcl {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][Security.AccessControl.FileSystemSecurity]$Acl,
        [switch]$Directory
    )

    if ($Directory) {
        [IO.Directory]::SetAccessControl([IO.Path]::GetFullPath($Path), [Security.AccessControl.DirectorySecurity]$Acl)
    } else {
        [IO.File]::SetAccessControl([IO.Path]::GetFullPath($Path), [Security.AccessControl.FileSecurity]$Acl)
    }
}

function Assert-TrustedWindowsAcl {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][object]$Acl,
        [Parameter(Mandatory = $true)][ValidateSet("file", "directory")][string]$Context
    )

    $allowedSids = @(Get-TrustedOperationSids)
    try {
        $ownerSid = (New-Object Security.Principal.NTAccount([string]$Acl.Owner)).Translate([Security.Principal.SecurityIdentifier]).Value
    }
    catch {
        try {
            $ownerSid = (New-Object Security.Principal.SecurityIdentifier([string]$Acl.Owner)).Value
        }
        catch {
            throw "Protected $Context has an unresolvable owner."
        }
    }
    if ($allowedSids -notcontains $ownerSid) {
        throw "Protected $Context owner is outside runner, Administrators, or SYSTEM."
    }
    if (-not $Acl.AreAccessRulesProtected) {
        throw "Protected $Context must have ACL inheritance disabled."
    }
    $observedAllowSids = @{}
    foreach ($rule in @($Acl.Access)) {
        if ($rule.IsInherited) {
            throw "Protected $Context contains an inherited access rule."
        }
        try {
            $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
        }
        catch {
            throw "Protected $Context contains an unresolvable access identity."
        }
        if ($rule.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow) {
            if ($allowedSids -notcontains $sid) {
                throw "Protected $Context grants replacement or write access outside runner, Administrators, or SYSTEM."
            }
            $observedAllowSids[$sid] = $true
        }
    }
    foreach ($requiredSid in $allowedSids) {
        if (-not $observedAllowSids.ContainsKey($requiredSid)) {
            throw "Protected $Context ACL is missing a required trusted identity."
        }
    }
}

function Assert-TrustedIntegrityAcl {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][object]$Acl,
        [Parameter(Mandatory = $true)][ValidateSet("file", "directory")][string]$Context,
        [switch]$RequireProtected
    )

    $allowedSids = @(Get-TrustedOperationSids)
    try {
        $ownerSid = (New-Object Security.Principal.NTAccount([string]$Acl.Owner)).Translate([Security.Principal.SecurityIdentifier]).Value
    }
    catch {
        try { $ownerSid = (New-Object Security.Principal.SecurityIdentifier([string]$Acl.Owner)).Value }
        catch { throw "Integrity-protected $Context has an unresolvable owner." }
    }
    if ($allowedSids -notcontains $ownerSid) {
        throw "Integrity-protected $Context owner is untrusted."
    }
    if ($RequireProtected -and -not $Acl.AreAccessRulesProtected) {
        throw "Integrity-protected $Context must have ACL inheritance disabled."
    }
    $observedAllowSids = @{}
    foreach ($rule in @($Acl.Access)) {
        try { $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value }
        catch { throw "Integrity-protected $Context contains an unresolvable identity." }
        if ($rule.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow) {
            if ($allowedSids -notcontains $sid) {
                throw "Integrity-protected $Context grants access to an untrusted identity."
            }
            $observedAllowSids[$sid] = $true
        }
    }
    foreach ($requiredSid in $allowedSids) {
        if (-not $observedAllowSids.ContainsKey($requiredSid)) {
            throw "Integrity-protected $Context ACL is missing a required trusted identity."
        }
    }
}

function Assert-TrustedIntegrityPath {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][ValidateSet("file", "directory")][string]$Type,
        [switch]$RequireProtectedAcl
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    $pathType = $(if ($Type -eq "file") { "Leaf" } else { "Container" })
    if (-not (Test-Path -LiteralPath $fullPath -PathType $pathType)) {
        throw "Required integrity-protected $Type is missing."
    }
    $item = Get-Item -LiteralPath $fullPath -Force
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Integrity-protected $Type cannot be a reparse point."
    }
    Assert-TrustedIntegrityAcl -Acl (Get-WindowsPathAcl -Path $fullPath -Directory:($Type -eq "directory")) -Context $Type -RequireProtected:$RequireProtectedAcl
    return $fullPath
}

function Assert-SelfHandlerStateRootIntegrity {
    [CmdletBinding()]
    param()

    return Assert-TrustedIntegrityPath -Path $script:SelfHandlerStateRoot -Type directory -RequireProtectedAcl
}

function Protect-TrustedIntegrityPathAcl {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [switch]$Directory
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    $acl = Get-WindowsPathAcl -Path $fullPath -Directory:$Directory
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.Access)) {
        [void]$acl.RemoveAccessRuleSpecific($rule)
    }
    foreach ($sidText in @(Get-TrustedOperationSids)) {
        $sid = New-Object Security.Principal.SecurityIdentifier($sidText)
        if ($Directory) {
            $rule = New-Object Security.AccessControl.FileSystemAccessRule(
                $sid,
                [Security.AccessControl.FileSystemRights]::FullControl,
                ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit),
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            )
        } else {
            $rule = New-Object Security.AccessControl.FileSystemAccessRule(
                $sid,
                [Security.AccessControl.FileSystemRights]::FullControl,
                [Security.AccessControl.AccessControlType]::Allow
            )
        }
        [void]$acl.AddAccessRule($rule)
    }
    Set-WindowsPathAcl -Path $fullPath -Acl $acl -Directory:$Directory
    Assert-TrustedIntegrityPath -Path $fullPath -Type $(if ($Directory) { "directory" } else { "file" }) -RequireProtectedAcl | Out-Null
}

function Assert-NoUntrustedReplacementAccess {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$Path)

    $fullPath = [IO.Path]::GetFullPath($Path)
    $item = Get-Item -LiteralPath $fullPath -Force
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Lock ancestor must be a regular directory."
    }
    $acl = Get-WindowsPathAcl -Path $fullPath -Directory
    $trustedSids = @(Get-TrustedOperationSids)
    try { $ownerSid = $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value }
    catch { throw "Lock ancestor owner is unresolvable." }
    if ($trustedSids -notcontains $ownerSid) {
        throw "Lock ancestor owner is untrusted."
    }
    $replacementRights = [Security.AccessControl.FileSystemRights]::Write -bor
        [Security.AccessControl.FileSystemRights]::Modify -bor
        [Security.AccessControl.FileSystemRights]::FullControl -bor
        [Security.AccessControl.FileSystemRights]::DeleteSubdirectoriesAndFiles -bor
        [Security.AccessControl.FileSystemRights]::ChangePermissions -bor
        [Security.AccessControl.FileSystemRights]::TakeOwnership
    foreach ($rule in @($acl.Access)) {
        if ($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) { continue }
        try { $sid = $rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value }
        catch { throw "Lock ancestor contains an unresolvable access identity." }
        if ($trustedSids -notcontains $sid -and
            ($rule.FileSystemRights -band $replacementRights) -ne 0) {
            throw "Lock ancestor grants replacement access to an untrusted identity."
        }
    }
}

function Assert-ProtectedSecretFile {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [ValidateRange(1, 1048576)][int64]$MinimumBytes = 1,
        [ValidateRange(1, 16777216)][int64]$MaximumBytes = 1048576
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    if (-not (Test-Path -LiteralPath $fullPath -PathType Leaf)) {
        throw "Required ACL-protected secret file is missing."
    }
    $item = Get-Item -LiteralPath $fullPath -Force
    if ($MaximumBytes -lt $MinimumBytes -or
        ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or
        [int64]$item.Length -lt $MinimumBytes -or
        [int64]$item.Length -gt $MaximumBytes) {
        throw "Protected secret file type or size is invalid."
    }
    Assert-TrustedWindowsAcl -Acl (Get-WindowsPathAcl -Path $fullPath) -Context file

    $parentPath = Split-Path -Parent $fullPath
    $parentItem = Get-Item -LiteralPath $parentPath -Force
    if (-not $parentItem.PSIsContainer -or ($parentItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Protected file parent directory type is invalid."
    }
    Assert-TrustedWindowsAcl -Acl (Get-WindowsPathAcl -Path $parentPath -Directory) -Context directory
    return $fullPath
}

function Invoke-WithSensitiveDirectory {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [scriptblock]$Action
    )

    $fullPath = Assert-SafeFilesystemTarget -Path $Path
    if (Test-Path -LiteralPath $fullPath) {
        if (@(Get-ChildItem -LiteralPath $fullPath -Force).Count -gt 0) {
            throw "Sensitive staging directory already exists and is not empty."
        }
    } else {
        New-Item -ItemType Directory -Path $fullPath | Out-Null
    }
    try {
        & $Action $fullPath
    }
    finally {
        Remove-SensitivePath -Path $fullPath
    }
}

function Enter-SelfHandlerProductionLock {
    [CmdletBinding()]
    param(
        [string]$Path = $script:SelfHandlerLockPath,
        [ValidateRange(0, 3600)]
        [int]$TimeoutSeconds = 0
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    $directory = Split-Path -Parent $fullPath
    # The one-time administrator bootstrap owns directory creation. Creating a
    # lock beneath a mutable/reparse parent would let another account replace
    # the inode and bypass FileShare.None serialization.
    Assert-NoUntrustedReplacementAccess -Path (Split-Path -Parent $directory)
    Assert-TrustedIntegrityPath -Path $directory -Type directory -RequireProtectedAcl | Out-Null
    if (Test-Path -LiteralPath $fullPath) {
        Assert-TrustedIntegrityPath -Path $fullPath -Type file -RequireProtectedAcl | Out-Null
    }
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    do {
        try {
            $stream = [IO.File]::Open($fullPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
            Protect-TrustedIntegrityPathAcl -Path $fullPath
            $stream.SetLength(0)
            $writer = New-Object IO.StreamWriter($stream, (New-Object Text.UTF8Encoding($false)), 1024, $true)
            $writer.Write(("pid={0};acquired_at={1}" -f $PID, [DateTime]::UtcNow.ToString("o")))
            $writer.Flush()
            $writer.Dispose()
            return $stream
        }
        catch [IO.IOException] {
            if ([DateTime]::UtcNow -ge $deadline) {
                throw "Another SelfHandler production operation holds the exclusive lock."
            }
            Start-Sleep -Milliseconds 500
        }
    } while ($true)
}

function Exit-SelfHandlerProductionLock {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [IO.FileStream]$Lock
    )

    $Lock.Dispose()
}

function ConvertTo-CompactJson {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [AllowNull()]
        [object]$Value
    )

    return ($Value | ConvertTo-Json -Depth 20 -Compress)
}

function Write-AtomicJson {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [Parameter(Mandatory = $true)]
        [AllowNull()]
        [object]$Value
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    $directory = Split-Path -Parent $fullPath
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
    Protect-TrustedIntegrityPathAcl -Path $directory -Directory
    $temporary = "$fullPath.$([Guid]::NewGuid().ToString('N')).partial"
    $backup = "$fullPath.$([Guid]::NewGuid().ToString('N')).backup"
    try {
        [IO.File]::WriteAllText($temporary, (ConvertTo-CompactJson -Value $Value), (New-Object Text.UTF8Encoding($false)))
        Protect-TrustedIntegrityPathAcl -Path $temporary
        if ([IO.File]::Exists($fullPath)) {
            [IO.File]::Replace($temporary, $fullPath, $backup)
            [IO.File]::Delete($backup)
        } else {
            [IO.File]::Move($temporary, $fullPath)
        }
        Protect-TrustedIntegrityPathAcl -Path $fullPath
    }
    finally {
        foreach ($candidate in @($temporary, $backup)) {
            if ([IO.File]::Exists($candidate)) {
                [IO.File]::Delete($candidate)
            }
        }
    }
}

function Read-JsonFile {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [switch]$AllowMissing
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        if ($AllowMissing) {
            return $null
        }
        throw "Required JSON state was not found."
    }
    try {
        return (Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json)
    }
    catch {
        throw "Stored JSON state is invalid."
    }
}

function Add-ReleaseRecord {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [psobject]$Record,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    if ([string]$Record.attempt_id -notmatch '^[A-Za-z0-9._-]{8,128}$') {
        throw "Release attempt identity is invalid."
    }
    $recordsRoot = Join-Path $StateRoot "releases"
    New-Item -ItemType Directory -Path $recordsRoot -Force | Out-Null
    $recordPath = Join-Path $recordsRoot (([string]$Record.attempt_id) + ".json")
    if (Test-Path -LiteralPath $recordPath) {
        throw "Release attempt already exists; append-only evidence cannot be overwritten."
    }
    Write-AtomicJson -Path $recordPath -Value $Record

    $historyPath = Join-Path $StateRoot "release-history.json"
    $history = @()
    $existing = Read-JsonFile -Path $historyPath -AllowMissing
    if ($null -ne $existing) {
        $history = @($existing)
    }
    $history += $Record
    try {
        Write-AtomicJson -Path $historyPath -Value $history
    }
    catch {
        # The immutable per-attempt record above is canonical. A derived
        # aggregate must never turn a durably recorded terminal outcome into an
        # unrecorded deployment failure.
        Write-SafeOperationMessage -Code "release.history_degraded" -Detail "The canonical attempt record is preserved."
    }
    return $recordPath
}

function Get-ReleaseAttemptPath {
    param(
        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[A-Za-z0-9._-]{8,128}$')]
        [string]$AttemptId,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    return Join-Path (Join-Path $StateRoot "releases") ($AttemptId + ".json")
}

function Get-ReleaseAttemptRecord {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[A-Za-z0-9._-]{8,128}$')]
        [string]$AttemptId,
        [switch]$AllowMissing,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    $path = Get-ReleaseAttemptPath -AttemptId $AttemptId -StateRoot $StateRoot
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        Assert-TrustedIntegrityPath -Path $path -Type file -RequireProtectedAcl | Out-Null
    }
    return Read-JsonFile -Path $path -AllowMissing:$AllowMissing
}

function Get-PendingReleasePath {
    param(
        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[A-Za-z0-9._-]{8,128}$')]
        [string]$AttemptId,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    return Join-Path (Join-Path $StateRoot "pending-releases") ($AttemptId + ".json")
}

function Get-PendingRelease {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[A-Za-z0-9._-]{8,128}$')]
        [string]$AttemptId,
        [switch]$AllowMissing,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    $path = Get-PendingReleasePath -AttemptId $AttemptId -StateRoot $StateRoot
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        Assert-TrustedIntegrityPath -Path $path -Type file -RequireProtectedAcl | Out-Null
    }
    return Read-JsonFile -Path $path -AllowMissing:$AllowMissing
}

function Get-PendingReleaseRecords {
    [CmdletBinding()]
    param([string]$StateRoot = $script:SelfHandlerStateRoot)

    $root = Join-Path $StateRoot "pending-releases"
    if (-not (Test-Path -LiteralPath $root -PathType Container)) {
        return @()
    }
    Assert-TrustedIntegrityPath -Path $root -Type directory -RequireProtectedAcl | Out-Null
    $records = @()
    foreach ($file in @(Get-ChildItem -LiteralPath $root -File -Filter "*.json" | Sort-Object Name)) {
        Assert-TrustedIntegrityPath -Path $file.FullName -Type file -RequireProtectedAcl | Out-Null
        $record = Read-JsonFile -Path $file.FullName
        if ([string]$record.attempt_id -notmatch '^[A-Za-z0-9._-]{8,128}$' -or
            $file.Name -ne (([string]$record.attempt_id) + ".json") -or
            [string]$record.deployment_id -ne $script:SelfHandlerDeploymentId -or
            [string]$record.state -notin @("deploying", "awaiting_completion", "completion_validated")) {
            throw "Stored pending release state is invalid."
        }
        $records += $record
    }
    return @($records)
}

function Save-PendingRelease {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Record,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    $path = Get-PendingReleasePath -AttemptId ([string]$Record.attempt_id) -StateRoot $StateRoot
    if (Test-Path -LiteralPath $path) {
        throw "A pending release attempt cannot be overwritten."
    }
    Write-AtomicJson -Path $path -Value $Record
    return $path
}

function Set-PendingReleaseDeploymentState {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Record,
        [Parameter(Mandatory = $true)][ValidateSet("deploying", "awaiting_completion")][string]$State,
        [Parameter(Mandatory = $true)][ValidatePattern('^[0-9a-f]{64}$')][string]$SchemaAfter,
        [Parameter(Mandatory = $true)][psobject]$Checks,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    if ([string]$Record.state -notin @("deploying", "awaiting_completion") -or
        [string]$Record.attempt_id -notmatch '^[A-Za-z0-9._-]{8,128}$') {
        throw "Pending deployment journal is invalid."
    }
    $updated = [ordered]@{}
    foreach ($property in @($Record.PSObject.Properties)) {
        $updated[$property.Name] = $property.Value
    }
    $updated["state"] = $State
    $updated["schema_after"] = $SchemaAfter
    $updated["checks"] = $Checks
    if ($State -eq "awaiting_completion") {
        $updated["verified_at"] = [DateTime]::UtcNow.ToString("o")
    }
    $path = Get-PendingReleasePath -AttemptId ([string]$Record.attempt_id) -StateRoot $StateRoot
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Pending deployment journal disappeared."
    }
    Write-AtomicJson -Path $path -Value ([pscustomobject]$updated)
    return Read-JsonFile -Path $path
}

function Set-PendingReleaseCompletion {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Record,
        [Parameter(Mandatory = $true)][psobject]$Backup,
        [Parameter(Mandatory = $true)][ValidateLength(1, 512)][string]$Reference,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    if ([string]$Record.state -notin @("awaiting_completion", "completion_validated") -or
        [string]$Backup.status -ne "valid" -or
        [string]$Backup.off_host_reference -ne $Reference -or
        [string]$Backup.bundle_id -notmatch '^selfhandler-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8,64}$') {
        throw "Pending release completion evidence is invalid."
    }
    $updated = [ordered]@{}
    foreach ($property in @($Record.PSObject.Properties)) {
        $updated[$property.Name] = $property.Value
    }
    $updated["state"] = "completion_validated"
    $updated["completion_backup_bundle_id"] = [string]$Backup.bundle_id
    $updated["completion_backup_reference"] = $Reference
    $updated["completion_validated_at"] = [DateTime]::UtcNow.ToString("o")
    $path = Get-PendingReleasePath -AttemptId ([string]$Record.attempt_id) -StateRoot $StateRoot
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Pending release disappeared before completion evidence could be stored."
    }
    Write-AtomicJson -Path $path -Value ([pscustomobject]$updated)
    return Read-JsonFile -Path $path
}

function Remove-PendingRelease {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[A-Za-z0-9._-]{8,128}$')]
        [string]$AttemptId,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    $path = Get-PendingReleasePath -AttemptId $AttemptId -StateRoot $StateRoot
    if ([IO.File]::Exists($path)) {
        [IO.File]::Delete($path)
    }
}

function Get-UnfinishedPendingReleaseRecords {
    [CmdletBinding()]
    param([string]$StateRoot = $script:SelfHandlerStateRoot)

    $unfinished = @()
    foreach ($pending in @(Get-PendingReleaseRecords -StateRoot $StateRoot)) {
        $terminal = Get-ReleaseAttemptRecord -AttemptId ([string]$pending.attempt_id) -AllowMissing -StateRoot $StateRoot
        if ($null -eq $terminal) {
            $unfinished += $pending
            continue
        }
        if ([string]$terminal.deployment_id -ne $script:SelfHandlerDeploymentId -or
            [string]$terminal.source_revision -ne [string]$pending.source_revision -or
            [string]$terminal.web_digest -ne [string]$pending.web_digest -or
            [string]$terminal.app_digest -ne [string]$pending.app_digest -or
            [string]$terminal.outcome -notin @("succeeded", "rejected", "failed_before_replace", "rolled_back", "recovery_required")) {
            throw "Pending release conflicts with canonical terminal evidence."
        }
        if ([string]$terminal.outcome -eq "succeeded") {
            $candidate = [pscustomobject]@{
                source_revision = [string]$terminal.source_revision
                web_digest = [string]$terminal.web_digest
                app_digest = [string]$terminal.app_digest
            }
            if (-not (Test-ReleaseIdentityEqual -Left (Get-ActiveRelease -StateRoot $StateRoot) -Right $candidate)) {
                throw "Succeeded terminal evidence conflicts with the active release."
            }
        } elseif ([string]$terminal.outcome -eq "rolled_back" -and
            -not (Test-ReleaseIdentityEqual -Left (Get-ActiveRelease -StateRoot $StateRoot) -Right $terminal.restored_release)) {
            throw "Rolled-back terminal evidence conflicts with the active release."
        }
        Remove-PendingRelease -AttemptId ([string]$pending.attempt_id) -StateRoot $StateRoot
    }
    return @($unfinished)
}

function Test-ReleaseIdentityEqual {
    [CmdletBinding()]
    param(
        [AllowNull()][psobject]$Left,
        [AllowNull()][psobject]$Right
    )

    if ($null -eq $Left -or $null -eq $Right) {
        return $null -eq $Left -and $null -eq $Right
    }
    return [string]$Left.source_revision -eq [string]$Right.source_revision -and
        [string]$Left.web_digest -eq [string]$Right.web_digest -and
        [string]$Left.app_digest -eq [string]$Right.app_digest
}

function Complete-SelfHandlerReleaseRecord {
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
        [Parameter(Mandatory = $true)][psobject]$Checks,
        [Parameter(Mandatory = $true)][ValidatePattern('^[A-Za-z0-9_.-]{1,100}$')][string]$Actor,
        [Parameter(Mandatory = $true)][ValidatePattern('^[A-Za-z0-9._-]{8,128}$')][string]$AttemptId
    )

    $record = [pscustomobject][ordered]@{
        schema_version = 1
        attempt_id = $AttemptId
        deployment_id = $script:SelfHandlerDeploymentId
        source_revision = [string]$Manifest.source_revision
        web_digest = [string]$Manifest.web_image.digest
        app_digest = [string]$Manifest.app_image.digest
        previous_release = $PreviousRelease
        schema_before = $SchemaBefore
        schema_after = $SchemaAfter
        backup_reference = $ValidatedBackupReference
        actor = $Actor
        started_at = $StartedAt
        completed_at = [DateTime]::UtcNow.ToString("o")
        checks = $Checks
        outcome = $Outcome
        restored_release = $RestoredRelease
        failure_code = $FailureCode
    }
    Add-ReleaseRecord -Record $record | Out-Null
    return $record
}

function Set-ActiveRelease {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [psobject]$Release,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    Write-AtomicJson -Path (Join-Path $StateRoot "active-release.json") -Value $Release
}

function Get-ActiveRelease {
    [CmdletBinding()]
    param([string]$StateRoot = $script:SelfHandlerStateRoot)

    $path = Join-Path $StateRoot "active-release.json"
    if (Test-Path -LiteralPath $path -PathType Leaf) {
        Assert-TrustedIntegrityPath -Path $path -Type file -RequireProtectedAcl | Out-Null
    }
    return Read-JsonFile -Path $path -AllowMissing
}

function Get-Sha256Text {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Text)

    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = (New-Object Text.UTF8Encoding($false)).GetBytes($Text)
        return (($algorithm.ComputeHash($bytes) | ForEach-Object { $_.ToString("x2") }) -join "")
    }
    finally {
        $algorithm.Dispose()
    }
}

function Convert-DockerMemoryTextToBytes {
    param([Parameter(Mandatory = $true)][string]$Text)

    $value = $Text.Trim()
    if ($value -notmatch '^([0-9]+(?:\.[0-9]+)?)\s*(B|kB|KiB|MB|MiB|GB|GiB)$') {
        throw "Docker memory usage format is invalid."
    }
    $number = [double]::Parse($matches[1], [Globalization.CultureInfo]::InvariantCulture)
    $multiplier = switch ($matches[2]) {
        "B" { 1 }
        "kB" { 1000 }
        "KiB" { 1024 }
        "MB" { 1000000 }
        "MiB" { 1048576 }
        "GB" { 1000000000 }
        "GiB" { 1073741824 }
    }
    return [int64][Math]::Ceiling($number * $multiplier)
}

function Test-SelfHandlerMemoryCapacity {
    param(
        [Parameter(Mandatory = $true)][int64]$TotalBytes,
        [Parameter(Mandatory = $true)][int64]$UsedBytes,
        [int64]$MinimumTotalBytes = 4294967296,
        [int64]$RequiredHeadroomBytes = 2147483648
    )

    return $TotalBytes -ge $MinimumTotalBytes -and
        $UsedBytes -ge 0 -and
        $UsedBytes -le $TotalBytes -and
        ($TotalBytes - $UsedBytes) -ge $RequiredHeadroomBytes
}

function Assert-SelfHandlerRecoveryAgePolicy {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][ValidateSet("Drill", "Production", "BootstrapReset")][string]$RecoveryMode,
        [Parameter(Mandatory = $true)][double]$AgeHours,
        [AllowNull()][string]$StaleConfirmation
    )

    $confirmationText = "RESTORE selfhandler-production BACKUP OLDER THAN 24 HOURS"
    if ($AgeHours -lt (-5.0 / 60.0)) {
        throw "recovery_timestamp_in_future"
    }
    if ($RecoveryMode -eq "Drill") {
        if ($StaleConfirmation) { throw "stale_confirmation_forbidden" }
        if ($AgeHours -gt 24) { throw "recovery_backup_too_old" }
        return
    }
    if ($AgeHours -gt 720) {
        throw "recovery_backup_beyond_retention"
    }
    if ($AgeHours -gt 24) {
        if ($StaleConfirmation -ne $confirmationText) {
            throw "stale_recovery_confirmation_required"
        }
        return
    }
    if ($StaleConfirmation) {
        throw "stale_confirmation_not_applicable"
    }
}

function Test-LoopbackPortAvailable {
    [CmdletBinding()]
    param(
        [ValidateRange(1, 65535)]
        [int]$Port = 18080
    )

    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Parse("127.0.0.1"), $Port)
    try {
        # ExclusiveAddressUse prevents this read-only preflight from reporting a
        # reusable/shared bind as safe for Docker's fixed production listener.
        $listener.ExclusiveAddressUse = $true
        $listener.Start()
        return $true
    }
    catch [Net.Sockets.SocketException] {
        return $false
    }
    finally {
        try { $listener.Stop() } catch { }
    }
}

function Assert-BootstrapLoopbackPortAvailable {
    [CmdletBinding()]
    param()

    if (-not (Test-LoopbackPortAvailable -Port 18080)) {
        throw "local_port_conflict"
    }
}

function Assert-SelfHandlerCapacity {
    [CmdletBinding()]
    param(
        [Int64]$RequiredBytes = 2147483648,
        [string]$Path = $script:SelfHandlerProductionRoot
    )

    $fullPath = [IO.Path]::GetFullPath($Path)
    $driveRoot = [IO.Path]::GetPathRoot($fullPath)
    $drive = New-Object IO.DriveInfo($driveRoot)
    if (-not $drive.IsReady -or $drive.AvailableFreeSpace -lt $RequiredBytes) {
        throw "capacity_insufficient"
    }
    $totalMemoryText = [string](& docker info --format "{{.MemTotal}}" 2>$null)
    if ($LASTEXITCODE -ne 0 -or $totalMemoryText.Trim() -notmatch '^[0-9]+$') {
        throw "capacity_insufficient"
    }
    $usedMemory = [int64]0
    $usageLines = @(& docker stats --no-stream --format "{{.MemUsage}}" 2>$null)
    if ($LASTEXITCODE -ne 0) {
        throw "capacity_insufficient"
    }
    foreach ($usageLine in $usageLines) {
        if ([String]::IsNullOrWhiteSpace([string]$usageLine)) { continue }
        $usedText = ([string]$usageLine -split '/', 2)[0].Trim()
        $usedMemory += Convert-DockerMemoryTextToBytes -Text $usedText
    }
    if (-not (Test-SelfHandlerMemoryCapacity -TotalBytes ([int64]$totalMemoryText.Trim()) -UsedBytes $usedMemory)) {
        throw "capacity_insufficient"
    }
    return [Int64]$drive.AvailableFreeSpace
}

function Assert-RequiredCommands {
    [CmdletBinding()]
    param([string[]]$Names = @("docker"))

    foreach ($name in $Names) {
        if (-not (Get-Command $name -ErrorAction SilentlyContinue)) {
            throw "dependency_unavailable"
        }
    }
    & docker info *> $null
    if ($LASTEXITCODE -ne 0) {
        throw "dependency_unavailable"
    }
}

function ConvertTo-WindowsNativeArgument {
    [CmdletBinding()]
    param([AllowEmptyString()][Parameter(Mandatory = $true)][string]$Argument)

    # ProcessStartInfo.ArgumentList is unavailable in Windows PowerShell 5.1.
    # Apply the CommandLineToArgvW quoting rules so arguments are never handed
    # to cmd.exe (which would make SQL paths and shell fragments injectable).
    if ($Argument.Length -gt 0 -and $Argument -notmatch '[\s"]') {
        return $Argument
    }
    $builder = New-Object Text.StringBuilder
    [void]$builder.Append('"')
    $backslashes = 0
    foreach ($character in $Argument.ToCharArray()) {
        if ([int]$character -eq 92) {
            $backslashes++
            continue
        }
        if ([int]$character -eq 34) {
            [void]$builder.Append(('\' * (($backslashes * 2) + 1)))
            [void]$builder.Append('"')
            $backslashes = 0
            continue
        }
        if ($backslashes -gt 0) {
            [void]$builder.Append(('\' * $backslashes))
            $backslashes = 0
        }
        [void]$builder.Append($character)
    }
    if ($backslashes -gt 0) {
        [void]$builder.Append(('\' * ($backslashes * 2)))
    }
    [void]$builder.Append('"')
    return $builder.ToString()
}

function Invoke-NativeProcessRedirected {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [string]$StandardInputPath,
        [string]$StandardOutputPath,
        [Parameter(Mandatory = $true)][string]$StandardErrorPath
    )

    $startInfo = New-Object Diagnostics.ProcessStartInfo
    $startInfo.FileName = [IO.Path]::GetFullPath($FilePath)
    $startInfo.Arguments = (@($Arguments | ForEach-Object { ConvertTo-WindowsNativeArgument -Argument ([string]$_) }) -join ' ')
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = -not [String]::IsNullOrWhiteSpace($StandardInputPath)
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true

    $process = New-Object Diagnostics.Process
    $process.StartInfo = $startInfo
    $inputStream = $null
    $childInputStream = $null
    $outputStream = $null
    $errorStream = $null
    $inputTask = $null
    $outputTasks = New-Object 'Collections.Generic.List[Threading.Tasks.Task]'
    $originalInputEncoding = $null
    try {
        if ($startInfo.RedirectStandardInput) {
            $originalInputEncoding = [Console]::InputEncoding
            [Console]::InputEncoding = New-Object Text.UTF8Encoding($false)
        }
        if (-not $process.Start()) {
            throw "Native process could not be started."
        }
        if ($startInfo.RedirectStandardInput) {
            $inputStream = [IO.File]::OpenRead([IO.Path]::GetFullPath($StandardInputPath))
            # Copy via the raw pipe. Closing Process.StandardInput's StreamWriter
            # on .NET Framework emits its encoding preamble and corrupts exact
            # SQL bytes. Construct that wrapper under BOM-less UTF-8, restore
            # the process-wide setting immediately, and close only BaseStream.
            $childInputStream = $process.StandardInput.BaseStream
            [Console]::InputEncoding = $originalInputEncoding
            $originalInputEncoding = $null
            $inputTask = $inputStream.CopyToAsync($childInputStream)
        }
        if ([String]::IsNullOrWhiteSpace($StandardOutputPath)) {
            $outputStream = [IO.Stream]::Null
        } else {
            $outputStream = [IO.File]::Open([IO.Path]::GetFullPath($StandardOutputPath), [IO.FileMode]::Create, [IO.FileAccess]::Write, [IO.FileShare]::None)
        }
        $errorStream = [IO.File]::Open([IO.Path]::GetFullPath($StandardErrorPath), [IO.FileMode]::Create, [IO.FileAccess]::Write, [IO.FileShare]::None)
        $outputTasks.Add($process.StandardOutput.BaseStream.CopyToAsync($outputStream))
        $outputTasks.Add($process.StandardError.BaseStream.CopyToAsync($errorStream))
        if ($startInfo.RedirectStandardInput) {
            # mysql waits for EOF. Finish the bounded file copy and close the
            # child stdin before waiting for process exit; stdout/stderr keep
            # draining concurrently so neither pipe can fill and deadlock.
            $inputTask.Wait()
            $childInputStream.Close()
        }
        $process.WaitForExit()
        [Threading.Tasks.Task]::WaitAll($outputTasks.ToArray())
        return [int]$process.ExitCode
    }
    finally {
        if ($null -ne $originalInputEncoding) { [Console]::InputEncoding = $originalInputEncoding }
        if ($inputStream) { $inputStream.Dispose() }
        if ($childInputStream) { $childInputStream.Dispose() }
        if ($outputStream -and $outputStream -ne [IO.Stream]::Null) { $outputStream.Dispose() }
        if ($errorStream) { $errorStream.Dispose() }
        $process.Dispose()
    }
}

function Invoke-SelfHandlerCompose {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true, ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    if (-not (Test-Path -LiteralPath $script:SelfHandlerComposePath -PathType Leaf)) {
        throw "Production Compose definition is missing from the qualified bundle."
    }
    Assert-ProtectedSecretFile -Path $script:SelfHandlerEnvironmentPath | Out-Null
    # Windows PowerShell 5.1 promotes ordinary native stderr progress to a
    # terminating NativeCommandError when the caller uses Stop. Compose writes
    # status lines such as "Container ... Running" to stderr even on exit 0, so
    # capture both streams under Continue and decide only from its exit code.
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $composeOutput = @(& docker compose -p $script:SelfHandlerComposeProject --env-file $script:SelfHandlerEnvironmentPath -f $script:SelfHandlerComposePath @Arguments 2>&1)
        $composeExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    $composeOutput | ForEach-Object { Write-Host ([string]$_) }
    if ($composeExitCode -ne 0) {
        throw "Docker Compose operation failed."
    }
}

function ConvertTo-EncodedPosixShellCommand {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$Script
    )

    # Windows PowerShell 5.1 removes embedded double quotes while constructing
    # native command lines. Encode the complete POSIX script so the argument
    # passed through docker.exe contains no quotes for PowerShell to rewrite.
    $utf8 = New-Object Text.UTF8Encoding($false)
    $payload = [Convert]::ToBase64String($utf8.GetBytes($Script))
    if ($payload -notmatch '^[A-Za-z0-9+/]+={0,2}$') {
        throw "Unable to encode the POSIX shell command safely."
    }
    return "printf %s $payload | base64 -d | sh"
}

function Get-DockerResourceLabel {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet("container", "network", "volume")]
        [string]$Type,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$Name,

        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$')]
        [string]$Label
    )

    $arguments = @("inspect", "--type", "container", "--format", "{{json .Config.Labels}}", $Name)
    if ($Type -eq "network") {
        $arguments = @("network", "inspect", "--format", "{{json .Labels}}", $Name)
    } elseif ($Type -eq "volume") {
        $arguments = @("volume", "inspect", "--format", "{{json .Labels}}", $Name)
    }
    $labelJson = @(& docker @arguments 2>$null)
    $dockerExit = $LASTEXITCODE
    if ($dockerExit -ne 0 -or $labelJson.Count -ne 1 -or [String]::IsNullOrWhiteSpace([string]$labelJson[0])) {
        throw "Unable to inspect the required Docker resource labels."
    }
    try {
        $labels = [string]$labelJson[0] | ConvertFrom-Json
    } catch {
        throw "Docker returned invalid resource label JSON."
    }
    if ($null -eq $labels) {
        return ""
    }
    $property = @($labels.PSObject.Properties | Where-Object { $_.Name -ceq $Label })
    if ($property.Count -ne 1) {
        return ""
    }
    return [string]$property[0].Value
}

function Invoke-DockerQuietProbe {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string[]]$Argument
    )

    # Expected non-zero readiness/existence probes must be decided only by the
    # native exit code. Windows PowerShell 5.1 otherwise promotes native stderr
    # to a terminating NativeCommandError when the caller uses Stop.
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = "Continue"
        & docker @Argument *> $null
        return [int]$LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
}

function Get-SelfHandlerContainerId {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet("web", "app", "db")]
        [string]$Service,
        [switch]$AllowMissing,
        [switch]$RunningOnly
    )

    $dockerArguments = @("ps", "-a")
    if ($RunningOnly) {
        $dockerArguments = @("ps")
    }
    $containers = @(& docker @dockerArguments --filter "label=com.docker.compose.project=$script:SelfHandlerComposeProject" --filter "label=com.docker.compose.service=$Service" --format "{{.ID}}")
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to resolve the fixed production container."
    }
    $containers = @($containers | Where-Object { -not [String]::IsNullOrWhiteSpace($_) })
    if ($containers.Count -eq 0 -and $AllowMissing) {
        return $null
    }
    if ($containers.Count -ne 1) {
        throw "Expected exactly one fixed production '$Service' container."
    }
    return $containers[0].Trim()
}

function Get-DockerInspection {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$ContainerId)

    $raw = & docker inspect $ContainerId
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to inspect the fixed production container."
    }
    return @($raw | ConvertFrom-Json)[0]
}

function Test-SelfHandlerNoNewPrivileges {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][psobject]$Inspection)

    return @($Inspection.HostConfig.SecurityOpt | ForEach-Object { [string]$_ }) -contains "no-new-privileges:true"
}

function Test-SelfHandlerMountContract {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][ValidateSet("web", "app", "db")][string]$Service,
        [Parameter(Mandatory = $true)][psobject]$Inspection
    )

    $expectedVolumeName = $null
    $expectedVolumeDestination = $null
    $expectedTmpfs = @("/tmp")
    if ($Service -eq "db") {
        $expectedVolumeName = $script:SelfHandlerDatabaseVolume
        $expectedVolumeDestination = "/var/lib/mysql"
        $expectedTmpfs = @("/run/mysqld", "/tmp")
    } elseif ($Service -eq "app") {
        $expectedVolumeName = $script:SelfHandlerPrivateFilesVolume
        $expectedVolumeDestination = "/app/storage/app/private"
        $expectedTmpfs = @("/tmp", "/app/bootstrap/cache", "/app/storage/framework", "/app/storage/logs")
    }

    $volumeMounts = @($Inspection.Mounts | Where-Object { [string]$_.Type -eq "volume" })
    $unexpectedMounts = @($Inspection.Mounts | Where-Object { [string]$_.Type -notin @("volume", "tmpfs") })
    if ($unexpectedMounts.Count -ne 0) { return $false }
    if ($null -eq $expectedVolumeName) {
        if ($volumeMounts.Count -ne 0) { return $false }
    } else {
        if ($volumeMounts.Count -ne 1) { return $false }
        $mount = $volumeMounts[0]
        $mountNameProperty = $mount.PSObject.Properties["Name"]
        if ($null -eq $mountNameProperty -or
            [string]$mountNameProperty.Value -ne $expectedVolumeName -or
            [string]$mount.Destination -ne $expectedVolumeDestination -or
            -not [bool]$mount.RW) {
            return $false
        }
    }

    $tmpfsConfiguration = $Inspection.HostConfig.Tmpfs
    $actualTmpfs = @()
    if ($null -ne $tmpfsConfiguration) {
        $actualTmpfs = @($tmpfsConfiguration.PSObject.Properties.Name | Sort-Object)
    }
    if (($actualTmpfs -join ",") -ne (($expectedTmpfs | Sort-Object) -join ",")) {
        return $false
    }
    $observedTmpfsMounts = @($Inspection.Mounts | Where-Object { [string]$_.Type -eq "tmpfs" } | ForEach-Object { [string]$_.Destination } | Sort-Object)
    if (@($observedTmpfsMounts | Where-Object { $expectedTmpfs -notcontains $_ }).Count -ne 0) {
        return $false
    }
    return $true
}

function Test-SelfHandlerReadiness {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet("Local", "Public")]
        [string]$Scope,
        [string]$ExpectedRevision,
        [ValidateRange(1, 30)]
        [int]$TimeoutSeconds = 10
    )

    $origin = $script:SelfHandlerLocalOrigin
    if ($Scope -eq "Public") {
        $origin = $script:SelfHandlerPublicOrigin
    }
    $stopwatch = [Diagnostics.Stopwatch]::StartNew()
    try {
        $response = Invoke-RestMethod -Uri ($origin + "/api/health") -TimeoutSec $TimeoutSeconds -Method Get
        $healthy = $response.status -eq "ok" -and [string]$response.release -match '^[0-9a-f]{40}$'
        if ($ExpectedRevision) {
            $healthy = $healthy -and [string]$response.release -eq $ExpectedRevision
        }
        return [pscustomobject][ordered]@{
            status = $(if ($healthy) { "healthy" } else { "unhealthy" })
            latency_ms = [Math]::Min(30000, [int]$stopwatch.ElapsedMilliseconds)
            release = $(if ($response.release) { [string]$response.release } else { $null })
        }
    }
    catch {
        return [pscustomobject][ordered]@{
            status = "unreachable"
            latency_ms = [Math]::Min(30000, [int]$stopwatch.ElapsedMilliseconds)
            release = $null
        }
    }
    finally {
        $stopwatch.Stop()
    }
}

function Get-SelfHandlerSchemaFingerprint {
    [CmdletBinding()]
    param([switch]$AllowEmpty)

    $container = Get-SelfHandlerContainerId -Service db -RunningOnly
    $queryCommand = ConvertTo-EncodedPosixShellCommand -Script 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT migration, batch FROM migrations ORDER BY migration;"'
    $output = @(& docker exec $container sh -c $queryCommand 2>$null)
    if ($LASTEXITCODE -ne 0) {
        if ($AllowEmpty) {
            return $script:SelfHandlerEmptySchemaFingerprint
        }
        throw "Unable to read migration state."
    }
    return Get-Sha256Text -Text (($output | ForEach-Object { $_.TrimEnd() }) -join "`n")
}

function Read-ValidatedReleaseManifest {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$Path)

    $manifest = Read-JsonFile -Path $Path
    if ($manifest.schema_version -ne 1 -or $manifest.deployment_id -ne $script:SelfHandlerDeploymentId) {
        throw "revision_mismatch"
    }
    if ($manifest.source_repository -ne "PanyaPrimal/selfHandlerApp" -or [string]$manifest.source_revision -notmatch '^[0-9a-f]{40}$') {
        throw "revision_mismatch"
    }
    foreach ($pair in @(
        @($manifest.web_image, $script:SelfHandlerWebRepository),
        @($manifest.app_image, $script:SelfHandlerAppRepository)
    )) {
        $image = $pair[0]
        if ($image.repository -ne $pair[1] -or [string]$image.digest -notmatch '^sha256:[0-9a-f]{64}$' -or $image.revision -ne $manifest.source_revision) {
            throw "revision_mismatch"
        }
    }
    foreach ($name in @("backend", "frontend", "e2e", "deployment", "production_smoke")) {
        if ($manifest.quality_evidence.$name.status -ne "passed") {
            throw "quality_evidence_failed"
        }
    }
    if ($manifest.image_integrity.web.subject_digest -ne $manifest.web_image.digest -or
        $manifest.image_integrity.app.subject_digest -ne $manifest.app_image.digest -or
        $manifest.image_integrity.web.verification_method -ne "same-run-manifest-and-oci-revision" -or
        $manifest.image_integrity.app.verification_method -ne "same-run-manifest-and-oci-revision") {
        throw "revision_mismatch"
    }
    return $manifest
}

function Get-ImageReference {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][ValidateSet("web", "app")][string]$Service,
        [Parameter(Mandatory = $true)][string]$Digest
    )

    if ($Digest -notmatch '^sha256:[0-9a-f]{64}$') {
        throw "Immutable image digest is invalid."
    }
    if ($Service -eq "web") {
        return "$script:SelfHandlerWebRepository@$Digest"
    }
    return "$script:SelfHandlerAppRepository@$Digest"
}

function Assert-ReleaseIsNew {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Manifest,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    $records = @()
    $recordsRoot = Join-Path $StateRoot "releases"
    if (Test-Path -LiteralPath $recordsRoot -PathType Container) {
        foreach ($file in @(Get-ChildItem -LiteralPath $recordsRoot -File -Filter "*.json" | Sort-Object Name)) {
            $record = Read-JsonFile -Path $file.FullName
            if ([string]$record.attempt_id -notmatch '^[A-Za-z0-9._-]{8,128}$' -or
                $file.Name -ne (([string]$record.attempt_id) + ".json")) {
                throw "Stored canonical release history is invalid."
            }
            $records += $record
        }
    }
    $records += @(Get-PendingReleaseRecords -StateRoot $StateRoot)
    foreach ($record in @($records)) {
        if (
            $record.source_revision -eq $Manifest.source_revision -and
            $record.web_digest -eq $Manifest.web_image.digest -and
            $record.app_digest -eq $Manifest.app_image.digest
        ) {
            throw "duplicate_release"
        }
    }
}

function Save-PendingBackupEvidence {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Evidence,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    $path = Join-Path (Join-Path $StateRoot "pending-backups") (([string]$Evidence.bundle_id) + ".json")
    Write-AtomicJson -Path $path -Value $Evidence
    return $path
}

function Bind-OffHostBackupReference {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][psobject]$Evidence,
        [Parameter(Mandatory = $true)][ValidateLength(1, 512)][string]$Reference,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    if ($Reference -match '[\r\n]' -or $Reference -notmatch [regex]::Escape([string]$Evidence.bundle_id)) {
        throw "backup_not_off_host"
    }
    $completed = [pscustomobject][ordered]@{
        schema_version = 1
        bundle_id = [string]$Evidence.bundle_id
        deployment_id = $script:SelfHandlerDeploymentId
        created_at = [string]$Evidence.created_at
        reason = [string]$Evidence.reason
        source_release = $Evidence.source_release
        ciphertext_bytes = [Int64]$Evidence.ciphertext_bytes
        ciphertext_sha256 = [string]$Evidence.ciphertext_sha256
        off_host_reference = $Reference
        validated_at = [DateTime]::UtcNow.ToString("o")
        status = "valid"
    }
    $backupsRoot = Join-Path $StateRoot "backups"
    Write-AtomicJson -Path (Join-Path $backupsRoot (([string]$Evidence.bundle_id) + ".json")) -Value $completed
    Write-AtomicJson -Path (Join-Path $StateRoot "latest-backup.json") -Value $completed
    return $completed
}

function Get-LatestPendingBackup {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$Reason,
        [string]$StateRoot = $script:SelfHandlerStateRoot
    )

    $root = Join-Path $StateRoot "pending-backups"
    if (-not (Test-Path -LiteralPath $root -PathType Container)) {
        throw "backup_not_off_host"
    }
    $candidates = @(Get-ChildItem -LiteralPath $root -File -Filter "*.json" | Sort-Object LastWriteTimeUtc -Descending)
    foreach ($candidate in $candidates) {
        $evidence = Read-JsonFile -Path $candidate.FullName
        if ($evidence.reason -eq $Reason -and $evidence.deployment_id -eq $script:SelfHandlerDeploymentId) {
            return $evidence
        }
    }
    throw "backup_not_off_host"
}
