[CmdletBinding()]
param([string] $DeveloperSid = '')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$privateRoot = Join-Path $projectRoot '.tools/environments'
[void][IO.Directory]::CreateDirectory($privateRoot)
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
if ($identity -match '\\CodexSandbox' -and -not $DeveloperSid) { throw 'Indica el SID comprobado del desarrollador al ejecutar desde el sandbox.' }
$developer = if ($DeveloperSid) { New-Object Security.Principal.SecurityIdentifier($DeveloperSid) } else { [Security.Principal.WindowsIdentity]::GetCurrent().User }
$paths = @($privateRoot)
$paths += Get-ChildItem -LiteralPath $projectRoot -Filter '.env*' -File |
    Where-Object { $_.Name -ne '.env.example' -and -not $_.Name.EndsWith('.example') } |
    ForEach-Object { $_.FullName }
foreach ($path in $paths) {
    $isDirectory = Test-Path -LiteralPath $path -PathType Container
    $acl = if ($isDirectory) { New-Object Security.AccessControl.DirectorySecurity } else { New-Object Security.AccessControl.FileSecurity }
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = if ($isDirectory) { 'ContainerInherit, ObjectInherit' } else { 'None' }
    $principals = @($developer,
        (New-Object Security.Principal.SecurityIdentifier('S-1-5-18')),
        (New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))
    try {
        $automation = New-Object Security.Principal.NTAccount($env:COMPUTERNAME, 'CodexSandboxOffline')
        $principals += $automation.Translate([Security.Principal.SecurityIdentifier])
    } catch [Security.Principal.IdentityNotMappedException] { }
    foreach ($principal in $principals) {
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($principal, 'FullControl', $inheritance, 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    try {
        if ($isDirectory) { [IO.Directory]::SetAccessControl($path, $acl) } else { [IO.File]::SetAccessControl($path, $acl) }
    } catch { throw ('No se pudo restringir la ACL de ' + $path) }
}
Write-Host 'PASS: credenciales restringidas al desarrollador, SYSTEM, administradores y la cuenta de automatización autorizada.'
