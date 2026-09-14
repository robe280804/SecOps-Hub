[CmdletBinding()]
param([switch] $SkipImageBuild)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$environmentPath = Join-Path $projectRoot 'backend/.env'
$composePath = Join-Path $projectRoot 'compose.local.yaml'
$composeEnvironmentPath = Join-Path $projectRoot 'docker/terminal/.env'

# Persist local gateway routing so subsequent Compose invocations can reuse it.
$frontendEnvironmentPath = Join-Path $projectRoot 'frontend/.env.local'
$terminalSettings = @{}
if (Test-Path -LiteralPath $frontendEnvironmentPath) {
    foreach ($line in [IO.File]::ReadAllLines($frontendEnvironmentPath)) {
        if ($line -match '^(API_PROXY_TARGET|API_PROXY_CA_FILE)=(.*)$') {
            $terminalSettings[$Matches[1]] = $Matches[2].Trim().Trim('"', "'")
        }
    }
}
if ($terminalSettings['API_PROXY_TARGET']) {
    $terminalApi = [uri] $terminalSettings['API_PROXY_TARGET']
    $gatewaySettings = @(
        'SECOPS_TERMINAL_API_URL=' + $terminalApi.AbsoluteUri.TrimEnd('/')
        'SECOPS_TERMINAL_API_HOST=' + $terminalApi.Host
    )
    if ($terminalSettings['API_PROXY_CA_FILE']) {
        Copy-Item -LiteralPath $terminalSettings['API_PROXY_CA_FILE'] -Destination (Join-Path $projectRoot 'docker/terminal/trust/backend-ca.crt')
        $gatewaySettings += 'SECOPS_TERMINAL_CA_FILE=/trust/backend-ca.crt'
    }
    [IO.File]::WriteAllLines($composeEnvironmentPath, $gatewaySettings, [Text.UTF8Encoding]::new($false))
}

function Invoke-Docker {
    param([string[]] $Arguments)
    if ($Arguments[0] -eq 'compose' -and (Test-Path -LiteralPath $composeEnvironmentPath)) {
        $Arguments = @('compose', '--env-file', $composeEnvironmentPath) + $Arguments[1..($Arguments.Length - 1)]
    }
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed (exit $LASTEXITCODE). Fix the reported problem and run this script again."
    }
}

if (-not (Test-Path -LiteralPath $environmentPath -PathType Leaf)) {
    throw 'Configure backend/.env before starting the local runtime.'
}
$environmentText = [IO.File]::ReadAllText($environmentPath)
if ($environmentText -notmatch '(?m)^APP_ENV=local\s*$') {
    throw 'This setup is only for APP_ENV=local.'
}

Invoke-Docker -Arguments @('info', '--format', '{{.OSType}}')
foreach ($setting in @{
    ENVIRONMENTS_RUNTIME_ENABLED = 'true'
    ENVIRONMENTS_TERMINAL_ENABLED = 'true'
    ENVIRONMENTS_ALLOW_UNLIMITED_STORAGE = 'true'
    ENVIRONMENTS_QUEUE_CONNECTION = 'redis'
    REDIS_QUEUE_RETRY_AFTER = '240'
}.GetEnumerator()) {
    $pattern = '(?m)^' + [regex]::Escape($setting.Key) + '=.*$'
    $line = $setting.Key + '=' + $setting.Value
    if ([regex]::IsMatch($environmentText, $pattern)) {
        $environmentText = [regex]::Replace($environmentText, $pattern, $line)
    } else {
        $environmentText = $environmentText.TrimEnd() + "`r`n" + $line + "`r`n"
    }
}
[IO.File]::WriteAllText($environmentPath, $environmentText, [Text.UTF8Encoding]::new($false))

if (-not $SkipImageBuild) {
    Invoke-Docker -Arguments @('compose', '-f', $composePath, '--profile', 'images', 'build', 'kali-image')
}
Invoke-Docker -Arguments @('compose', '-f', $composePath, 'build', 'worker', 'terminal')
Invoke-Docker -Arguments @('compose', '-f', $composePath, 'run', '--rm', '--no-deps', 'worker', 'php', 'artisan', 'migrate', '--force', '--no-interaction')
Invoke-Docker -Arguments @('compose', '-f', $composePath, 'run', '--rm', '--no-deps', 'worker', 'php', 'artisan', 'environments:check')
Invoke-Docker -Arguments @('compose', '-f', $composePath, 'up', '-d', '--wait', '--wait-timeout', '120', 'worker', 'scheduler', 'terminal')
Write-Host 'Local environment startup is ready. Activate your project, reload its details, and click Start environment.'
