$projectRoot = Split-Path $PSScriptRoot -Parent
$localPhp = Join-Path $projectRoot '.tools/php'
if (-not (Test-Path (Join-Path $localPhp 'php.exe'))) {
    throw 'Ejecuta scripts/install-local-tools.ps1 antes de cargar el entorno.'
}
$env:PATH = $localPhp + ';' + $env:PATH
$env:COMPOSER_CACHE_DIR = Join-Path $projectRoot '.tools/composer-cache'
$env:npm_config_cache = Join-Path $projectRoot '.tools/npm-cache'
$env:OPENSSL_CONF = Join-Path $localPhp 'extras/ssl/openssl.cnf'
