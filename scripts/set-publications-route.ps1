[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('Enable','Disable')][string]$Action)
$ErrorActionPreference='Stop'
$projectRoot=Split-Path $PSScriptRoot -Parent
$dockerPath=Join-Path $env:LOCALAPPDATA 'Programs/DockerDesktop/resources/bin/docker.exe'
$maximumBytes=16MB
. (Join-Path $PSScriptRoot 'docker-private-io.ps1')
. (Join-Path $PSScriptRoot 'portable-private-io.ps1')
$profile=Join-Path $projectRoot '.tools/environments/container-runtime.env'
$original=[IO.File]::ReadAllText($profile)
$value=if($Action -eq 'Enable'){'true'}else{'false'}
$updated=Set-PrivateEnvironmentValue $original 'PUBLICATIONS_PUBLIC_ENABLED' $value
$compose=@('compose','--env-file',(Join-Path $projectRoot 'docker/environment.example'),'-p','servicios_stage')
$started=[DateTime]::UtcNow
try {
 [IO.File]::WriteAllText($profile,$updated,(New-Object Text.UTF8Encoding($false)))
 [void](Invoke-DockerBytes ($compose+@('up','-d','--no-deps','--force-recreate','backend','worker')))
 $expected=if($Action -eq 'Enable'){'200'}else{'404'}
 $actual=''
 for($attempt=0;$attempt -lt 30;$attempt++) {
  $actual=& curl.exe --silent --cacert (Join-Path $projectRoot 'artifacts/docker-tls/root.crt') --output NUL --write-out '%{http_code}' --max-time 3 'https://localhost:8443/api/v1/public/notices'
  if($actual -eq $expected){break}
  Start-Sleep -Milliseconds 500
 }
 if($actual -ne $expected){throw 'Publications route did not reach expected state.'}
 $result=[ordered]@{action=$Action;publicStatus=[int]$actual;expectedStatus=[int]$expected;tlsVerified=$true;seconds=([DateTime]::UtcNow-$started).TotalSeconds;dataDestruction=$false;legacyBackendConfigured=$false;environment='local staging'}
 $name='sprint3-route-'+$Action.ToLowerInvariant()+'-'+[DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')+'.json'
 [IO.File]::WriteAllText((Join-Path $projectRoot ('artifacts/'+$name)),($result|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
 Write-Output ('PASS Publications route '+$Action+'; HTTPS status '+$actual+'; persistent data preserved.')
} catch {
 [IO.File]::WriteAllText($profile,$original,(New-Object Text.UTF8Encoding($false)))
 [void](Invoke-DockerBytes ($compose+@('up','-d','--no-deps','--force-recreate','backend','worker')))
 throw 'Route change failed; original profile restored and services recreated.'
}
