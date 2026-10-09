param([ValidateSet('staging','production')][string] $Environment = 'staging')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$php = Join-Path $projectRoot '.tools/php/php.exe'
$env:PATH = (Split-Path $php -Parent) + ';' + $env:PATH
$env:APP_ENV = $Environment
Set-Location -LiteralPath $projectRoot
while ($true) {
    & $php artisan outbox:work (('--env=') + $Environment) --no-interaction
    Write-Output 'STAGE_WORKER_RESTART: reintentando en cinco segundos.'
    Start-Sleep -Seconds 5
}
