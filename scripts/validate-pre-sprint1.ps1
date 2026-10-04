$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $projectRoot
$php = Join-Path $projectRoot '.tools/php/php.exe'
if (-not (Test-Path -LiteralPath $php)) { throw 'Instala el toolchain con scripts/install-local-tools.ps1.' }
$artifactRoot = Join-Path $projectRoot 'artifacts/local-validation'
New-Item -ItemType Directory -Force -Path $artifactRoot | Out-Null
$records = @()
$versions = [ordered]@{php=(& $php -r 'echo PHP_VERSION;'); composer=(& $php .tools/composer.phar --version --no-ansi); node=(& node --version); npm=(& npm.cmd --version)}
function Invoke-Check([string] $name, [string] $executable, [string[]] $arguments) {
    $log = Join-Path $artifactRoot ($name + '.log')
    $started = [DateTime]::UtcNow.ToString('o')
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $executable @arguments 2>&1 | ForEach-Object { $_.ToString() } | Tee-Object -FilePath $log | Out-Host
        $result = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previousPreference }
    $script:records += [ordered]@{name=$name; command=($executable + ' ' + ($arguments -join ' ')); executedAt=$started; exitCode=$result; artifact=('artifacts/local-validation/' + $name + '.log'); sha256=(Get-FileHash -LiteralPath $log -Algorithm SHA256).Hash.ToLowerInvariant()}
    if ($result -ne 0) { throw ('Falló ' + $name + '; consulta ' + $log) }
}
try {
    Invoke-Check 'composer-validate' $php @('.tools/composer.phar','validate','--strict')
    Invoke-Check 'platform' $php @('.tools/composer.phar','check-platform-reqs')
    Invoke-Check 'phpunit' $php @('vendor/bin/phpunit','--log-junit','artifacts/local-validation/phpunit.xml')
    Invoke-Check 'test-database' $php @('artisan','modernization:prepare-test-database')
    Invoke-Check 'mysql' $php @('vendor/bin/phpunit','-c','phpunit.mysql.xml','--log-junit','artifacts/local-validation/mysql.xml')
    Invoke-Check 'phpstan' $php @('vendor/bin/phpstan','analyse','--no-progress','--memory-limit=512M')
    Invoke-Check 'openapi' 'npm.cmd' @('run','openapi:lint')
    Invoke-Check 'angular-client' 'npm.cmd' @('--prefix','frontend','run','api:check')
    Invoke-Check 'angular-build' 'npm.cmd' @('--prefix','frontend','run','build')
    Invoke-Check 'angular-tests' 'npm.cmd' @('--prefix','frontend','test','--','--watch=false')
    Invoke-Check 'gate-tests' 'npm.cmd' @('run','test:gate')
    Invoke-Check 'composer-audit' $php @('.tools/composer.phar','audit','--locked')
    Invoke-Check 'npm-audit' 'npm.cmd' @('audit')
    Invoke-Check 'frontend-audit' 'npm.cmd' @('--prefix','frontend','audit')
    Invoke-Check 'preflight' $php @('artisan','modernization:preflight','--oidc')
} finally {
    $head = (& git rev-parse HEAD).Trim()
    $dirty = [bool] (& git status --porcelain)
    $manifest = [ordered]@{commit=$head; workingTreeDirty=$dirty; evaluatedAt=[DateTime]::UtcNow.ToString('o'); environment='local development / isolated MySQL test'; toolchain=$versions; checks=$records}
    [IO.File]::WriteAllText((Join-Path $artifactRoot 'manifest.json'), ($manifest | ConvertTo-Json -Depth 10), (New-Object Text.UTF8Encoding($false)))
}
& node scripts/pre-sprint1-gate.mjs
exit $LASTEXITCODE
