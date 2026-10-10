[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$privateRoot = Join-Path $projectRoot '.tools/environments'
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot '.env.staging'))) { throw 'Ejecuta modernization:prepare-environments primero.' }
[void][IO.Directory]::CreateDirectory($privateRoot)
$credentialsFile = Join-Path $privateRoot 'compose-credentials.json'
if (Test-Path -LiteralPath $credentialsFile) {
    $credentials = Get-Content -LiteralPath $credentialsFile -Raw | ConvertFrom-Json
} else {
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $values = @()
        foreach ($index in 1..3) {
            $bytes = New-Object byte[] 32
            $generator.GetBytes($bytes)
            $values += [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
        }
        $credentials = [pscustomobject]@{root=$values[0];runtime=$values[1];migrator=$values[2]}
    } finally { $generator.Dispose() }
    [IO.File]::WriteAllText($credentialsFile, ($credentials | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
}
if (-not ($credentials.PSObject.Properties.Name -contains 'operator')) {
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $bytes = New-Object byte[] 32
        $generator.GetBytes($bytes)
        $credentials | Add-Member -NotePropertyName operator -NotePropertyValue ([BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant())
    } finally { $generator.Dispose() }
    [IO.File]::WriteAllText($credentialsFile, ($credentials | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
}
foreach ($value in @($credentials.root, $credentials.runtime, $credentials.migrator, $credentials.operator)) {
    if ($value -notmatch '^[a-f0-9]{64}$') { throw 'Credencial de Compose inválida.' }
}
function Set-EnvironmentValue([string] $content, [string] $key, [string] $value) {
    $line = $key + '="' + $value + '"'
    $pattern = '(?m)^' + [regex]::Escape($key) + '=.*$'
    if ([regex]::IsMatch($content, $pattern)) { return [regex]::Replace($content, $pattern, [Text.RegularExpressions.MatchEvaluator]{ param($match) $line }) }
    return $content + "`n" + $line + "`n"
}
$base = [IO.File]::ReadAllText((Join-Path $projectRoot '.env.staging'))
foreach ($role in @('runtime', 'migrator', 'operator')) {
    $content = Set-EnvironmentValue $base 'DB_HOST' 'mysql'
    $content = Set-EnvironmentValue $content 'DB_USERNAME' ('sr_container_' + $role)
    $content = Set-EnvironmentValue $content 'DB_PASSWORD' $credentials.$role
    $content = Set-EnvironmentValue $content 'PRIVATE_STORAGE_ROOT' '/srv/app/storage/app/private'
    $content = Set-EnvironmentValue $content 'MODERNIZATION_OPERATIONS_ENABLED' ([string]($role -eq 'operator')).ToLowerInvariant()
    $existingFile = Join-Path $privateRoot ('container-' + $role + '.env')
    if (Test-Path -LiteralPath $existingFile) {
        $existing = [IO.File]::ReadAllText($existingFile)
        foreach ($key in @('APP_KEY','APP_PREVIOUS_KEYS')) {
            $match = [regex]::Match($existing, '(?m)^' + $key + '="?([^"\r\n]+)"?\r?$')
            if ($match.Success) { $content = Set-EnvironmentValue $content $key $match.Groups[1].Value }
        }
    }
    [IO.File]::WriteAllText($existingFile, $content, (New-Object Text.UTF8Encoding($false)))
}
[IO.File]::WriteAllText((Join-Path $privateRoot 'mysql-root-password.txt'), $credentials.root, (New-Object Text.UTF8Encoding($false)))
$sql = "CREATE USER IF NOT EXISTS 'sr_container_runtime'@'%' IDENTIFIED BY '" + $credentials.runtime + "';`n"
$sql += "CREATE USER IF NOT EXISTS 'sr_container_migrator'@'%' IDENTIFIED BY '" + $credentials.migrator + "';`n"
$sql += "GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON servicios_moderno_stage.* TO 'sr_container_migrator'@'%';`n"
$sql += "CREATE USER IF NOT EXISTS 'sr_container_operator'@'%' IDENTIFIED BY '" + $credentials.operator + "';`n"
[IO.File]::WriteAllText((Join-Path $privateRoot 'initialize-users.sql'), $sql, (New-Object Text.UTF8Encoding($false)))
$sql = ''
foreach ($table in @('iam_identidades','iam_cuentas_externas','compartido_mensajes_salida','compartido_bandeja_entrada','compartido_archivos_almacenados','sessions','cache','cache_locks','jobs','job_batches','failed_jobs')) {
    $sql += 'GRANT SELECT, INSERT, UPDATE, DELETE ON servicios_moderno_stage.' + $table + " TO 'sr_container_runtime'@'%';`n"
}
$sql += "GRANT SELECT, INSERT ON servicios_moderno_stage.compartido_registros_auditoria TO 'sr_container_runtime'@'%';`n"
$sql += "GRANT SELECT, UPDATE ON servicios_moderno_stage.iam_identidades TO 'sr_container_operator'@'%';`n"
$sql += "GRANT SELECT ON servicios_moderno_stage.iam_cuentas_externas TO 'sr_container_operator'@'%';`n"
$sql += "GRANT SELECT, INSERT ON servicios_moderno_stage.compartido_registros_auditoria TO 'sr_container_operator'@'%';`n"
[IO.File]::WriteAllText((Join-Path $privateRoot 'grant-runtime.sql'), $sql, (New-Object Text.UTF8Encoding($false)))
Write-Host 'PASS: archivos privados de Compose preparados; no se modificaron las bases existentes.'
