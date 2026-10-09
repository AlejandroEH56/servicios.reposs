$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = Split-Path $PSScriptRoot -Parent
$toolRoot = Join-Path $projectRoot '.tools/operations'
[void][IO.Directory]::CreateDirectory($toolRoot)
$downloads = @(
    @{Version='2.11.7'; Repo='caddyserver/caddy'; Name='caddy_2.11.7_windows_amd64.zip'; Checksums='caddy_2.11.7_checksums.txt'},
    @{Version='5.6.0'; Repo='docker/compose'; Name='docker-compose-windows-x86_64.exe'; Checksums='checksums.txt'}
)
$verified = @()
foreach ($item in $downloads) {
    $releaseUrl = 'https://github.com/' + $item.Repo + '/releases/download/v' + $item.Version + '/'
    $file = Join-Path $toolRoot $item.Name
    $checksumFile = Join-Path $toolRoot $item.Checksums
    Invoke-WebRequest -UseBasicParsing -TimeoutSec 30 -Uri ($releaseUrl + $item.Checksums) -OutFile $checksumFile
    $checksums = [IO.File]::ReadAllText($checksumFile)
    $match = [regex]::Match($checksums, '(?m)^([a-fA-F0-9]{128}|[a-fA-F0-9]{64})\s+\*?' + [regex]::Escape($item.Name) + '\s*$')
    if (-not $match.Success) { throw 'El checksum oficial no contiene el artefacto esperado.' }
    $expected = $match.Groups[1].Value
    if (-not (Test-Path -LiteralPath $file) -or (Get-Item -LiteralPath $file).Length -eq 0) { Invoke-WebRequest -UseBasicParsing -TimeoutSec 180 -Uri ($releaseUrl + $item.Name) -OutFile $file }
    $algorithm = if ($expected.Length -eq 128) { 'SHA512' } else { 'SHA256' }
    if ((Get-FileHash -LiteralPath $file -Algorithm $algorithm).Hash -ne $expected) { throw 'Checksum de herramienta incorrecto; no se ejecutará.' }
    if ($item.Name.EndsWith('.zip')) { Expand-Archive -LiteralPath $file -DestinationPath $toolRoot -Force }
    $verified += @{tool=$item.Repo;version=$item.Version;officialAlgorithm=$algorithm;officialChecksum=$expected;sha256=(Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash;source=($releaseUrl + $item.Name)}
}
[IO.File]::WriteAllText((Join-Path $toolRoot 'verified-tools.json'), ($verified | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
Write-Host 'PASS: Caddy y Compose verificados contra los checksums oficiales.'
