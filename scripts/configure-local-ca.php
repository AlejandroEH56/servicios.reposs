<?php

$root = dirname(__DIR__);
$composer = $root.'/.tools/composer.phar';
if (hash_file('sha256', $composer) !== '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6') {
    throw new RuntimeException('Composer checksum mismatch: CA bundle not installed.');
}
$archive = new Phar($composer);
$bundle = $archive['vendor/composer/ca-bundle/res/cacert.pem']->getContent();
if (! str_contains($bundle, '-----BEGIN CERTIFICATE-----')) {
    throw new RuntimeException('CA bundle missing.');
}
$path = str_replace('\\', '/', $root.'/.tools/php/cacert.pem');
file_put_contents($path, $bundle);
$iniPath = $root.'/.tools/php/php.ini';
$ini = preg_replace('/^(curl\.cainfo|openssl\.cafile)\s*=.*$/m', '', file_get_contents($iniPath));
file_put_contents($iniPath, $ini.PHP_EOL.'curl.cainfo = "'.$path.'"'.PHP_EOL.'openssl.cafile = "'.$path.'"'.PHP_EOL);
echo 'Local PHP CA bundle configured: '.hash('sha256', $bundle).PHP_EOL;
