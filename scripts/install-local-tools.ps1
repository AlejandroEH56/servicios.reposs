$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$toolsRoot = Join-Path $projectRoot '.tools'
New-Item -ItemType Directory -Force $toolsRoot | Out-Null
$phpArchive = Join-Path $toolsRoot 'php-8.4.26.zip'
$phpHash = 'da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7'
if (-not (Test-Path $phpArchive)) {
    Invoke-WebRequest 'https://www.php.net/~windows/releases/php-8.4.26-nts-Win32-vs17-x64.zip' -OutFile $phpArchive
}
if ((Get-FileHash $phpArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $phpHash) {
    throw 'Checksum PHP incorrecto. No se ejecutó el binario.'
}
$phpRoot = Join-Path $toolsRoot 'php'
if (-not (Test-Path (Join-Path $phpRoot 'php.exe'))) {
    Expand-Archive -LiteralPath $phpArchive -DestinationPath $phpRoot
}
$iniFile = Join-Path $phpRoot 'php.ini'
if (-not (Test-Path $iniFile)) {
    $ini = Get-Content (Join-Path $phpRoot 'php.ini-development') -Raw
    $ini = $ini.Replace(';extension_dir = "ext"', 'extension_dir = "ext"')
    foreach ($extension in @('curl', 'fileinfo', 'mbstring', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'sqlite3', 'zip', 'intl')) {
        $ini = $ini.Replace(';extension=' + $extension, 'extension=' + $extension)
    }
    Set-Content -LiteralPath $iniFile -Value $ini
}
$composerFile = Join-Path $toolsRoot 'composer.phar'
if (-not (Test-Path $composerFile)) {
    Invoke-WebRequest 'https://getcomposer.org/download/2.10.3/composer.phar' -OutFile $composerFile
}
if ((Get-FileHash $composerFile -Algorithm SHA256).Hash.ToLowerInvariant() -ne '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6') {
    throw 'Checksum Composer incorrecto. No se ejecutó el PHAR.'
}
. (Join-Path $PSScriptRoot 'use-local-tools.ps1')
php (Join-Path $PSScriptRoot 'configure-local-ca.php')
php -v
php $composerFile --version
