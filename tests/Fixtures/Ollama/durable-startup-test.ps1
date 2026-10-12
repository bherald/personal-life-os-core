param([Parameter(Mandatory=$true)][string]$Source)
$ErrorActionPreference='Stop'
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($Source,[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'Source parse failed'}
# Load only pure guard and receipt functions, never the script's host actions.
foreach($name in @('Update-PLOSIngressStartupState','Write-PLOSDurableFailure')){
 $node=$ast.Find({param($n) $n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name},$true)
 if(-not $node){throw "Missing function: $name"}
 . ([scriptblock]::Create($node.Extent.Text))
}
$at=[datetime]'2026-01-01T00:00:00Z';$deadline=$at.AddSeconds(180);$checks=0
function State([bool]$open){[pscustomobject]@{checkpoint_valid=$true;production_remote='192.168.50.20';ingress_open=$open;coexistence_firewall_exact=$open;isolated=(-not $open)}}
function Reject($s,[bool]$opened,[datetime]$time){
 $rejected=$false
 try{$null=Update-PLOSIngressStartupState $s $opened $deadline $time}catch{$rejected=$true}
 if(-not $rejected){throw 'Expected ingress rejection'}
 $script:checks++
}
if(Update-PLOSIngressStartupState (State $false) $false $deadline $at){throw 'Startup isolation incorrectly opened'};$checks++
if(-not(Update-PLOSIngressStartupState (State $true) $false $deadline $at)){throw 'Exact opening rejected'};$checks++
if(-not(Update-PLOSIngressStartupState (State $true) $true $deadline $deadline.AddSeconds(1))){throw 'Established ingress rejected'};$checks++
Reject (State $false) $true $at
Reject (State $false) $false $deadline
Reject (State $true) $false $deadline
$s=State $true;$s.coexistence_firewall_exact=$false;Reject $s $false $at
$s=State $false;$s.isolated=$false;Reject $s $false $at
$s=State $false;$s.checkpoint_valid=$false;Reject $s $false $at
$s=State $false;$s.production_remote='192.0.2.1';Reject $s $false $at
foreach($field in @('checkpoint_valid','ingress_open','coexistence_firewall_exact','isolated')){
 $s=State $false;$s.$field='false';Reject $s $false $at
 $s=State $false;$s.PSObject.Properties.Remove($field);Reject $s $false $at
}
$verificationRoot=Join-Path ([IO.Path]::GetTempPath()) ('plos-durable-fixture-'+[guid]::NewGuid().ToString('N'))
try{
 try{throw 'PRIVATE-FIXTURE-TEXT'}catch{Write-PLOSDurableFailure -Stage 'identity' -Failure $_}
 $raw=[IO.File]::ReadAllText((Join-Path $verificationRoot 'ollama-durable-last-failure.json'))
 $r=$raw|ConvertFrom-Json
 if($raw.Contains('PRIVATE-FIXTURE-TEXT') -or $r.contains_prompt_or_response_content -ne $false -or $r.stage -ne 'identity'){throw 'Unsafe receipt'};$checks++
 Write-PLOSDurableFailure -Stage 'process_exit' -ExitCode 7
 $r=Get-Content -Raw (Join-Path $verificationRoot 'ollama-durable-last-failure.json')|ConvertFrom-Json
 if($r.exit_code -ne 7){throw 'Exit receipt mismatch'};$checks++
}finally{
 $f=Join-Path $verificationRoot 'ollama-durable-last-failure.json'
 if(Test-Path -LiteralPath $f){Remove-Item -LiteralPath $f}
 if(Test-Path -LiteralPath $verificationRoot){[IO.Directory]::Delete($verificationRoot,$false)}
}
[ordered]@{passed=$true;checks=$checks;host_actions=0;model_requests=0}|ConvertTo-Json -Compress
