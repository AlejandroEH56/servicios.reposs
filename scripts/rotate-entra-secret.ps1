[CmdletBinding()]
param([ValidateSet('Prepare','Activate','VerifyRevoked')][string]$Action='Prepare')
$ErrorActionPreference='Stop'
$projectRoot=Split-Path $PSScriptRoot -Parent
$dockerPath=Join-Path $env:LOCALAPPDATA 'Programs/DockerDesktop/resources/bin/docker.exe'
$maximumBytes=16MB
. (Join-Path $PSScriptRoot 'docker-private-io.ps1')
. (Join-Path $PSScriptRoot 'portable-private-io.ps1')
$age=Join-Path $projectRoot '.tools/quality/age/windows/age/age.exe'
$keyFile=Join-Path $projectRoot '.tools/recovery-keys/age-identity.txt'
$recipient=([IO.File]::ReadAllText((Join-Path $projectRoot '.tools/recovery-keys/recipient.txt'))).Trim()
$privateRoot=Join-Path $projectRoot '.tools/environments/entra-rotation'
Set-PrivateDirectory $privateRoot
$stateFile=Join-Path $privateRoot 'before.age'
$php=Join-Path $projectRoot '.tools/php/php.exe'
$probe=@'
require 'vendor/autoload.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$result=[];
try {
 $client=new GuzzleHttp\Client(['timeout'=>20,'connect_timeout'=>5,'allow_redirects'=>false,'http_errors'=>false]);
 $response=$client->post('https://login.microsoftonline.com/'.$input['tenant'].'/oauth2/v2.0/token',['form_params'=>['client_id'=>$input['client'],'client_secret'=>$input['secret'],'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']]);
 $body=json_decode((string)$response->getBody(),true);$result['tokenHttpStatus']=$response->getStatusCode();$result['oauthErrorCodes']=$body['error_codes']??[];
 if(isset($body['access_token'])){
  $response=$client->get("https://graph.microsoft.com/v1.0/applications(appId='".$input['client']."')",['headers'=>['Authorization'=>'Bearer '.$body['access_token']],'query'=>['$select'=>'passwordCredentials']]);
  $result['registrationReadHttpStatus']=$response->getStatusCode();$result['credentialKeyIds']=array_column(json_decode((string)$response->getBody(),true)['passwordCredentials']??[],'keyId');
 }
} catch(Throwable $error){$result['errorType']=get_class($error);}
echo json_encode($result,JSON_THROW_ON_ERROR);
'@
function Test-Credential([object]$State) {
 $input=[Text.Encoding]::UTF8.GetBytes(($State|ConvertTo-Json -Depth 8 -Compress))
 $output=Invoke-PrivateBytes $php @('-r',$probe) $input
 return [Text.Encoding]::UTF8.GetString($output)|ConvertFrom-Json
}
$profilePaths=@('.env','.env.staging','.env.production','.env.operator','.env.migrator','.env.staging-migrator','.env.production-migrator','.tools/environments/container-runtime.env','.tools/environments/container-migrator.env','.tools/environments/container-operator.env')
$evidencePath=Join-Path $projectRoot 'artifacts/entra-rotation.json'
if($Action -eq 'Prepare') {
 if(Test-Path -LiteralPath $stateFile){throw 'Rotation already prepared; use Activate or VerifyRevoked.'}
 $base=[IO.File]::ReadAllText((Join-Path $projectRoot '.env.staging'))
 $state=[ordered]@{tenant=(Get-EnvironmentValue $base 'MICROSOFT_TENANT_ID');client=(Get-EnvironmentValue $base 'MICROSOFT_CLIENT_ID');secret=(Get-EnvironmentValue $base 'MICROSOFT_CLIENT_SECRET')}
 $before=Test-Credential $state
 if($before.tokenHttpStatus -ne 200 -or $before.registrationReadHttpStatus -ne 200 -or @($before.credentialKeyIds).Count -ne 1){throw 'Prepare requires one verified current credential.'}
 $state['oldKeyId']=$before.credentialKeyIds[0]
 [void](Invoke-PrivateBytes $age @('-r',$recipient,'-o',$stateFile) ([Text.Encoding]::UTF8.GetBytes(($state|ConvertTo-Json -Depth 8))))
 $evidence=[ordered]@{preparedAt=[DateTime]::UtcNow.ToString('o');currentCredentialAccepted=$true;oldCredentialBackupEncryptedWithAge=$true;phase='PREPARED';newCredentialActivated=$false;oldCredentialRevoked=$false;secretValuesExported=$false}
 [IO.File]::WriteAllText($evidencePath,($evidence|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 Write-Output 'PREPARED: copia age de credencial previa verificada. Crear sustituta en Entra y actualizar sólo MICROSOFT_CLIENT_SECRET en .env; conservar anterior hasta validar login.'
 exit 0
}
$bytes=Invoke-PrivateBytes $age @('-d','-i',$keyFile,$stateFile)
$old=[Text.Encoding]::UTF8.GetString($bytes)|ConvertFrom-Json
$base=[IO.File]::ReadAllText((Join-Path $projectRoot '.env'))
$new=[ordered]@{tenant=(Get-EnvironmentValue $base 'MICROSOFT_TENANT_ID');client=(Get-EnvironmentValue $base 'MICROSOFT_CLIENT_ID');secret=(Get-EnvironmentValue $base 'MICROSOFT_CLIENT_SECRET')}
if($new.tenant -ne $old.tenant -or $new.client -ne $old.client -or $new.secret -eq $old.secret){throw 'New secret must belong to the same app and differ from prepared secret.'}
$checked=Test-Credential $new
if($checked.tokenHttpStatus -ne 200){throw 'New credential rejected; no profiles changed.'}
$evidence=Get-Content -LiteralPath $evidencePath -Raw|ConvertFrom-Json
if($Action -eq 'Activate') {
 $rollbackFile=Join-Path $privateRoot 'profiles-before.age'
 if(Test-Path -LiteralPath $rollbackFile){throw 'Activation already attempted; inspect aggregate evidence before retry.'}
 $profiles=[ordered]@{}
 foreach($relative in $profilePaths){$path=Join-Path $projectRoot $relative;if(Test-Path -LiteralPath $path){$profiles[$relative]=[IO.File]::ReadAllText($path)}}
 [void](Invoke-PrivateBytes $age @('-r',$recipient,'-o',$rollbackFile) ([Text.Encoding]::UTF8.GetBytes(($profiles|ConvertTo-Json -Depth 8))))
 try {
  foreach($relative in $profiles.Keys){$content=Set-PrivateEnvironmentValue $profiles[$relative] 'MICROSOFT_CLIENT_SECRET' $new.secret;[IO.File]::WriteAllText((Join-Path $projectRoot $relative),$content,(New-Object Text.UTF8Encoding($false)))}
  [void](Invoke-DockerBytes @('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'),'-p','servicios_stage','up','-d','--no-build','--force-recreate','backend','worker'))
 } catch {
  foreach($relative in $profiles.Keys){[IO.File]::WriteAllText((Join-Path $projectRoot $relative),$profiles[$relative],(New-Object Text.UTF8Encoding($false)))}
  [void](Invoke-DockerBytes @('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'),'-p','servicios_stage','up','-d','--no-build','--force-recreate','backend','worker'))
  throw 'Activation failed; profile contents restored; secret diagnostics suppressed.'
 }
 $evidence|Add-Member -NotePropertyName activatedAt -NotePropertyValue ([DateTime]::UtcNow.ToString('o')) -Force
 $evidence.newCredentialActivated=$true;$evidence.phase='ACTIVATED_PENDING_REAL_LOGIN_AND_REVOCATION'
 Write-Output 'ACTIVATED: perfiles sincronizados; validar login real antes de eliminar el secreto anterior en Entra.'
} else {
 $oldChecked=Test-Credential $old
 $evidence|Add-Member -NotePropertyName oldCredentialHttpStatus -NotePropertyValue $oldChecked.tokenHttpStatus -Force
 $evidence|Add-Member -NotePropertyName oldCredentialOAuthErrorCodes -NotePropertyValue @($oldChecked.oauthErrorCodes) -Force
 $oldKeyAbsent=$checked.registrationReadHttpStatus -eq 200 -and -not(@($checked.credentialKeyIds) -contains $old.oldKeyId)
 $oldRejected=$oldChecked.tokenHttpStatus -in @(400,401) -and @($oldChecked.oauthErrorCodes|Where-Object {$_ -in @(7000215,7000222)}).Count -gt 0
 $evidence|Add-Member -NotePropertyName verifiedAt -NotePropertyValue ([DateTime]::UtcNow.ToString('o')) -Force
 $evidence|Add-Member -NotePropertyName oldKeyAbsentFromRegistration -NotePropertyValue $oldKeyAbsent -Force
 $evidence|Add-Member -NotePropertyName oldCredentialRejected -NotePropertyValue $oldRejected -Force
 $evidence|Add-Member -NotePropertyName newCredentialAccepted -NotePropertyValue $true -Force
 $evidence.oldCredentialRevoked=$oldKeyAbsent -and $oldRejected
 if($evidence.oldCredentialRevoked){$evidence.phase='ROTATION_VERIFIED'}else{$evidence.phase='REVOCATION_PENDING'}
 [IO.File]::WriteAllText($evidencePath,($evidence|ConvertTo-Json -Depth 10),(New-Object Text.UTF8Encoding($false)))
 if(-not $evidence.oldCredentialRevoked){throw 'New secret works; old secret revocation not yet verified.'}
 Write-Output 'PASS: sustituta aceptada; anterior ausente del registro y rechazada por OAuth.'
}
[IO.File]::WriteAllText($evidencePath,($evidence|ConvertTo-Json -Depth 10),(New-Object Text.UTF8Encoding($false)))
