[CmdletBinding()]
param()
$ErrorActionPreference='Stop';$ProgressPreference='SilentlyContinue'
$root=Split-Path $PSScriptRoot -Parent
$specs=@(
 @{asset='age-v1.3.2-windows-amd64.zip';platform='windows';hash='f48d8f8f9ebe903ab5027ed067652f2cc1db94bc206976430133b905dcd8e8c7'},
 @{asset='age-v1.3.2-linux-amd64.tar.gz';platform='linux';hash='cbe24006683f8eb669266162894b9a522a1af52f2665fbc63a4bb032ed26ac10'}
)
$records=@()
foreach($spec in $specs){
 $directory=Join-Path $root ('.tools/quality/age/'+$spec.platform);[void][IO.Directory]::CreateDirectory($directory)
 $archive=Join-Path $directory $spec.asset;$url='https://github.com/FiloSottile/age/releases/download/v1.3.2/'+$spec.asset
 if(-not(Test-Path -LiteralPath $archive)){Invoke-WebRequest -Uri $url -OutFile $archive -UseBasicParsing -TimeoutSec 120}
 $actual=(Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
 if($actual -ne $spec.hash){throw 'age release digest mismatch.'}
 if($spec.platform -eq 'windows'){Expand-Archive -LiteralPath $archive -DestinationPath $directory -Force}else{& tar.exe -xzf $archive -C $directory;if($LASTEXITCODE -ne 0){throw 'age archive extraction failed.'}}
 $records+=[ordered]@{version='1.3.2';platform=$spec.platform;source=$url;sha256=$actual;verification='Pinned publisher release asset digest';verifiedAt=[DateTime]::UtcNow.ToString('o')}
}
[IO.File]::WriteAllText((Join-Path $root '.tools/quality/age/verified-tools.json'),($records|ConvertTo-Json -Depth 8),(New-Object Text.UTF8Encoding($false)))
Write-Output 'PASS age 1.3.2 Windows/Linux verified and installed locally.'
