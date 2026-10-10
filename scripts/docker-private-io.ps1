function Quote-Argument([string]$value){
 if($value -notmatch '[\s"]'){return $value}
 $escaped=[regex]::Replace($value,'(\\*)"', '$1$1\"')
 $escaped=[regex]::Replace($escaped,'(\\+)$','$1$1')
 return '"'+$escaped+'"'
}
function Invoke-DockerBytes([string[]]$Arguments,[byte[]]$InputBytes){
 $start=New-Object Diagnostics.ProcessStartInfo
 $start.FileName=$dockerPath
 $start.Arguments=($Arguments | ForEach-Object {Quote-Argument $_}) -join ' '
 $start.UseShellExecute=$false;$start.CreateNoWindow=$true
 $start.RedirectStandardInput=$true;$start.RedirectStandardOutput=$true;$start.RedirectStandardError=$true
 $process=New-Object Diagnostics.Process;$process.StartInfo=$start
 $output=New-Object IO.MemoryStream
 try{
  [void]$process.Start();$errorTask=$process.StandardError.ReadToEndAsync()
  if($InputBytes){$writeTask=$process.StandardInput.BaseStream.WriteAsync($InputBytes,0,$InputBytes.Length);[void]$writeTask.GetAwaiter().GetResult()}
  $process.StandardInput.Close()
  $buffer=New-Object byte[] 65536
  $readClock=[Diagnostics.Stopwatch]::StartNew()
  while($true){
   $remaining=60000-[int]$readClock.ElapsedMilliseconds
   if($remaining -le 0){$process.Kill();throw 'Docker output deadline exceeded.'}
   $readTask=$process.StandardOutput.BaseStream.ReadAsync($buffer,0,$buffer.Length)
   if(-not $readTask.Wait($remaining)){$process.Kill();throw 'Docker output deadline exceeded.'}
   $count=$readTask.Result
   if($count -eq 0){break}
   if($output.Length+$count -gt $maximumBytes){$process.Kill();throw 'Snapshot exceeds local memory limit; use streaming backup before scaling.'}
   $output.Write($buffer,0,$count)
  }
  if(-not $process.WaitForExit(60000)){$process.Kill();throw 'Docker operation timed out.'}
  $privateError=$errorTask.GetAwaiter().GetResult()
  if($process.ExitCode -ne 0){$category=if($privateError -match 'Parse error|syntax error'){'PARSE_ERROR'}elseif($privateError -match 'Undefined constant'){ 'UNDEFINED_CONSTANT'}elseif($privateError -match 'Fatal error'){ 'FATAL_ERROR'}else{'DOCKER_ERROR'};throw ('Private Docker operation failed: '+$category)}
  return ,$output.ToArray()
 }finally{$output.Dispose();$process.Dispose()}
}
function Get-Digest([byte[]]$Bytes){$sha=[Security.Cryptography.SHA256]::Create();try{return [BitConverter]::ToString($sha.ComputeHash($Bytes)).Replace('-','').ToLowerInvariant()}finally{$sha.Dispose()}}
function Get-DatabaseDigest([byte[]]$Bytes){
 $sql=[Text.Encoding]::UTF8.GetString($Bytes)
 $canonical=[regex]::Replace($sql,'(?m)^(  `[^`]+` [^\r\n]*?) CHARACTER SET (utf8mb4|ascii) COLLATE \2_','$1 COLLATE $2_')
 return Get-Digest ([Text.Encoding]::UTF8.GetBytes($canonical))
}
function Protect-Bytes([byte[]]$Bytes,[string]$Path){$encrypted=[Security.Cryptography.ProtectedData]::Protect($Bytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser);[IO.File]::WriteAllBytes($Path,$encrypted)}
function Unprotect-Bytes([string]$Path,[string]$Digest){$bytes=[Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes($Path),$null,[Security.Cryptography.DataProtectionScope]::CurrentUser);if((Get-Digest $bytes) -ne $Digest){throw 'Snapshot integrity mismatch.'};return ,$bytes}
