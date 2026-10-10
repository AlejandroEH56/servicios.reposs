[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
$projectRoot=Split-Path $PSScriptRoot -Parent
$dockerPath=Join-Path $env:LOCALAPPDATA 'Programs/DockerDesktop/resources/bin/docker.exe'
$maximumBytes=16MB
. (Join-Path $PSScriptRoot 'docker-private-io.ps1')
$privateRoot=Join-Path $projectRoot '.tools/environments'
$credentialsFile=Join-Path $privateRoot 'compose-credentials.json'
$runtimeFile=Join-Path $privateRoot 'container-runtime.env'
$credentials=Get-Content -LiteralPath $credentialsFile -Raw|ConvertFrom-Json
if($credentials.runtime -notmatch '^[a-f0-9]{64}$'){throw 'Unexpected runtime credential format.'}
$oldEnvironment=[IO.File]::ReadAllText($runtimeFile)
$oldKeyMatch=[regex]::Match($oldEnvironment,'(?m)^APP_KEY="?(base64:[A-Za-z0-9+/=]+)"?\r?$')
if(-not $oldKeyMatch.Success){throw 'Missing current APP_KEY.'}
$oldKey=$oldKeyMatch.Groups[1].Value
$previousKeys=@($oldKey)
$previousMatch=[regex]::Match($oldEnvironment,'(?m)^APP_PREVIOUS_KEYS="?([^"\r\n]+)"?\r?$')
if($previousMatch.Success){$previousKeys+=($previousMatch.Groups[1].Value.Split(',') | ForEach-Object {$_.Trim()} | Where-Object {$_})}
$previousKeys=@($previousKeys|Select-Object -Unique)
if($previousKeys.Count -gt 4){throw 'Retire previous keys only after session expiry and verified data recovery before another rotation.'}
$previousValue=$previousKeys -join ','
$compose=@('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'))
$bootstrap='require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
[byte[]]$cipher=Invoke-DockerBytes ($compose+@('exec','-T','backend','php','-r',($bootstrap+'echo app("encrypter")->encryptString("KEY_ROTATION_REHEARSAL");')))
$generator=[Security.Cryptography.RandomNumberGenerator]::Create()
try{$bytes=New-Object byte[] 32;$generator.GetBytes($bytes);$newPassword=[BitConverter]::ToString($bytes).Replace('-','').ToLowerInvariant();$generator.GetBytes($bytes);$newKey='base64:'+ [Convert]::ToBase64String($bytes)}finally{$generator.Dispose()}
$oldPassword=$credentials.runtime
$alter="ALTER USER 'sr_container_runtime'@'%' IDENTIFIED BY '"+$newPassword+"';"
$mysqlCommand='export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; exec mysql --user=root'
[void](Invoke-DockerBytes ($compose+@('exec','-T','mysql','bash','-c',$mysqlCommand)) ([Text.Encoding]::UTF8.GetBytes($alter)))
$phase='OLD_CREDENTIAL_CHECK'
try{
 $check='try{new PDO("mysql:host=mysql;dbname=servicios_moderno_stage",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));echo "ACCEPTED";}catch(PDOException $e){echo ($e->errorInfo[1]===1045)?"REJECTED":"UNEXPECTED";}'
 [byte[]]$checkResult=Invoke-DockerBytes ($compose+@('exec','-T','backend','php','-r',$check))
 if([Text.Encoding]::UTF8.GetString($checkResult) -ne 'REJECTED'){throw 'Old runtime credential was not rejected.'}
 $phase='WRITE_NEW_ENVIRONMENT'
 $environment=[regex]::Replace($oldEnvironment,'(?m)^DB_PASSWORD=.*$',('DB_PASSWORD="'+$newPassword+'"'))
 $phase='WRITE_NEW_ENVIRONMENT'
 $environment=[regex]::Replace($environment,'(?m)^APP_KEY=.*$',('APP_KEY="'+$newKey+'"'))
 if($environment -match '(?m)^APP_PREVIOUS_KEYS='){$environment=[regex]::Replace($environment,'(?m)^APP_PREVIOUS_KEYS=.*$',('APP_PREVIOUS_KEYS="'+$previousValue+'"'))}else{$environment+="`nAPP_PREVIOUS_KEYS=`""+$previousValue+"`"`n"}
 [IO.File]::WriteAllText($runtimeFile,$environment,(New-Object Text.UTF8Encoding($false)))
 $credentials.runtime=$newPassword;[IO.File]::WriteAllText($credentialsFile,($credentials|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 [void](Invoke-DockerBytes ($compose+@('up','-d','--no-build','--force-recreate','backend','worker')))
 $phase='APP_KEY_RECOVERY'
 $check=$bootstrap+'try{$old=app("encrypter")->decryptString(stream_get_contents(STDIN));$new=app("encrypter")->encryptString("NEW_KEY_REHEARSAL");echo json_encode(["previousKeyRecovers"=>$old==="KEY_ROTATION_REHEARSAL","newKeyWorks"=>app("encrypter")->decryptString($new)==="NEW_KEY_REHEARSAL"]);}catch(Throwable $e){echo json_encode(["recoveryError"=>get_class($e),"previousKeyCount"=>count(config("app.previous_keys"))]);}'
 [byte[]]$checked=Invoke-DockerBytes ($compose+@('exec','-T','backend','php','-r',$check)) $cipher
 $phase='APP_KEY_JSON_CHECK'
 $result=[Text.Encoding]::UTF8.GetString($checked)|ConvertFrom-Json
 Write-Output ('Rotation aggregate: '+($result|ConvertTo-Json -Compress))
 if(-not $result.previousKeyRecovers -or -not $result.newKeyWorks){throw 'Application encryption rotation failed.'}
 $phase='WORKER_RECOVERY'
 [void](Invoke-DockerBytes ($compose+@('exec','-T','backend','php','artisan','outbox:metrics','--check')))
 $evidence=[ordered]@{executedAt=[DateTime]::UtcNow.ToString('o');scope='isolated servicios_stage Docker rehearsal';oldDatabaseCredentialRejected=$true;previousAppKeyRecovers=$true;newAppKeyWorks=$true;credentialsNotEmitted=$true;previousKeyRetirement='After active session lifetime and re-encryption; still retained for recovery';entraRotation='Pending: application update returned HTTP 403'}
 [IO.File]::WriteAllText((Join-Path $projectRoot 'artifacts/rotations-20261008.json'),($evidence|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 Write-Output 'PASS: DB credential rotated; old login rejected; APP_KEY rotated with recovery; worker resumed.'
}catch{
 $failureCategory=if($_.Exception.Message -like 'Private Docker operation failed:*'){$_.Exception.Message}else{$parameter=[regex]::Match($_.Exception.Message,"parameter '([A-Za-z]+)'" );$_.Exception.GetType().FullName+'; parameter='+$parameter.Groups[1].Value}
 $revert="ALTER USER 'sr_container_runtime'@'%' IDENTIFIED BY '"+$oldPassword+"';"
 [void](Invoke-DockerBytes ($compose+@('exec','-T','mysql','bash','-c',$mysqlCommand)) ([Text.Encoding]::UTF8.GetBytes($revert)))
 [IO.File]::WriteAllText($runtimeFile,$oldEnvironment,(New-Object Text.UTF8Encoding($false)))
 $credentials.runtime=$oldPassword;[IO.File]::WriteAllText($credentialsFile,($credentials|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 [void](Invoke-DockerBytes ($compose+@('up','-d','--no-build','--force-recreate','backend','worker')))
 throw ('Rotation failed at '+$phase+': '+$failureCategory+'; previous credentials restored. No secret details emitted.')
}