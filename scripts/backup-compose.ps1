[CmdletBinding()]
param([ValidateSet('Backup','Restore')][string]$Action='Backup',[string]$Snapshot)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Security
Add-Type -AssemblyName System.IO.Compression
$projectRoot=Split-Path $PSScriptRoot -Parent
$backupRoot=Join-Path $projectRoot '.tools/backups'
[void][IO.Directory]::CreateDirectory($backupRoot)
$dockerPath=Join-Path $env:LOCALAPPDATA 'Programs/DockerDesktop/resources/bin/docker.exe'
if(-not(Test-Path -LiteralPath $dockerPath)){throw 'Docker Desktop requerido.'}
$maximumBytes=256MB
. (Join-Path $PSScriptRoot 'docker-private-io.ps1')
$sourceSecret=Join-Path $projectRoot '.tools/environments/mysql-root-password.txt'
$inventoryCommand='$root=getcwd();$files=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){$relative=substr($file->getPathname(),strlen($root)+1);if(str_starts_with($relative,".health/")){continue;}if($file->isFile()){$files[$relative]=["sha256"=>hash_file("sha256",$file->getPathname()),"bytes"=>$file->getSize()];}}ksort($files);echo json_encode($files,JSON_THROW_ON_ERROR);'
$dumpCommand='export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; exec mysqldump --user=root --single-transaction --hex-blob --skip-comments --skip-dump-date --order-by-primary --set-gtid-purged=OFF --databases servicios_moderno_stage'
$started=[DateTime]::UtcNow
if($Action -eq 'Backup'){
 $Snapshot=$started.ToString('yyyyMMddTHHmmssZ')
 $directory=Join-Path $backupRoot $Snapshot;if(Test-Path -LiteralPath $directory){throw 'Snapshot ID already exists.'};[void][IO.Directory]::CreateDirectory($directory)
 $owner='*'+[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
 & icacls.exe $directory /inheritance:r /grant:r ($owner+':(OI)(CI)F') '*S-1-5-18:(OI)(CI)F' | Out-Null
 if($LASTEXITCODE -ne 0){throw 'Backup ACL failed.'}
 [byte[]]$sql=Invoke-DockerBytes @('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'),'exec','-T','mysql','bash','-c',$dumpCommand)
 [byte[]]$storage=Invoke-DockerBytes @('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'),'exec','-T','backend','tar','-cf','-','-C','storage/app/private','.')
 [byte[]]$inventory=Invoke-DockerBytes @('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'),'exec','-T','--workdir','/srv/app/storage/app/private','backend','php','-r',$inventoryCommand)
 $secretStream=New-Object IO.MemoryStream
 $archive=New-Object IO.Compression.ZipArchive($secretStream,[IO.Compression.ZipArchiveMode]::Create,$true)
 try{
  foreach($relative in @('.env','.env.staging','.env.production','.env.operator','.env.migrator','.env.staging-migrator','.env.production-migrator','.tools/environments/compose-credentials.json','.tools/environments/container-runtime.env','.tools/environments/container-migrator.env','.tools/environments/mysql-root-password.txt')){
   $path=Join-Path $projectRoot $relative
   if(Test-Path -LiteralPath $path){$entry=$archive.CreateEntry($relative);$stream=$entry.Open();try{$data=[IO.File]::ReadAllBytes($path);$stream.Write($data,0,$data.Length)}finally{$stream.Dispose()}}
  }
 }finally{$archive.Dispose()}
 [byte[]]$secrets=$secretStream.ToArray();$secretStream.Dispose()
 Protect-Bytes $sql (Join-Path $directory 'database.dpapi');Protect-Bytes $storage (Join-Path $directory 'storage.dpapi');Protect-Bytes $secrets (Join-Path $directory 'secrets.dpapi')
 $manifest=[ordered]@{snapshot=$Snapshot;createdAt=$started.ToString('o');scope='CurrentUser DPAPI; local development recovery';commit=(& git -C $projectRoot rev-parse HEAD).Trim();workingTreeDirty=[bool](& git -C $projectRoot status --porcelain);databaseHash=(Get-Digest $sql);storageHash=(Get-Digest $storage);storageInventoryHash=(Get-Digest $inventory);secretsHash=(Get-Digest $secrets);bytes=@{database=$sql.Length;storage=$storage.Length;secrets=$secrets.Length};rpoTargetHours=24;rtoTargetHours=4}
 [IO.File]::WriteAllText((Join-Path $directory 'manifest.json'),($manifest|ConvertTo-Json -Depth 5),(New-Object Text.UTF8Encoding($false)))
 Write-Output ('PASS backup '+$Snapshot+'; encrypted database/storage/secrets; CurrentUser recovery only.')
}else{
 if($Snapshot -notmatch '^\d{8}T\d{6}Z$'){throw 'Provide an existing snapshot ID.'}
 $directory=Join-Path $backupRoot $Snapshot;$manifest=Get-Content -LiteralPath (Join-Path $directory 'manifest.json') -Raw|ConvertFrom-Json
 [byte[]]$sql=Unprotect-Bytes (Join-Path $directory 'database.dpapi') $manifest.databaseHash
 [byte[]]$storage=Unprotect-Bytes (Join-Path $directory 'storage.dpapi') $manifest.storageHash
 [byte[]]$secrets=Unprotect-Bytes (Join-Path $directory 'secrets.dpapi') $manifest.secretsHash
 $restoreRoot=Join-Path $projectRoot ('.tools/environments/restore-'+$Snapshot);[void][IO.Directory]::CreateDirectory($restoreRoot)
 $owner='*'+[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
 & icacls.exe $restoreRoot /inheritance:r /grant:r ($owner+':(OI)(CI)F') '*S-1-5-18:(OI)(CI)F' | Out-Null
 if($LASTEXITCODE -ne 0){throw 'Restore ACL failed.'}
 $secretStream=New-Object IO.MemoryStream(,$secrets);$archive=New-Object IO.Compression.ZipArchive($secretStream,[IO.Compression.ZipArchiveMode]::Read)
 try{$entry=$archive.GetEntry('.tools/environments/mysql-root-password.txt');if(-not $entry){throw 'Missing recovery credential.'};$stream=$entry.Open();$target=[IO.File]::Create((Join-Path $restoreRoot 'mysql-root-password.txt'));try{$stream.CopyTo($target)}finally{$stream.Dispose();$target.Dispose()}}finally{$archive.Dispose();$secretStream.Dispose()}
 $container='servicios-restore-'+$Snapshot.ToLowerInvariant();$dataVolume=$container+'-mysql';$fileVolume=$container+'-private'
 $mysqlImage='servicios-mysql:local'
 [void](Invoke-DockerBytes @('run','-d','--name',$container,'--label',('servicios.restore='+$Snapshot),'--network','none','--mount',('type=volume,source='+$dataVolume+',target=/var/lib/mysql'),'--mount',('type=bind,source='+(Join-Path $restoreRoot 'mysql-root-password.txt')+',target=/run/secrets/mysql_root_password,readonly'),'--env','MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql_root_password',$mysqlImage))
 $ready=$false
 for($attempt=0;$attempt -lt 30;$attempt++){
  try{[void](Invoke-DockerBytes @('exec',$container,'mysqladmin','ping','-h','127.0.0.1'));$ready=$true;break}catch{Start-Sleep -Milliseconds 1000}
 }
 if(-not $ready){throw 'Isolated restore database not ready.'}
 [void](Invoke-DockerBytes @('exec','-i',$container,'bash','-c','export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; exec mysql --user=root') $sql)
 [byte[]]$restoredSql=Invoke-DockerBytes @('exec',$container,'bash','-c',$dumpCommand)
 [void](Invoke-DockerBytes @('run','--rm','-i','--network','none','--user','0:0','--mount',('type=volume,source='+$fileVolume+',target=/restore'),'--entrypoint','tar','servicios-backend:local','-xf','-','-C','/restore') $storage)
 [byte[]]$restoredStorage=Invoke-DockerBytes @('run','--rm','--network','none','--user','0:0','--mount',('type=volume,source='+$fileVolume+',target=/restore'),'--entrypoint','tar','servicios-backend:local','-cf','-','-C','/restore','.')
 $sqlMatches=(Get-DatabaseDigest $restoredSql) -eq (Get-DatabaseDigest $sql);$storageMatches=(Get-Digest $restoredStorage) -eq $manifest.storageHash
 if($manifest.storageInventoryHash){
  [byte[]]$restoredInventory=Invoke-DockerBytes @('run','--rm','--network','none','--user','0:0','--mount',('type=volume,source='+$fileVolume+',target=/restore'),'--workdir','/restore','--entrypoint','php','servicios-backend:local','-r',$inventoryCommand)
  $storageMatches=(Get-Digest $restoredInventory) -eq $manifest.storageInventoryHash
 }
 [void](Invoke-DockerBytes @('stop',$container))
 $result=[ordered]@{snapshot=$Snapshot;executedAt=$started.ToString('o');databaseMatches=$sqlMatches;storageMatches=$storageMatches;secretsIntegrity=$true;isolatedNetwork='none';seconds=([DateTime]::UtcNow-$started).TotalSeconds;ageSeconds=($started-[DateTime]::Parse($manifest.createdAt).ToUniversalTime()).TotalSeconds;restoreContainer=$container;dataVolume=$dataVolume;fileVolume=$fileVolume;scope='Local CurrentUser recovery rehearsal; no promotion'}
 $artifact=Join-Path $projectRoot ('artifacts/restore-'+$Snapshot+'.json');[IO.File]::WriteAllText($artifact,($result|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 if(-not $sqlMatches -or -not $storageMatches){throw 'Restore hashes differ; inspect aggregate artifact.'}
 Write-Output ('PASS isolated restore '+$Snapshot+'; DB/storage hashes identical; stopped recovery container.')
}