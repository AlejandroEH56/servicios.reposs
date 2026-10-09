[CmdletBinding()]
param([ValidateSet('InitKey','Daily','Backup','Restore','Prune')][string]$Action='Backup',[string]$Snapshot,[int]$RetentionDays=14,[int]$MaximumSnapshots=30,[int]$MaximumGiB=40)
$ErrorActionPreference='Stop'
$projectRoot=Split-Path $PSScriptRoot -Parent
$dockerPath=Join-Path $env:LOCALAPPDATA 'Programs/DockerDesktop/resources/bin/docker.exe'
$maximumBytes=16MB
. (Join-Path $PSScriptRoot 'docker-private-io.ps1')
. (Join-Path $PSScriptRoot 'portable-private-io.ps1')
Add-Type -AssemblyName System.IO.Compression
$backupRoot=Join-Path $projectRoot '.tools/portable-backups'
$recoveryRoot=Join-Path $projectRoot '.tools/portable-recovery'
$keyFile=Join-Path $projectRoot '.tools/recovery-keys/age-identity.txt'
$recipientFile=Join-Path $projectRoot '.tools/recovery-keys/recipient.txt'
$age=Join-Path $projectRoot '.tools/quality/age/windows/age/age.exe'
$linuxTools=Join-Path $projectRoot '.tools/quality/age/linux/age'
if($Action -eq 'InitKey') {
 Set-PrivateDirectory (Split-Path $keyFile -Parent)
 $keygen=Join-Path $projectRoot '.tools/quality/age/windows/age/age-keygen.exe'
 if(-not(Test-Path -LiteralPath $keyFile)){[void](Invoke-PrivateBytes $keygen @('-o',$keyFile) $null)}
 [byte[]]$public=Invoke-PrivateBytes $keygen @('-y',$keyFile) $null
 $recipient=[Text.Encoding]::UTF8.GetString($public).Trim()
 if(Test-Path -LiteralPath $recipientFile){if([IO.File]::ReadAllText($recipientFile).Trim() -ne $recipient){throw 'Existing public recipient does not match recovery key.'}}
 [IO.File]::WriteAllText($recipientFile,$recipient,(New-Object Text.UTF8Encoding($false)))
 Write-Output 'PASS private recovery key initialized; existing key preserved; public recipient verified.'
 exit 0
}
$recipient=([IO.File]::ReadAllText($recipientFile)).Trim()
Set-PrivateDirectory $backupRoot
$compose=@('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'),'-p','servicios_stage')
$dump='export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; exec mysqldump --user=root --single-transaction --hex-blob --skip-comments --skip-dump-date --order-by-primary --set-gtid-purged=OFF --databases servicios_moderno_stage'
$mysql='export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; exec mysql --user=root'
$inventory='$root=getcwd();$files=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){$relative=substr($file->getPathname(),strlen($root)+1);if(str_starts_with($relative,".health/")){continue;}if($file->isFile()){$files[$relative]=["sha256"=>hash_file("sha256",$file->getPathname()),"bytes"=>$file->getSize()];}}ksort($files);echo json_encode($files,JSON_THROW_ON_ERROR);'
function Assert-SnapshotPath([string]$Id) {
 if($Id -notmatch '^\d{8}T\d{6}Z$'){throw 'Invalid snapshot ID.'}
 $path=[IO.Path]::GetFullPath((Join-Path $backupRoot $Id))
 if(-not $path.StartsWith([IO.Path]::GetFullPath($backupRoot)+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){throw 'Snapshot path outside backup root.'}
 return $path
}
function Get-LinuxDecryptArguments([string]$File) {
 return @('run','--rm','--network','none','--user','0:0','--mount',('type=bind,source='+$linuxTools+',target=/tools,readonly'),'--mount',('type=bind,source='+$keyFile+',target=/key.txt,readonly'),'--mount',('type=bind,source='+$directory+',target=/snapshot,readonly'),'--entrypoint','/tools/age','servicios-backend:local','-d','-i','/key.txt',('/snapshot/'+$File))
}
if($RetentionDays -lt 1 -or $RetentionDays -gt 90 -or $MaximumSnapshots -lt 2 -or $MaximumSnapshots -gt 90 -or $MaximumGiB -lt 1 -or $MaximumGiB -gt 200){throw 'Invalid retention/capacity policy.'}
if($Action -eq 'Prune') {
 $completed=@(Get-ChildItem -LiteralPath $backupRoot -Directory|Where-Object {$_.Name -match '^\d{8}T\d{6}Z$' -and (Test-Path -LiteralPath (Join-Path $_.FullName 'manifest.json')) -and (Get-Content -LiteralPath (Join-Path $_.FullName 'manifest.json') -Raw|ConvertFrom-Json).completed}|Sort-Object Name -Descending)
 $deleted=0;$index=0
 foreach($item in $completed){$index++;$created=[DateTime]::ParseExact($item.Name,'yyyyMMddTHHmmssZ',[Globalization.CultureInfo]::InvariantCulture,[Globalization.DateTimeStyles]::AssumeUniversal).ToUniversalTime();if($index -le 2){continue};if($index -gt $MaximumSnapshots -or $created -lt [DateTime]::UtcNow.AddDays(-$RetentionDays)){$safe=Assert-SnapshotPath $item.Name;Remove-Item -LiteralPath $safe -Recurse -Force;$deleted++}}
 Write-Output ('PASS retention; removed completed snapshots='+$deleted+'; at least two retained; key directory excluded.')
 exit 0
}
if($Action -eq 'Daily'){& $PSCommandPath -Action Prune -RetentionDays $RetentionDays -MaximumSnapshots $MaximumSnapshots -MaximumGiB $MaximumGiB;& $PSCommandPath -Action Backup -RetentionDays $RetentionDays -MaximumSnapshots $MaximumSnapshots -MaximumGiB $MaximumGiB;exit 0}
$started=[DateTime]::UtcNow
if($Action -eq 'Backup') {
 $Snapshot=$started.ToString('yyyyMMddTHHmmssZ');$directory=Assert-SnapshotPath $Snapshot
 if(Test-Path -LiteralPath $directory){throw 'Snapshot already exists.'}
 $usage=(Get-ChildItem -LiteralPath $backupRoot -Recurse -File|Measure-Object Length -Sum).Sum
 if($usage -gt $MaximumGiB*1GB){throw 'Backup capacity reached; review retention before creating another snapshot.'}
 Set-PrivateDirectory $directory
 $streams=[ordered]@{}
 $streams['images.age']=Invoke-PrivatePipeline $dockerPath @('image','save','servicios-backend:local','servicios-mysql:local','servicios-proxy:local','servicios-antivirus:local') $age @('-r',$recipient) (Join-Path $directory 'images.age')
 $streams['database.age']=Invoke-PrivatePipeline $dockerPath ($compose+@('exec','-T','mysql','bash','-c',$dump)) $age @('-r',$recipient) (Join-Path $directory 'database.age') -CanonicalSql
 foreach($entry in @(@('storage.age','backend','/srv/app/storage/app/private'),@('antivirus.age','antivirus','/var/lib/clamav'),@('tls.age','proxy','/data'))){$streams[$entry[0]]=Invoke-PrivatePipeline $dockerPath ($compose+@('exec','-T',$entry[1],'tar','-cf','-','-C',$entry[2],'.')) $age @('-r',$recipient) (Join-Path $directory $entry[0])}
 [byte[]]$files=Invoke-DockerBytes ($compose+@('exec','-T','--workdir','/srv/app/storage/app/private','backend','php','-r',$inventory))
 [void](Invoke-PrivateBytes $age @('-r',$recipient,'-o',(Join-Path $directory 'inventory.age')) $files)
 $secrets=New-Object IO.MemoryStream
 $zip=New-Object IO.Compression.ZipArchive($secrets,[IO.Compression.ZipArchiveMode]::Create,$true)
 try {
  foreach($relative in @('container-runtime.env','container-migrator.env','container-operator.env','compose-credentials.json','mysql-root-password.txt')){$path=Join-Path $projectRoot ('.tools/environments/'+$relative);if(-not(Test-Path -LiteralPath $path)){throw 'Required recovery profile missing.'};$entry=$zip.CreateEntry($relative);$stream=$entry.Open();try{$data=[IO.File]::ReadAllBytes($path);$stream.Write($data,0,$data.Length)}finally{$stream.Dispose()}}
 } finally {$zip.Dispose()}
 [byte[]]$secretBytes=$secrets.ToArray();$secrets.Dispose()
 [void](Invoke-PrivateBytes $age @('-r',$recipient,'-o',(Join-Path $directory 'secrets.age')) $secretBytes)
 $images=[ordered]@{}
 foreach($image in @('servicios-backend:local','servicios-mysql:local','servicios-proxy:local','servicios-antivirus:local')){$id=Invoke-DockerBytes @('image','inspect','--format','{{.Id}}',$image);$images[$image]=[Text.Encoding]::UTF8.GetString($id).Trim()}
 $encrypted=[ordered]@{}
 foreach($file in Get-ChildItem -LiteralPath $directory -File){$encrypted[$file.Name]=[ordered]@{sha256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant();bytes=$file.Length}}
 $manifest=[ordered]@{snapshot=$Snapshot;createdAt=$started.ToString('o');commit=(& git -C $projectRoot rev-parse HEAD).Trim();workingTreeDirty=[bool](& git -C $projectRoot status --porcelain);format='age X25519 v1; streaming';scope='Portable recovery of current foundation; no DPAPI dependency';streams=$streams;storageInventorySha256=(Get-Digest $files);secretsSha256=(Get-Digest $secretBytes);encryptedFiles=$encrypted;images=$images;retentionDays=$RetentionDays;maximumSnapshots=$MaximumSnapshots;maximumGiB=$MaximumGiB;rpoHours=24;rtoHours=4;completed=$true}
 if(((Get-ChildItem -LiteralPath $backupRoot -Recurse -File|Measure-Object Length -Sum).Sum) -gt $MaximumGiB*1GB){throw 'Capacity exceeded; snapshot not committed.'}
 [IO.File]::WriteAllText((Join-Path $directory 'manifest.json'),($manifest|ConvertTo-Json -Depth 16),(New-Object Text.UTF8Encoding($false)))
 [IO.File]::WriteAllText((Join-Path $projectRoot 'artifacts/portable-backup-latest.json'),($manifest|ConvertTo-Json -Depth 16),(New-Object Text.UTF8Encoding($false)))
 & $PSCommandPath -Action Prune -RetentionDays $RetentionDays -MaximumSnapshots $MaximumSnapshots -MaximumGiB $MaximumGiB | Out-Null
 Write-Output ('PASS portable backup '+$Snapshot+'; SQL/storage/AV/TLS/secrets encrypted; no private recovery key in archive.')
 exit 0
}
$directory=Assert-SnapshotPath $Snapshot
$manifest=Get-Content -LiteralPath (Join-Path $directory 'manifest.json') -Raw|ConvertFrom-Json
if(-not $manifest.completed){throw 'Snapshot incomplete.'}
foreach($file in $manifest.encryptedFiles.PSObject.Properties){if($file.Name -notmatch '^[a-z]+\.age$'){throw 'Invalid encrypted file name.'};$hash=(Get-FileHash -LiteralPath (Join-Path $directory $file.Name) -Algorithm SHA256).Hash.ToLowerInvariant();if($hash -ne $file.Value.sha256){throw 'Encrypted snapshot integrity check failed.'}}
if($manifest.encryptedFiles.PSObject.Properties.Name -contains 'images.age') {
 [void](Invoke-PrivatePipeline $age @('-d','-i',$keyFile,(Join-Path $directory 'images.age')) $dockerPath @('image','load'))
}
foreach($image in $manifest.images.PSObject.Properties){$id=Invoke-DockerBytes @('image','inspect','--format','{{.Id}}',$image.Name);if([Text.Encoding]::UTF8.GetString($id).Trim() -ne $image.Value){throw 'Recovery image differs from snapshot; restore the pinned release images first.'}}
$root=Join-Path $recoveryRoot $Snapshot
if(Test-Path -LiteralPath $root){throw 'Recovery already exists; use a fresh snapshot.'}
Set-PrivateDirectory $root
[byte[]]$secretBytes=Invoke-DockerBytes (Get-LinuxDecryptArguments 'secrets.age')
if((Get-Digest $secretBytes) -ne $manifest.secretsSha256){throw 'Recovered secrets differ.'}
$memory=New-Object IO.MemoryStream(,$secretBytes);$zip=New-Object IO.Compression.ZipArchive($memory,[IO.Compression.ZipArchiveMode]::Read)
try {foreach($name in @('container-runtime.env','container-migrator.env','container-operator.env','compose-credentials.json','mysql-root-password.txt')){$entry=$zip.GetEntry($name);if(-not $entry){throw 'Recovery profile missing from archive.'};$stream=$entry.Open();$out=[IO.File]::Create((Join-Path $root $name));try{$stream.CopyTo($out)}finally{$stream.Dispose();$out.Dispose()}}}finally{$zip.Dispose();$memory.Dispose()}
$credentials=Get-Content -LiteralPath (Join-Path $root 'compose-credentials.json') -Raw|ConvertFrom-Json
foreach($role in @('root','runtime','migrator','operator')){if($credentials.$role -notmatch '^[a-f0-9]{64}$'){throw 'Invalid recovered DB credential format.'}}
$prefix='servicios-cold-'+$Snapshot.ToLowerInvariant();$network=$prefix+'-network';$db=$prefix+'-mysql';$backend=$prefix+'-backend';$worker=$prefix+'-worker';$av=$prefix+'-antivirus';$proxy=$prefix+'-proxy';$containers=@();$volumes=@{mysql=$prefix+'-mysql-data';storage=$prefix+'-private';antivirus=$prefix+'-av';tls=$prefix+'-tls'}
$result=[ordered]@{snapshot=$Snapshot;startedAt=$started.ToString('o');sourceCommit=$manifest.commit;workingTreeDirtyAtBackup=$manifest.workingTreeDirty;portableDecryptPlatform='Linux amd64 / age 1.3.2, network none';dpapiUsed=$false;newVolumes=$true;isolatedInternalNetwork=$true;publishedPorts=0;physicalHostChanged=$false;releaseImagesIncluded=($manifest.encryptedFiles.PSObject.Properties.Name -contains 'images.age');releaseImagesLoaded=($manifest.encryptedFiles.PSObject.Properties.Name -contains 'images.age');secretsIntegrity=$true;databaseMatches=$false;storageMatches=$false;runtimeDdlDenied=$false;auditMutationDenied=$false;operatorDdlDenied=$false;applicationRecovered=$false;workerRecovered=$false;tlsVerified=$false;antivirusRecovered=$false;stopped=$false}
try {
 [void](Invoke-DockerBytes @('network','create','--internal','--label',('servicios.recovery='+$Snapshot),$network))
 foreach($volume in $volumes.Values){[void](Invoke-DockerBytes @('volume','create','--label',('servicios.recovery='+$Snapshot),$volume))}
 [void](Invoke-DockerBytes @('run','-d','--name',$db,'--label',('servicios.recovery='+$Snapshot),'--network',$network,'--network-alias','mysql','--mount',('type=volume,source='+$volumes.mysql+',target=/var/lib/mysql'),'--mount',('type=bind,source='+(Join-Path $root 'mysql-root-password.txt')+',target=/run/secrets/mysql_root_password,readonly'),'--env','MYSQL_ROOT_PASSWORD_FILE=/run/secrets/mysql_root_password','servicios-mysql:local'));$containers+=$db
 $ready=$false;for($i=0;$i -lt 60;$i++){try{[void](Invoke-DockerBytes @('exec',$db,'mysqladmin','ping','-h','127.0.0.1'));$ready=$true;break}catch{Start-Sleep -Milliseconds 1000}}
 if(-not $ready){throw 'Cold MySQL startup timed out.'}
 $import=Invoke-PrivatePipeline $dockerPath (Get-LinuxDecryptArguments 'database.age') $dockerPath @('exec','-i',$db,'bash','-c',$mysql)
 $verify=Invoke-PrivatePipeline $dockerPath @('exec',$db,'bash','-c',$dump) $age @('-r',$recipient) (Join-Path $root 'database-verified.age') -CanonicalSql
 $result.databaseMatches=$verify.inputSha256 -eq $manifest.streams.'database.age'.inputSha256
 if(-not $result.databaseMatches){throw 'Cold SQL equivalence failed.'}
 foreach($entry in @(@('storage.age',$volumes.storage),@('antivirus.age',$volumes.antivirus),@('tls.age',$volumes.tls))){[void](Invoke-PrivatePipeline $dockerPath (Get-LinuxDecryptArguments $entry[0]) $dockerPath @('run','--rm','-i','--network','none','--user','0:0','--mount',('type=volume,source='+$entry[1]+',target=/restore'),'--entrypoint','tar','servicios-backend:local','-xf','-','-C','/restore'))}
 [byte[]]$restoredFiles=Invoke-DockerBytes @('run','--rm','--network','none','--user','0:0','--mount',('type=volume,source='+$volumes.storage+',target=/restore'),'--workdir','/restore','--entrypoint','php','servicios-backend:local','-r',$inventory)
 $result.storageMatches=(Get-Digest $restoredFiles) -eq $manifest.storageInventorySha256
 if(-not $result.storageMatches){throw 'Cold private-file inventory differs.'}
 $sql=''
 foreach($role in @('runtime','migrator','operator')){$sql+="CREATE USER 'sr_container_"+$role+"'@'%' IDENTIFIED BY '"+$credentials.$role+"';"}
 $sql+="GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON servicios_moderno_stage.* TO 'sr_container_migrator'@'%';"
 foreach($table in @('iam_identidades','iam_cuentas_externas','compartido_mensajes_salida','compartido_bandeja_entrada','compartido_archivos_almacenados','sessions','cache','cache_locks','jobs','job_batches','failed_jobs')){$sql+='GRANT SELECT, INSERT, UPDATE, DELETE ON servicios_moderno_stage.'+$table+" TO 'sr_container_runtime'@'%';"}
 $sql+="GRANT SELECT, INSERT ON servicios_moderno_stage.compartido_registros_auditoria TO 'sr_container_runtime'@'%';GRANT SELECT, UPDATE ON servicios_moderno_stage.iam_identidades TO 'sr_container_operator'@'%';GRANT SELECT ON servicios_moderno_stage.iam_cuentas_externas TO 'sr_container_operator'@'%';GRANT SELECT, INSERT ON servicios_moderno_stage.compartido_registros_auditoria TO 'sr_container_operator'@'%';"
 [void](Invoke-DockerBytes @('exec','-i',$db,'bash','-c',$mysql) ([Text.Encoding]::UTF8.GetBytes($sql)))
 $runtime=[IO.File]::ReadAllText((Join-Path $root 'container-runtime.env'))
 $runtime=Set-PrivateEnvironmentValue $runtime 'CLAMAV_HOST' 'antivirus';$runtime=Set-PrivateEnvironmentValue $runtime 'OTEL_ENABLED' 'false'
 [IO.File]::WriteAllText((Join-Path $root 'container-runtime.env'),$runtime,(New-Object Text.UTF8Encoding($false)))
 [void](Invoke-DockerBytes @('run','-d','--name',$backend,'--label',('servicios.recovery='+$Snapshot),'--network',$network,'--network-alias','backend','--env-file',(Join-Path $root 'container-runtime.env'),'--env','SESSION_SECURE_COOKIE=true','--mount',('type=volume,source='+$volumes.storage+',target=/srv/app/storage/app/private'),'servicios-backend:local'));$containers+=$backend
 [void](Invoke-DockerBytes @('run','-d','--name',$worker,'--label',('servicios.recovery='+$Snapshot),'--network',$network,'--env-file',(Join-Path $root 'container-runtime.env'),'--mount',('type=volume,source='+$volumes.storage+',target=/srv/app/storage/app/private'),'servicios-backend:local','php','artisan','outbox:work'));$containers+=$worker
 [void](Invoke-DockerBytes @('run','-d','--name',$av,'--label',('servicios.recovery='+$Snapshot),'--network',$network,'--network-alias','antivirus','--mount',('type=volume,source='+$volumes.antivirus+',target=/var/lib/clamav'),'servicios-antivirus:local'));$containers+=$av
 [void](Invoke-DockerBytes @('run','-d','--name',$proxy,'--label',('servicios.recovery='+$Snapshot),'--network',$network,'--env','SITE_ADDRESS=https://localhost:8443','--env','TLS_DIRECTIVE=tls internal','--env','CANONICAL_ORIGIN=https://localhost:8443','--mount',('type=volume,source='+$volumes.tls+',target=/data'),'servicios-proxy:local'));$containers+=$proxy
 $bootstrap='require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
 $permissionProbe=$bootstrap+'$pdo=Illuminate\Support\Facades\DB::connection()->getPdo();$pdo->query("SELECT COUNT(*) FROM iam_identidades")->fetchColumn();$denied=0;foreach(["CREATE TABLE runtime_forbidden_probe(id INT)","UPDATE compartido_registros_auditoria SET accion=accion WHERE 1=0","DELETE FROM compartido_registros_auditoria WHERE 1=0"] as $sql){try{Illuminate\Support\Facades\DB::statement($sql);}catch(PDOException $e){if(in_array($e->errorInfo[1],[1142,1044],true)){$denied++;}else{throw $e;}}}echo json_encode(["denied"=>$denied]);'
 $permissions=Invoke-DockerBytes @('exec',$backend,'php','-r',$permissionProbe);$result.runtimeDdlDenied=([Text.Encoding]::UTF8.GetString($permissions)|ConvertFrom-Json).denied -eq 3;$result.auditMutationDenied=$result.runtimeDdlDenied
 $operatorProbe=Invoke-DockerBytes @('run','--rm','--network',$network,'--env-file',(Join-Path $root 'container-operator.env'),'--entrypoint','php','servicios-backend:local','-r',$permissionProbe)
 $result.operatorDdlDenied=([Text.Encoding]::UTF8.GetString($operatorProbe)|ConvertFrom-Json).denied -eq 3
 $address=Invoke-DockerBytes @('inspect','--format','{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}',$proxy);$ip=[Text.Encoding]::UTF8.GetString($address).Trim()
 if($ip -notmatch '^\d{1,3}(\.\d{1,3}){3}$'){throw 'Recovery proxy address unavailable.'}
 $http='<?php $curl=curl_init("https://localhost:8443/health/ready");curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CAINFO=>"/tmp/recovery-ca.crt",CURLOPT_RESOLVE=>["localhost:8443:@@IP@@"],CURLOPT_TIMEOUT=>10]);$body=curl_exec($curl);echo json_encode(["status"=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),"tls"=>curl_errno($curl)===0,"curlErrno"=>curl_errno($curl)]);'
 $http=$http.Replace('@@IP@@',$ip)
 [void](Invoke-DockerBytes @('cp',($proxy+':/data/caddy/pki/authorities/local/root.crt'),(Join-Path $root 'recovery-ca.crt')))
 [void](Invoke-DockerBytes @('cp',(Join-Path $root 'recovery-ca.crt'),($backend+':/tmp/recovery-ca.crt')))
 $httpResult=$null
 for($i=0;$i -lt 30;$i++){try{$data=Invoke-DockerBytes @('exec','-i',$backend,'php') ([Text.Encoding]::UTF8.GetBytes($http));$httpResult=[Text.Encoding]::UTF8.GetString($data)|ConvertFrom-Json;if($httpResult.status -eq 200){break}}catch{};Start-Sleep -Milliseconds 1000}
 $result.applicationRecovered=$httpResult.status -eq 200;$result.tlsVerified=$httpResult.tls -eq $true
 $heartbeatProbe=$bootstrap+'$value=Illuminate\Support\Facades\Cache::get(config("modernization.health.outbox_heartbeat_key"));echo json_encode(["heartbeat"=>$value]);'
 $freshHeartbeat=$false
 for($i=0;$i -lt 30;$i++){
  $state=Invoke-DockerBytes @('inspect','--format','{{.State.Running}}',$worker)
  $data=Invoke-DockerBytes @('exec',$backend,'php','-r',$heartbeatProbe)
  $heartbeat=([Text.Encoding]::UTF8.GetString($data)|ConvertFrom-Json).heartbeat
  if([Text.Encoding]::UTF8.GetString($state).Trim() -eq 'true' -and $heartbeat -ge ([DateTimeOffset]$started).ToUnixTimeSeconds()){$freshHeartbeat=$true;break}
  Start-Sleep -Milliseconds 1000
 }
 [void](Invoke-DockerBytes @('exec',$backend,'php','artisan','outbox:metrics','--check'))
 $result.workerRecovered=$freshHeartbeat
 $avReady=$false;for($i=0;$i -lt 60;$i++){try{[void](Invoke-DockerBytes @('exec',$av,'clamdscan','--ping','1'));$avReady=$true;break}catch{Start-Sleep -Milliseconds 1000}}
 $result.antivirusRecovered=$avReady
 if(-not($result.workerRecovered -and $result.applicationRecovered -and $result.tlsVerified -and $result.runtimeDdlDenied -and $result.operatorDdlDenied -and $result.antivirusRecovered)){throw 'Cold application/principals/scanner check failed.'}
} finally {
 $allStopped=$true;foreach($container in $containers){try{[void](Invoke-DockerBytes @('stop','--time','30',$container))}catch{$allStopped=$false}}
 $result.stopped=$allStopped;$result.seconds=([DateTime]::UtcNow-$started).TotalSeconds;$result.rpoAgeHours=($started-[DateTime]::Parse($manifest.createdAt).ToUniversalTime()).TotalHours;$result.rtoTargetMet=$result.seconds -le 4*3600;$result.rpoTargetMet=$result.rpoAgeHours -le 24
 [IO.File]::WriteAllText((Join-Path $projectRoot ('artifacts/portable-cold-restore-'+$Snapshot+'.json')),($result|ConvertTo-Json -Depth 12),(New-Object Text.UTF8Encoding($false)))
}
if(-not ($result.stopped -and $result.rtoTargetMet -and $result.rpoTargetMet)){throw 'Cold recovery did not satisfy cleanup/RPO/RTO policy.'}
Write-Output ('PASS cold recovery '+$Snapshot+'; Linux decrypt; isolated DB/principals/files/AV/TLS/app/worker; source unchanged; containers stopped.')
