[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = Split-Path $PSScriptRoot -Parent
$toolRoot = Join-Path $projectRoot '.tools/quality'
[void][IO.Directory]::CreateDirectory($toolRoot)
$headers = @{ 'User-Agent'='servicios-modernization'; Accept='application/vnd.github+json' }
$specifications = @(
    @{name='syft';repo='anchore/syft';tag='v1.54.1';asset='syft_1.54.1_windows_amd64.zip';checksum='syft_1.54.1_checksums.txt'},
    @{name='grype';repo='anchore/grype';tag='v0.120.1';asset='grype_0.120.1_windows_amd64.zip';checksum='grype_0.120.1_checksums.txt'},
    @{name='gitleaks';repo='gitleaks/gitleaks';tag='v8.30.1';asset='gitleaks_8.30.1_windows_x64.zip';checksum='gitleaks_8.30.1_checksums.txt'},
    @{name='oasdiff';repo='oasdiff/oasdiff';tag='v1.33.0';asset='oasdiff_1.33.0_windows_amd64.tar.gz';checksum='checksums.txt'},
    @{name='gh';repo='cli/cli';tag='v2.102.0';asset='gh_2.102.0_windows_amd64.zip';checksum='gh_2.102.0_checksums.txt'}
)
$records = @()
foreach ($specification in $specifications) {
    $release = Invoke-RestMethod -Uri ('https://api.github.com/repos/' + $specification.repo + '/releases/tags/' + $specification.tag) -Headers $headers
    $archiveAsset = $release.assets | Where-Object name -eq $specification.asset
    $checksumAsset = $release.assets | Where-Object name -eq $specification.checksum
    if (-not $archiveAsset -or -not $checksumAsset) { throw ('Assets oficiales ausentes: ' + $specification.name) }
    $directory = Join-Path $toolRoot $specification.name
    [void][IO.Directory]::CreateDirectory($directory)
    $archive = Join-Path $directory $specification.asset
    $checksumFile = Join-Path $directory $specification.checksum
    Invoke-WebRequest -Uri $checksumAsset.browser_download_url -OutFile $checksumFile -UseBasicParsing
    $pattern = '(?m)^([a-fA-F0-9]{64})[ \t]+\*?' + [regex]::Escape($specification.asset) + '\r?$'
    $match = [regex]::Match([IO.File]::ReadAllText($checksumFile), $pattern)
    if (-not $match.Success) { throw ('Checksum oficial ausente: ' + $specification.name) }
    if (-not (Test-Path -LiteralPath $archive) -or (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $match.Groups[1].Value.ToLowerInvariant()) {
        Invoke-WebRequest -Uri $archiveAsset.browser_download_url -OutFile $archive -UseBasicParsing
    }
    $actual = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne $match.Groups[1].Value.ToLowerInvariant()) { throw ('Checksum incorrecto: ' + $specification.name) }
    if ($archiveAsset.digest -and $archiveAsset.digest -ne ('sha256:' + $actual)) { throw 'Digest GitHub incompatible.' }
    if ($specification.asset.EndsWith('.zip')) {
        Expand-Archive -LiteralPath $archive -DestinationPath $directory -Force
    } else {
        & tar.exe -xzf $archive -C $directory
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo extraer el archivo verificado.' }
    }
    $executables = @(Get-ChildItem -LiteralPath $directory -Recurse -File -Filter ($specification.name + '.exe'))
    if ($executables.Count -ne 1) { throw 'Ejecutable no identificado de forma única.' }
    $records += [ordered]@{name=$specification.name;version=$specification.tag;source=$archiveAsset.browser_download_url;sha256=$actual;executable=$executables[0].FullName;verifiedAt=[DateTime]::UtcNow.ToString('o')}
    Write-Host ('PASS: ' + $specification.name + ' ' + $specification.tag + ' checksum oficial verificado.')
}
[IO.File]::WriteAllText((Join-Path $toolRoot 'verified-tools.json'), ($records | ConvertTo-Json -Depth 5), (New-Object Text.UTF8Encoding($false)))
