function New-PrivateProcess([string]$Executable,[string[]]$Arguments) {
 $info=New-Object Diagnostics.ProcessStartInfo
 $info.FileName=$Executable
 $info.Arguments=($Arguments|ForEach-Object {Quote-Argument $_}) -join ' '
 $info.UseShellExecute=$false;$info.CreateNoWindow=$true
 $info.RedirectStandardInput=$true;$info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
 $process=New-Object Diagnostics.Process;$process.StartInfo=$info
 return $process
}
function Invoke-PrivateBytes([string]$Executable,[string[]]$Arguments,[byte[]]$InputBytes) {
 $process=New-PrivateProcess $Executable $Arguments
 $output=New-Object IO.MemoryStream
 try {
  [void]$process.Start();$errors=$process.StandardError.ReadToEndAsync();$read=$process.StandardOutput.BaseStream.CopyToAsync($output)
  if($InputBytes){$write=$process.StandardInput.BaseStream.WriteAsync($InputBytes,0,$InputBytes.Length);if(-not $write.Wait(60000)){throw 'Private input timed out.'}}
  $process.StandardInput.Close()
  if(-not $process.WaitForExit(60000)){throw 'Private process timed out.'}
  if(-not $read.Wait(10000)){throw 'Private output timed out.'}
  [void]$errors.GetAwaiter().GetResult()
  if($process.ExitCode -ne 0 -or $output.Length -gt 16MB){throw 'Private operation failed; no secret output emitted.'}
  return ,$output.ToArray()
 } finally {
  try{if(-not $process.HasExited){$process.Kill()}}catch{}
  $output.Dispose();$process.Dispose()
 }
}
function Invoke-PrivatePipeline([string]$SourceExecutable,[string[]]$SourceArguments,[string]$TargetExecutable,[string[]]$TargetArguments,[string]$OutputPath,[switch]$CanonicalSql,[int]$TimeoutSeconds=900) {
 $source=New-PrivateProcess $SourceExecutable $SourceArguments
 $target=New-PrivateProcess $TargetExecutable $TargetArguments
 $output=$null;$reader=$null;$hash=[Security.Cryptography.SHA256]::Create();$clock=[Diagnostics.Stopwatch]::StartNew();$total=0L
 try {
  $output=if($OutputPath){[IO.File]::Open($OutputPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)}else{New-Object IO.MemoryStream}
  [void]$target.Start();$targetErrors=$target.StandardError.ReadToEndAsync();$targetOutput=$target.StandardOutput.BaseStream.CopyToAsync($output);$target.StandardInput.AutoFlush=$false
  [void]$source.Start();$sourceErrors=$source.StandardError.ReadToEndAsync();$source.StandardInput.Close()
  if($CanonicalSql){$reader=New-Object IO.StreamReader($source.StandardOutput.BaseStream,(New-Object Text.UTF8Encoding($false)),$false,65536,$true)}
  $buffer=New-Object byte[] 65536
  while($true) {
   $remaining=$TimeoutSeconds*1000-[int]$clock.ElapsedMilliseconds
   if($remaining -le 0){throw 'Portable operation deadline exceeded.'}
   if($CanonicalSql) {
    $task=$reader.ReadLineAsync();if(-not $task.Wait($remaining)){throw 'SQL stream timed out.'};$line=$task.Result
    if($null -eq $line){break}
    if($line.Length -gt 16MB){throw 'SQL row exceeds 16 MiB line bound; use separate oversized-object export.'}
    $line=[regex]::Replace($line,'^(  `[^`]+` .*?) CHARACTER SET (utf8mb4|ascii) COLLATE \2_','$1 COLLATE $2_')
    $chunk=[Text.Encoding]::UTF8.GetBytes($line+"`n");$count=$chunk.Length
   } else {
    $task=$source.StandardOutput.BaseStream.ReadAsync($buffer,0,$buffer.Length);if(-not $task.Wait($remaining)){throw 'Private stream timed out.'};$count=$task.Result
    if($count -eq 0){break};$chunk=$buffer
   }
   [void]$hash.TransformBlock($chunk,0,$count,$null,0)
   $write=$target.StandardInput.BaseStream.WriteAsync($chunk,0,$count);if(-not $write.Wait($remaining)){throw 'Private destination timed out.'}
   $total+=$count
  }
  $target.StandardInput.Close()
  $remaining=$TimeoutSeconds*1000-[int]$clock.ElapsedMilliseconds
  if($remaining -le 0 -or -not $source.WaitForExit($remaining)){throw 'Private source failed to finish.'}
  $remaining=$TimeoutSeconds*1000-[int]$clock.ElapsedMilliseconds
  if($remaining -le 0 -or -not $target.WaitForExit($remaining)){throw 'Private destination failed to finish.'}
  if(-not $targetOutput.Wait(10000)){throw 'Private output failed to finish.'}
  [void]$sourceErrors.GetAwaiter().GetResult();[void]$targetErrors.GetAwaiter().GetResult()
  if($source.ExitCode -ne 0 -or $target.ExitCode -ne 0){throw 'Portable stream failed; secret diagnostics suppressed.'}
  [void]$hash.TransformFinalBlock((New-Object byte[] 0),0,0)
  return [ordered]@{inputSha256=[BitConverter]::ToString($hash.Hash).Replace('-','').ToLowerInvariant();inputBytes=$total;outputBytes=$output.Length;seconds=$clock.Elapsed.TotalSeconds}
 } finally {
  foreach($process in @($source,$target)){try{if(-not $process.HasExited){$process.Kill()}}catch{};$process.Dispose()}
  if($reader){$reader.Dispose()};if($output){$output.Dispose()};$hash.Dispose()
 }
}
function Set-PrivateDirectory([string]$Path) {
 [void][IO.Directory]::CreateDirectory($Path)
 if([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT) {
  $owner='*'+[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
  & icacls.exe $Path /inheritance:r /grant:r ($owner+':(OI)(CI)F') '*S-1-5-18:(OI)(CI)F' | Out-Null
  if($LASTEXITCODE -ne 0){throw 'Private directory ACL failed.'}
 } else {
  & chmod 700 -- $Path
  if($LASTEXITCODE -ne 0){throw 'Private directory mode failed.'}
 }
}
function Get-EnvironmentValue([string]$Content,[string]$Name) {
 $match=[regex]::Match($Content,'(?m)^'+[regex]::Escape($Name)+'=(?:"([^"\r\n]*)"|([^\r\n]*))\r?$')
 if(-not $match.Success){throw ('Missing environment field: '+$Name)}
 if($match.Groups[1].Success){return $match.Groups[1].Value}
 return $match.Groups[2].Value.Trim()
}
function Set-PrivateEnvironmentValue([string]$Content,[string]$Name,[string]$Value) {
 if($Value -match '["\r\n]'){throw 'Unsupported environment value.'}
 $line=$Name+'="'+$Value+'"';$pattern='(?m)^'+[regex]::Escape($Name)+'=.*$'
 if($Content -match $pattern){return [regex]::Replace($Content,$pattern,[Text.RegularExpressions.MatchEvaluator]{param($m)$line})}
 return $Content+"`n"+$line+"`n"
}
