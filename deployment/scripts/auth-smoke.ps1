[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-f]{40}$')]
    [string]$ExpectedRevision,

    [switch]$Bootstrap
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "shared.ps1")

function Get-ResponseStatusCode {
    param([Parameter(Mandatory = $true)][Management.Automation.ErrorRecord]$ErrorRecord)

    if ($ErrorRecord.Exception.Response -and $ErrorRecord.Exception.Response.StatusCode) {
        return [int]$ErrorRecord.Exception.Response.StatusCode
    }
    return 0
}

function Get-CsrfHeaders {
    param(
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][Uri]$Origin
    )

    $cookies = $Session.Cookies.GetCookies($Origin)
    $token = $cookies["XSRF-TOKEN"]
    if ($null -eq $token -or [String]::IsNullOrWhiteSpace($token.Value)) {
        throw "Authentication smoke did not receive the CSRF cookie."
    }
    $originText = $Origin.GetLeftPart([UriPartial]::Authority)
    return @{
        Accept = "application/json"
        "Content-Type" = "application/json"
        "X-XSRF-TOKEN" = [Uri]::UnescapeDataString($token.Value)
        Origin = $originText
        Referer = ($originText + "/")
    }
}

function Assert-BootstrapUserTableEmpty {
    [CmdletBinding()]
    param()

    $database = Get-SelfHandlerContainerId -Service db -RunningOnly
    $countText = [string](& docker exec $database sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM users;"')
    $countText = $countText.Trim()
    if ($LASTEXITCODE -ne 0 -or $countText -notmatch '^[0-9]+$' -or [int64]$countText -ne 0) {
        throw "Bootstrap registration requires an empty users table."
    }
}

function New-BootstrapInvitation {
    [CmdletBinding()]
    param()

    $application = Get-SelfHandlerContainerId -Service app -RunningOnly
    $output = [string](& docker exec $application php artisan invite:create --note=selfhandler-probe-bootstrap --no-ansi)
    if ($LASTEXITCODE -ne 0) {
        throw "Bootstrap probe invitation creation failed."
    }
    $match = [regex]::Match($output, '\b[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){2}\b')
    if (-not $match.Success) {
        throw "Bootstrap probe invitation creation failed."
    }
    return $match.Value
}

function Invoke-AuthenticationSmoke {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$Revision,
        [switch]$AllowRegistration
    )

    Assert-ProtectedSecretFile -Path $script:SelfHandlerOpsConfigPath | Out-Null
    $configuration = Read-KeyValueFile -Path $script:SelfHandlerOpsConfigPath
    if (-not $configuration.ContainsKey("PROBE_ACCOUNT_EMAIL") -or [string]$configuration["PROBE_ACCOUNT_EMAIL"] -notmatch '^[^\s@]+@[^\s@]+\.[^\s@]+$') {
        throw "The probe account email is not configured."
    }
    $email = [string]$configuration["PROBE_ACCOUNT_EMAIL"]
    $name = "SelfHandler Probe"
    if ($configuration.ContainsKey("PROBE_ACCOUNT_NAME") -and -not [String]::IsNullOrWhiteSpace([string]$configuration["PROBE_ACCOUNT_NAME"])) {
        $name = [string]$configuration["PROBE_ACCOUNT_NAME"]
    }
    $passwordPath = Join-Path $script:SelfHandlerSecretRoot "probe-account-password.txt"
    if (-not $configuration.ContainsKey("PROBE_ACCOUNT_PASSWORD_PATH") -or
        [IO.Path]::GetFullPath([string]$configuration["PROBE_ACCOUNT_PASSWORD_PATH"]) -ne [IO.Path]::GetFullPath($passwordPath)) {
        throw "The probe credential path must equal the fixed protected path."
    }
    $passwordPath = Assert-ProtectedSecretFile -Path $passwordPath -MinimumBytes 12
    $password = [IO.File]::ReadAllText($passwordPath).TrimEnd("`r", "`n")
    if ($password.Length -lt 12) {
        throw "The configured probe credential does not meet the application minimum."
    }

    $origin = [Uri]$script:SelfHandlerPublicOrigin
    $originText = $origin.GetLeftPart([UriPartial]::Authority)
    $statefulHeaders = @{
        Accept = "application/json"
        Origin = $originText
        Referer = ($originText + "/")
    }
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $csrfResponse = Invoke-WebRequest -Uri ("{0}/sanctum/csrf-cookie" -f $origin.AbsoluteUri.TrimEnd("/")) -Method Get -WebSession $session -Headers $statefulHeaders -TimeoutSec 20 -UseBasicParsing
    if ([int]$csrfResponse.StatusCode -ne 204) {
        throw "Authentication smoke could not initialize CSRF protection."
    }
    $setCookie = [string]$csrfResponse.Headers["Set-Cookie"]
    if (
        $setCookie -notmatch '(?i)selfhandler_session=' -or
        $setCookie -notmatch '(?i)selfhandler_session=[^,;]*(?:;[^,]*)*;\s*secure' -or
        $setCookie -notmatch '(?i)selfhandler_session=[^,;]*(?:;[^,]*)*;\s*httponly' -or
        $setCookie -notmatch '(?i)samesite=lax'
    ) {
        throw "Production session-cookie attributes do not match the fixed security contract."
    }
    $headers = Get-CsrfHeaders -Session $session -Origin $origin
    $loginBody = ConvertTo-CompactJson -Value ([ordered]@{ email = $email; password = $password })
    $authenticated = $false
    try {
        $loginResponse = Invoke-WebRequest -Uri ("{0}/api/auth/login" -f $origin.AbsoluteUri.TrimEnd("/")) -Method Post -WebSession $session -Headers $headers -Body $loginBody -TimeoutSec 20 -UseBasicParsing
        $authenticated = [int]$loginResponse.StatusCode -eq 200
    }
    catch {
        if (-not $AllowRegistration -or (Get-ResponseStatusCode -ErrorRecord $_) -notin @(401, 422)) {
            throw "Probe account login failed."
        }
    }

    if (-not $authenticated) {
        Assert-BootstrapUserTableEmpty
        $inviteCode = New-BootstrapInvitation
        $registerBody = ConvertTo-CompactJson -Value ([ordered]@{
            name = $name
            email = $email
            password = $password
            password_confirmation = $password
            invite_code = $inviteCode
        })
        try {
            $registerResponse = Invoke-WebRequest -Uri ("{0}/api/auth/register" -f $origin.AbsoluteUri.TrimEnd("/")) -Method Post -WebSession $session -Headers $headers -Body $registerBody -TimeoutSec 20 -UseBasicParsing
        }
        catch {
            throw "Bootstrap probe account registration failed."
        }
        if ([int]$registerResponse.StatusCode -ne 201) {
            throw "Bootstrap probe account registration failed."
        }
    }

    $health = Invoke-RestMethod -Uri ("{0}/api/health" -f $origin.AbsoluteUri.TrimEnd("/")) -Method Get -Headers $statefulHeaders -WebSession $session -TimeoutSec 20
    if ($health.status -ne "ok" -or [string]$health.release -ne $Revision) {
        throw "Public authentication smoke observed an unexpected release."
    }
    $date = [DateTime]::UtcNow.ToString("yyyy-MM-dd")
    try {
        $owned = Invoke-WebRequest -Uri ("{0}/api/today?date={1}" -f $origin.AbsoluteUri.TrimEnd("/"), $date) -Method Get -WebSession $session -Headers $statefulHeaders -TimeoutSec 20 -UseBasicParsing
    }
    catch {
        throw "The authenticated owned-data probe failed."
    }
    if ([int]$owned.StatusCode -ne 200) {
        throw "The authenticated owned-data probe failed."
    }
    $headers = Get-CsrfHeaders -Session $session -Origin $origin
    $logout = Invoke-WebRequest -Uri ("{0}/api/auth/logout" -f $origin.AbsoluteUri.TrimEnd("/")) -Method Post -WebSession $session -Headers $headers -Body "{}" -TimeoutSec 20 -UseBasicParsing
    if ([int]$logout.StatusCode -ne 204) {
        throw "Probe account logout failed."
    }
    try {
        Invoke-WebRequest -Uri ("{0}/api/today?date={1}" -f $origin.AbsoluteUri.TrimEnd("/"), $date) -Method Get -WebSession $session -Headers $statefulHeaders -TimeoutSec 20 -UseBasicParsing | Out-Null
        throw "Protected data remained reachable after logout."
    }
    catch {
        if ((Get-ResponseStatusCode -ErrorRecord $_) -ne 401) {
            throw "Post-logout authorization verification failed."
        }
    }
    return [pscustomobject][ordered]@{
        status = "passed"
        release = $Revision
        registration_performed = [bool](-not $authenticated)
        owned_data = "reachable_while_authenticated"
        logout = "invalidated"
    }
}

$result = Invoke-AuthenticationSmoke -Revision $ExpectedRevision -AllowRegistration:$Bootstrap
Write-Output ($result | ConvertTo-Json -Compress)
