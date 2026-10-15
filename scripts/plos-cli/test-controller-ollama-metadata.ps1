#requires -Version 5.1
param([Parameter(Mandatory)][string]$LifecyclePath)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
# Import function definitions only; never execute the native action dispatcher.
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($LifecyclePath,[ref]$tokens,[ref]$errors)
if($errors.Count){throw ($errors|Out-String)}
foreach($function in $ast.FindAll({param($node)$node -is [Management.Automation.Language.FunctionDefinitionAst]},$false)) {
 . ([scriptblock]::Create($function.Extent.Text))
}
$script:ExpectedHostId='fixture-host'
$script:ollamaStatusSnapshots=@{}
# Model-free actual snapshot/status controls. All OS, files and HTTP are mocked.
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$script:checks=0
function Check($Condition,$Message){if(-not $Condition){throw $Message};$script:checks++}
function Get-PLOSRuntimeBinding {param($Spec);return 'fixture-runtime'}
function Get-ScheduledTask {param($TaskName,$ErrorAction);return [pscustomobject]@{
 Actions=@([pscustomobject]@{Execute='powershell.exe';Arguments='fixture'});
 Principal=[pscustomobject]@{UserId='SYSTEM'};Triggers=@([pscustomobject]@{CimClass=[pscustomobject]@{CimClassName='MSFT_TaskBootTrigger'}});
 Settings=[pscustomobject]@{Enabled=$true};State='Running'}}
function Get-NetTCPConnection {param($State,$LocalPort,$ErrorAction);return [pscustomobject]@{LocalAddress='127.0.0.1';OwningProcess=123}}
function Get-PLOSOwnedProcesses {param($Id,$Spec);return [pscustomobject]@{ProcessId=123}}
function Get-PLOSSha256 {param($Path);return ('a'*64)}
function Invoke-RestMethod {
 param($Uri,$TimeoutSec)
 $script:probes+=@($Uri)
 $kind=if($Uri.EndsWith('/tags')){$script:tagsMode}else{$script:psMode}
 if($kind -ceq 'timeout'){throw [Net.WebException]::new('fixture timeout',$null,[Net.WebExceptionStatus]::Timeout,$null)}
 if($kind -ceq 'refused'){throw [Net.WebException]::new('fixture refusal',$null,[Net.WebExceptionStatus]::ConnectFailure,$null)}
 if($kind -ceq 'invalid'){return [pscustomobject]@{models='invalid-shape'}}
 $models=if($kind -ceq 'empty'){@()}else{@([pscustomobject]@{name=$(if($kind -ceq 'foreign'){'foreign'}else{'fixture-model'});digest=('b'*64);size=100;size_vram=100})}
 return [pscustomobject]@{models=@($models)}
}
$spec=[pscustomobject]@{adapter_type='ollama_coex';port=11434;task_name='fixture';control_path='fixture-control';executable_path='fixture-engine';control_sha256=('a'*64);executable_sha256=('a'*64);model_api_id='fixture-model';model_identity=('digest:'+('b'*64));task_user='SYSTEM';task_arguments='fixture';required_process_arguments=[pscustomobject]@{}}
function Observe([string]$Tags,[string]$Ps){$script:tagsMode=$Tags;$script:psMode=$Ps;$script:probes=@();$script:ollamaStatusSnapshots=@{};Get-PLOSTargetStatus -Id 'fixture' -Spec $spec}
$x=Observe 'exact' 'empty'
Check ($x.healthy -and $x.running_exact -and $x.model_exact -and $x.residency_verified -and -not $x.model_loaded_exact) 'Normal unloaded status remains exact'
$x=Observe 'timeout' 'empty'
Check ($script:probes.Count -eq 2) 'A tags timeout never skips the independent residency observation'
Check ($x.api_metadata_timed_out -and -not $x.model_identity_observed -and $x.residency_observed -and $x.foreign_loaded_count -eq 0) 'Partial timeout retains observed empty residency'
Check (-not $x.healthy -and -not $x.running_exact -and -not $x.model_exact -and $x.listener_owner_exact) 'Timeout preserves unknown health and exact process listener ownership'
$x=Observe 'exact' 'timeout'
Check ($x.api_metadata_timed_out -and $x.model_identity_observed -and $x.model_exact -and -not $x.residency_observed -and $null -eq $x.foreign_loaded_count) 'PS timeout preserves independently observed exact model identity'
$x=Observe 'timeout' 'timeout'
Check ($x.api_metadata_timed_out -and -not $x.model_identity_observed -and -not $x.residency_observed -and -not $x.residency_verified) 'Both timed out probes remain unobserved'
$x=Observe 'timeout' 'foreign'
Check ($x.foreign_loaded_count -eq 1 -and -not $x.residency_verified) 'Observed foreign residency survives tags timeout'
$x=Observe 'foreign' 'timeout'
Check ($x.model_identity_observed -and -not $x.model_exact) 'Observed wrong model identity survives PS timeout'
foreach($mode in @('invalid','refused')) {
 $x=Observe $mode 'timeout'
 Check (-not $x.api_metadata_timed_out -and -not $x.healthy) 'Explicit invalid or transport refusal cannot become a busy timeout'
 $x=Observe 'timeout' $mode
 Check (-not $x.api_metadata_timed_out -and -not $x.residency_observed) 'Negative residency response cannot become a busy timeout'
}
[ordered]@{read_only=$true;model_requests=0;host_writes=0;checks=$script:checks}|ConvertTo-Json -Compress
