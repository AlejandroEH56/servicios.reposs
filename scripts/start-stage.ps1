[CmdletBinding()]
param([ValidateSet('Start','Stop','Status','RestartWorker')][string] $Action = 'Start', [ValidateSet('staging','production')][string] $Environment = 'staging')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$stateFile = Join-Path $projectRoot ('.tools/' + $Environment + '-processes.json')
$port = if ($Environment -eq 'staging') { 8180 } else { 8280 }
$tlsPort = if ($Environment -eq 'staging') { 8443 } else { 9443 }
$php = Join-Path $projectRoot '.tools/php/php.exe'
$caddy = Join-Path $projectRoot '.tools/operations/caddy.exe'
if ($Action -eq 'Status') {
    if (Test-Path -LiteralPath $stateFile) { Get-Content -LiteralPath $stateFile } else { Write-Host 'Staging detenido.' }
    exit 0
}
if ($Action -eq 'RestartWorker') {
    $records = Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json
    foreach ($record in @($records | Where-Object { $_.service -eq 'worker' })) {
        $current = Get-Process -Id $record.pid -ErrorAction SilentlyContinue
        if ($current -and $current.StartTime.ToUniversalTime().ToString('o') -eq $record.startedAt) {
            & taskkill.exe /PID $record.pid /T /F | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'No se pudo detener el worker registrado.' }
        }
    }
    $process = Start-Process -FilePath powershell.exe -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','scripts/supervise-stage-worker.ps1','-Environment',$Environment -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $projectRoot ('.tools/' + $Environment + '-worker.log')) -RedirectStandardError (Join-Path $projectRoot ('.tools/' + $Environment + '-worker-error.log'))
    $updated = @($records | Where-Object { $_.service -ne 'worker' }) + @{service='worker';pid=$process.Id;startedAt=$process.StartTime.ToUniversalTime().ToString('o')}
    [IO.File]::WriteAllText($stateFile, ($updated | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
    Write-Host ('Worker ' + $Environment + ' recuperado; HTTP y proxy conservados.')
    exit 0
}
if ($Action -eq 'Stop') {
    if (-not (Test-Path -LiteralPath $stateFile)) { exit 0 }
    foreach ($record in (Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json)) {
        $process = Get-Process -Id $record.pid -ErrorAction SilentlyContinue
        if ($process -and $process.StartTime.ToUniversalTime().ToString('o') -eq $record.startedAt) {
            & taskkill.exe /PID $record.pid /T /F | Out-Null
            if ($LASTEXITCODE -ne 0) { throw 'No se pudo detener el proceso registrado. Conserva el registro y ejecuta Stop fuera del sandbox.' }
        }
    }
    Remove-Item -LiteralPath $stateFile
    Write-Host 'Staging detenido; datos y certificados conservados.'
    exit 0
}
if (Test-Path -LiteralPath $stateFile) { throw 'Ya existe un registro de staging. Comprueba Status/Stop antes de iniciar otra instancia.' }
if (-not (Test-Path -LiteralPath $caddy) -or -not (Test-Path -LiteralPath (Join-Path $projectRoot ('.env.' + $Environment)))) {
    throw 'Prepara los ambientes e instala Caddy con los scripts del proyecto.'
}
$env:PATH = (Split-Path $php -Parent) + ';' + $env:PATH
$env:APP_ENV = $Environment
$env:FRONTEND_ROOT = (Join-Path $projectRoot 'frontend/dist/servicios-ui/browser').Replace('\','/')
$env:XDG_DATA_HOME = Join-Path $projectRoot '.tools/caddy-data'
$env:XDG_CONFIG_HOME = Join-Path $projectRoot '.tools/caddy-config'
$env:CADDY_STORAGE_ROOT = (Join-Path $projectRoot '.tools/caddy-data').Replace('\','/')
$env:LOCAL_SITE_ADDRESS = 'https://localhost:' + $tlsPort
$env:LOCAL_BACKEND = '127.0.0.1:' + $port
if ($Environment -eq 'production') { $env:CADDY_STORAGE_ROOT = (Join-Path $projectRoot '.tools/caddy-production-data').Replace('\','/') }
$records = @()
try {
    foreach ($service in @(
        @{name='backend';file=$php;args=@('artisan','serve',('--env=' + $Environment),'--host=127.0.0.1',('--port=' + $port),'--no-interaction')},
        @{name='worker';file='powershell.exe';args=@('-NoProfile','-ExecutionPolicy','Bypass','-File','scripts/supervise-stage-worker.ps1','-Environment',$Environment)},
        @{name='proxy';file=$caddy;args=@('run','--config','docker/Caddyfile.local','--adapter','caddyfile')}
    )) {
        $process = Start-Process -FilePath $service.file -ArgumentList $service.args -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $projectRoot ('.tools/' + $Environment + '-' + $service.name + '.log')) -RedirectStandardError (Join-Path $projectRoot ('.tools/' + $Environment + '-' + $service.name + '-error.log'))
        $records += @{service=$service.name;pid=$process.Id;startedAt=$process.StartTime.ToUniversalTime().ToString('o')}
    }
} finally {
    [IO.File]::WriteAllText($stateFile, ($records | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
}
Write-Host ($Environment + ' iniciado: https://localhost:' + $tlsPort + '/portal/; Status/Stop usan sólo los procesos registrados.')
