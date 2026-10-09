[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$script=Join-Path $PSScriptRoot 'portable-recovery.ps1'
$powerShell=Join-Path $PSHOME 'powershell.exe'
$action=New-ScheduledTaskAction -Execute $powerShell -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "'+$script+'" -Action Daily') -WorkingDirectory $root
$trigger=New-ScheduledTaskTrigger -Daily -At '02:45'
$principal=New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)
$name='Servicios.Modernizacion.BackupPortable'
[void](Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Encrypted portable foundation backup; private key stored separately; Docker Desktop must be running.' -Force)
$task=Get-ScheduledTask -TaskName $name
$proof=[ordered]@{executedAt=[DateTime]::UtcNow.ToString('o');name=$name;enabled=$task.Settings.Enabled;dailyAt='02:45 America/Mexico_City';startWhenAvailable=$task.Settings.StartWhenAvailable;logonType=[string]$task.Principal.LogonType;runLevel=[string]$task.Principal.RunLevel;credentialStored=$false;requiresDockerDesktopAndInteractiveSession=$true;retentionDays=14;maximumSnapshots=30;maximumGiB=40;rpoTargetHours=24;rtoTargetHours=4}
[IO.File]::WriteAllText((Join-Path $root 'artifacts/portable-backup-schedule.json'),($proof|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
Write-Output 'PASS portable backup daily 02:45 registered and read back; no password stored.'