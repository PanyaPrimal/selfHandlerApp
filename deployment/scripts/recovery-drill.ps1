[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$BundlePath,

    [Parameter(Mandatory = $true)]
    [string]$IdentityPath
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "shared.ps1")

function Assert-DisposableResourceLabel {
    param(
        [Parameter(Mandatory = $true)][ValidateSet("container", "network", "volume")][string]$Type,
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Project
    )

    if ($Project -notmatch '^selfhandler-drill-[a-f0-9]{8,32}$' -or $Project -in @("selfhandler", "dealflow")) {
        throw "Refusing cleanup for an unsafe drill project."
    }
    try {
        $label = Get-DockerResourceLabel -Type $Type -Name $Name -Label "com.docker.compose.project"
    } catch {
        throw "Refusing cleanup for a resource without the exact disposable project label."
    }
    if ($label -ne $Project) {
        throw "Refusing cleanup for a resource without the exact disposable project label."
    }
}

function Remove-DisposableRestoreResources {
    param([Parameter(Mandatory = $true)][string]$Project)

    if ($Project -notmatch '^selfhandler-drill-[a-f0-9]{8,32}$') {
        throw "Refusing cleanup for an unsafe drill project."
    }
    $containers = @(& docker ps -a --filter "label=com.docker.compose.project=$Project" --format "{{.ID}}")
    foreach ($container in $containers) {
        if (-not [String]::IsNullOrWhiteSpace($container)) {
            Assert-DisposableResourceLabel -Type container -Name $container -Project $Project
            & docker rm --force $container *> $null
        }
    }
    foreach ($network in @("${Project}_app", "${Project}_data")) {
        & docker network inspect $network *> $null
        if ($LASTEXITCODE -eq 0) {
            Assert-DisposableResourceLabel -Type network -Name $network -Project $Project
            & docker network rm $network *> $null
        }
    }
    foreach ($volume in @("${Project}_mysql_data", "${Project}_private_files")) {
        & docker volume inspect $volume *> $null
        if ($LASTEXITCODE -eq 0) {
            Assert-DisposableResourceLabel -Type volume -Name $volume -Project $Project
            & docker volume rm $volume *> $null
        }
    }
}

$project = "selfhandler-drill-$([Guid]::NewGuid().ToString('N').Substring(0, 12))"
$started = [DateTime]::UtcNow
try {
    $output = & (Join-Path $PSScriptRoot "restore-production.ps1") -RecoveryMode Drill -BundlePath $BundlePath -IdentityPath $IdentityPath -DisposableProject $project
    if ($LASTEXITCODE -ne 0) {
        throw "Disposable recovery drill failed."
    }
    $result = $output | Select-Object -Last 1 | ConvertFrom-Json
    $evidence = [pscustomobject][ordered]@{
        status = [string]$result.status
        bundle_id = [string]$result.bundle_id
        disposable_project = $project
        duration_seconds = [Math]::Round(([DateTime]::UtcNow - $started).TotalSeconds, 3)
        production_resources_touched = 0
    }
    Write-Output ($evidence | ConvertTo-Json -Compress)
}
finally {
    Remove-DisposableRestoreResources -Project $project
}
