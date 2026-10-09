[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $arguments = '-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '"'
    $process = Start-Process -FilePath powershell.exe -Verb RunAs -ArgumentList $arguments -WindowStyle Hidden -PassThru -Wait
    exit $process.ExitCode
}
$projectRoot = Split-Path $PSScriptRoot -Parent
$results = @()
foreach ($feature in @('Microsoft-Windows-Subsystem-Linux','VirtualMachinePlatform')) {
    $current = Get-WindowsOptionalFeature -Online -FeatureName $feature
    if ($current.State -eq 'Enabled') {
        $results += @{feature=$feature;state='Enabled';restartNeeded=$false}
    } else {
        $enabled = Enable-WindowsOptionalFeature -Online -FeatureName $feature -All -NoRestart
        $state = (Get-WindowsOptionalFeature -Online -FeatureName $feature).State.ToString()
        $results += @{feature=$feature;state=$state;restartNeeded=$enabled.RestartNeeded}
    }
}
$directory = Join-Path $projectRoot 'artifacts/environment'
[void][IO.Directory]::CreateDirectory($directory)
[IO.File]::WriteAllText((Join-Path $directory 'windows-container-features.json'), ($results | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
if ($results.restartNeeded -contains $true) { Write-Host 'RESTART_REQUIRED: programa el reinicio del equipo; este script no lo ejecuta.' }
Write-Host 'Resultados de componentes Windows: artifacts/environment/windows-container-features.json'
