[CmdletBinding()]
param(
    [ValidateRange(1, 65535)][int] $Port = 8000,
    [switch] $Check,
    [string] $ProjectRoot = ''
)

$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path $PSScriptRoot -Parent
}
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$phpRoot = Join-Path $ProjectRoot '.tools/php'
$php = Join-Path $phpRoot 'php.exe'
$ini = Join-Path $phpRoot 'php.ini'
if (-not (Test-Path -LiteralPath $php) -or -not (Test-Path -LiteralPath $ini)) {
    throw 'Instala el PHP local con scripts/install-local-tools.ps1.'
}

$modules = & $php -c $ini -m
if ($LASTEXITCODE -ne 0) { throw 'No se pudo cargar la configuración PHP local.' }
foreach ($extension in @('pdo_mysql', 'pdo_sqlite', 'mbstring', 'openssl', 'curl', 'fileinfo')) {
    if ($modules -notcontains $extension) {
        throw ('El PHP local requiere la extensión ' + $extension + '.')
    }
}
$version = & $php -c $ini -r 'echo PHP_VERSION;'
if ($LASTEXITCODE -ne 0) { throw 'No se pudo identificar la versión PHP local.' }
Write-Host ('PHP ' + $version + ' / PDO MySQL y SQLite disponibles.')
Write-Host ('Configuración: ' + $ini)
if ($Check) { exit 0 }

$env:PATH = $phpRoot + ';' + $env:PATH
$env:PHPRC = $ini
$env:OPENSSL_CONF = Join-Path $phpRoot 'extras/ssl/openssl.cnf'
Push-Location -LiteralPath $ProjectRoot
try {
    & $php -c $ini artisan serve --host=127.0.0.1 --port=$Port --no-interaction
    $result = $LASTEXITCODE
} finally { Pop-Location }
exit $result
