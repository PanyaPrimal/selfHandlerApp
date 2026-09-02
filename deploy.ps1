[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

$PublicRepository = 'PanyaPrimal/selfHandlerApp'
$OperationsRepository = 'PanyaPrimal/selfhandler-ops'
$Workflow = 'deploy-selfhandler.yml'
$Branch = 'master'
$RepositoryRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$script:GitHubCli = $null
$script:GitHubCliRoot = $null
$hadGitHubToken = Test-Path Env:GH_TOKEN
$previousGitHubToken = if ($hadGitHubToken) { $env:GH_TOKEN } else { $null }

function Get-VerifiedGitHubCli {
    $version = '2.97.0'
    $expectedArchiveSha = '35d7fe05c4dd1411ffda1e73dfc7c6f44b75c936ca51fa6595c657fdc0350cec'
    $cacheRoot = Join-Path $RepositoryRoot '_tmp\trusted-tools'
    if (-not (Test-Path -LiteralPath $cacheRoot -PathType Container)) {
        New-Item -ItemType Directory -Path $cacheRoot | Out-Null
    }
    $archive = Join-Path $cacheRoot "gh_${version}_windows_amd64.zip"
    if (-not (Test-Path -LiteralPath $archive -PathType Leaf)) {
        $partial = "$archive.$([guid]::NewGuid().ToString('N')).partial"
        Invoke-WebRequest `
            -UseBasicParsing `
            -Uri "https://github.com/cli/cli/releases/download/v$version/gh_${version}_windows_amd64.zip" `
            -OutFile $partial
        if ((Get-FileHash -Algorithm SHA256 -LiteralPath $partial).Hash.ToLowerInvariant() -ne $expectedArchiveSha) {
            [IO.File]::Delete($partial)
            throw 'Portable GitHub CLI archive checksum verification failed.'
        }
        [IO.File]::Move($partial, $archive)
    }
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant() -ne $expectedArchiveSha) {
        throw 'The cached portable GitHub CLI archive failed checksum verification.'
    }
    $extractRoot = Join-Path ([IO.Path]::GetTempPath()) ("selfhandler-gh-$version-" + [guid]::NewGuid().ToString('N'))
    $script:GitHubCliRoot = $extractRoot
    Expand-Archive -LiteralPath $archive -DestinationPath $extractRoot
    $executable = Join-Path $extractRoot 'bin\gh.exe'
    if (-not (Test-Path -LiteralPath $executable -PathType Leaf)) {
        throw 'The verified portable GitHub CLI archive contains no gh.exe.'
    }
    return $executable
}

function Set-ProcessGitHubCredentialFromGit {
    if (Test-Path Env:GH_TOKEN) {
        return
    }
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $credentialOutput = @("protocol=https`nhost=github.com`n`n" | & git credential fill 2>$null)
        $credentialExit = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($credentialExit -ne 0) {
        return
    }
    foreach ($line in $credentialOutput) {
        $separator = $line.IndexOf('=')
        if ($separator -le 0) {
            continue
        }
        $name = $line.Substring(0, $separator)
        if ($name -eq 'password') {
            $token = $line.Substring($separator + 1)
            if (-not [string]::IsNullOrWhiteSpace($token)) {
                $env:GH_TOKEN = $token
                return
            }
        }
    }
}

function Invoke-GitHubCli {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)

    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& $script:GitHubCli @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($exitCode -ne 0) {
        throw "GitHub CLI command failed. No credential output was retained."
    }
    return ($output -join [Environment]::NewLine)
}

function ConvertFrom-JsonArray {
    param([Parameter(Mandatory = $true)][string]$Json)

    if ([String]::IsNullOrWhiteSpace($Json) -or $Json.Trim() -eq '[]') {
        return
    }
    $parsed = $Json | ConvertFrom-Json
    foreach ($item in @($parsed)) {
        Write-Output $item
    }
}

Push-Location $RepositoryRoot
try {
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
        throw 'Git is required on the developer workstation.'
    }
    $script:GitHubCli = Get-VerifiedGitHubCli
    Set-ProcessGitHubCredentialFromGit
    if (-not (Test-Path Env:GH_TOKEN)) {
        throw 'No GitHub credential is available from GH_TOKEN or Git Credential Manager.'
    }
    Invoke-GitHubCli @('auth', 'status', '--hostname', 'github.com') | Out-Null

    $localRepository = Invoke-GitHubCli @('repo', 'view', '--json', 'nameWithOwner', '--jq', '.nameWithOwner')
    if ($localRepository.Trim() -ne $PublicRepository) {
        throw "This launcher is fixed to $PublicRepository."
    }

    $workingTree = @(& git status --porcelain)
    if ($LASTEXITCODE -ne 0 -or $workingTree.Count -gt 0) {
        throw 'Commit and push the reviewed working tree before deployment.'
    }
    $currentBranch = (& git branch --show-current).Trim()
    if ($LASTEXITCODE -ne 0 -or $currentBranch -ne $Branch) {
        throw "Deployment must be launched from the fixed '$Branch' branch."
    }

    & git fetch --quiet origin $Branch
    if ($LASTEXITCODE -ne 0) {
        throw "Unable to fetch origin/$Branch."
    }
    $localSha = (& git rev-parse HEAD).Trim()
    $remoteSha = (& git rev-parse "origin/$Branch").Trim()
    if ($LASTEXITCODE -ne 0 -or $localSha -ne $remoteSha) {
        throw "Local $Branch must exactly match the reviewed origin/$Branch revision."
    }

    Invoke-GitHubCli @('workflow', 'view', $Workflow, '--repo', $OperationsRepository) | Out-Null
    $existingJson = Invoke-GitHubCli @(
        'run', 'list',
        '--repo', $OperationsRepository,
        '--workflow', $Workflow,
        '--branch', $Branch,
        '--event', 'repository_dispatch',
        '--limit', '20',
        '--json', 'databaseId'
    )
    $existingIds = @(ConvertFrom-JsonArray -Json $existingJson | ForEach-Object { [long]$_.databaseId })

    $requestId = [guid]::NewGuid().ToString('N')
    Write-Host "Starting the trusted SelfHandler homelab workflow for public revision $remoteSha."
    Invoke-GitHubCli @(
        'api', '--method', 'POST',
        "repos/$OperationsRepository/dispatches",
        '-f', 'event_type=deploy-selfhandler',
        '-f', "client_payload[request_id]=$requestId",
        '-f', "client_payload[source_repository]=$PublicRepository",
        '-f', "client_payload[source_revision]=$remoteSha"
    ) | Out-Null

    $run = $null
    $expectedTitle = "Deploy SelfHandler $remoteSha request $requestId"
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        Start-Sleep -Seconds 2
        $runsJson = Invoke-GitHubCli @(
            'run', 'list',
            '--repo', $OperationsRepository,
            '--workflow', $Workflow,
            '--branch', $Branch,
            '--event', 'repository_dispatch',
            '--limit', '20',
            '--json', 'databaseId,displayTitle,status,url'
        )
        $run = @(ConvertFrom-JsonArray -Json $runsJson) |
            Where-Object {
                $existingIds -notcontains [long]$_.databaseId -and
                [string]$_.displayTitle -eq $expectedTitle
            } |
            Sort-Object databaseId -Descending |
            Select-Object -First 1
        if ($run) {
            break
        }
    }
    if (-not $run) {
        throw 'The private workflow was dispatched but its run was not found within 60 seconds.'
    }

    Write-Host "Trusted workflow: $($run.url)"
    & $script:GitHubCli run watch $run.databaseId --repo $OperationsRepository --compact --exit-status
    if ($LASTEXITCODE -ne 0) {
        Write-Host 'The first attempt did not complete; requesting one crash-safe rerun of failed jobs in the same immutable workflow run.'
        Invoke-GitHubCli @(
            'run', 'rerun', [string]$run.databaseId,
            '--repo', $OperationsRepository,
            '--failed'
        ) | Out-Null
        Start-Sleep -Seconds 2
        & $script:GitHubCli run watch $run.databaseId --repo $OperationsRepository --compact --exit-status
        if ($LASTEXITCODE -ne 0) {
            throw "Production deployment failed after its single crash-safe retry. Inspect run $($run.databaseId) in the private operations repository."
        }
    }
    Write-Host 'SelfHandler production deployment completed successfully.'
}
finally {
    try {
        if (-not [string]::IsNullOrWhiteSpace($script:GitHubCliRoot)) {
            $temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
            $cliRoot = [IO.Path]::GetFullPath($script:GitHubCliRoot)
            if ($cliRoot.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase) -and
                [IO.Path]::GetFileName($cliRoot) -match '^selfhandler-gh-2\.97\.0-[0-9a-f]{32}$' -and
                (Test-Path -LiteralPath $cliRoot -PathType Container)) {
                Remove-Item -LiteralPath $cliRoot -Recurse -Force
            }
        }
    }
    finally {
        try {
            if ($hadGitHubToken) {
                $env:GH_TOKEN = $previousGitHubToken
            }
            else {
                Remove-Item Env:GH_TOKEN -ErrorAction SilentlyContinue
            }
        }
        finally {
            Pop-Location
        }
    }
}
