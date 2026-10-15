param([Parameter(Mandatory=$true)][string]$AdapterPath,[Parameter(Mandatory=$true)][string]$LifecyclePath)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
. $AdapterPath
$tokens=$null;$errors=$null;$ast=[Management.Automation.Language.Parser]::ParseFile($LifecyclePath,[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'Lifecycle parse failed'}
$f=$ast.Find({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -ceq 'Invoke-PLOSActivate'},$true)
. ([scriptblock]::Create($f.Extent.Text))
$script:checks=0
function Check([bool]$Value,[string]$Name){if(-not $Value){throw ('Failed: '+$Name)};$script:checks++}
function Reset-Fixture {
 $script:spec=[pscustomobject]@{task_name='fixture';startup_timeout_seconds=1;adapter_type='authenticated_reranker';switch_budget_exempt=$false}
 $script:mode='launch_error';$script:stopped=$false;$script:cleanupFails=$false;$script:remaining=0;$script:drift=$false
 $script:foreignBefore=$false;$script:starts=0;$script:stops=0;$script:records=@();$script:writes=0
 $script:state=[pscustomobject]@{lease=$null;technical_fences=@();switches=@()}
}
function Get-PLOSRerankerTargetStatus {param($Spec)
 [pscustomobject]@{task_exact=(-not $script:drift);control_exact=$true;executable_exact=$true;runtime_files_exact=$true;
 running_exact=($script:mode -ceq 'ready' -and $script:starts -gt 0 -and -not $script:stopped);
 task_enabled=(-not $script:stopped);task_state=$(if($script:stopped){'Disabled'}elseif($script:mode -ceq 'task_exited'){'Ready'}else{'Running'});
 owned_process_count=$(if($script:foreignBefore){1}else{$script:remaining});loopback_listener_count=0}
}
function Enable-ScheduledTask {param($TaskName)}
function Start-ScheduledTask {param($TaskName);$script:starts++;if($script:mode -ceq 'launch_error'){throw 'Synthetic launch error'}}
function Stop-PLOSRerankerTarget {param($Spec);$script:stops++;if($script:cleanupFails){throw 'Synthetic unknown ownership'};$script:stopped=$true}
function Start-Sleep {param($Milliseconds);if($script:mode -ceq 'timeout'){[Threading.Thread]::Sleep(1100)}}
foreach($mode in @('launch_error','task_exited','timeout')) {
 Reset-Fixture;$script:mode=$mode;$caught=$null;try{Start-PLOSRerankerTarget $script:spec}catch{$caught=$_}
 $code=switch($mode){'launch_error'{'startup_error'}'task_exited'{'task_exited'}'timeout'{'readiness_timeout'}}
 Check ($null -ne $caught -and $caught.Exception.Data.Contains('plos_startup_failure')) ('verified failure '+$mode)
 Check ($caught.Exception.Data['plos_startup_failure'].code -ceq $code -and $script:stops -eq 1) ('closed reason '+$mode)
}
foreach($fault in @('unknown_stop','remaining_process','identity_drift','preexisting_process')) {
 Reset-Fixture
 switch($fault){'unknown_stop'{$script:cleanupFails=$true}'remaining_process'{$script:remaining=1}
  'identity_drift'{$script:drift=$true}'preexisting_process'{$script:foreignBefore=$true}}
 $caught=$null;try{Start-PLOSRerankerTarget $script:spec}catch{$caught=$_}
 Check ($null -ne $caught -and -not $caught.Exception.Data.Contains('plos_startup_failure')) ('unsafe ownership held '+$fault)
 if($fault -in @('identity_drift','preexisting_process')){Check ($script:starts -eq 0 -and $script:stops -eq 0) ('foreign state untouched '+$fault)}
}
Reset-Fixture;$script:mode='ready';Start-PLOSRerankerTarget $script:spec
Check ($script:starts -eq 1 -and $script:stops -eq 0) 'successful startup remains running'
# Real activation branch consumes the adapter's structured verified failure.
function Assert-PLOSRequestIdentity {param($Value,$Name)}
function Get-PLOSTargetSpec {param($Manifest,$Id);$script:spec}
function Initialize-PLOSStateRoot {param($Manifest)}
function Read-PLOSState {param($Manifest);$script:state}
function Get-PLOSRequestRecord {param($State,$Id);@($script:records|Where-Object{$_.id -ceq $Id})}
function Get-PLOSSwitchesInWindow {param($State);@()}
function Get-PLOSTargetStatus {param($Id,$Spec);Get-PLOSRerankerTargetStatus $Spec}
function Assert-PLOSAdmissionResidency {param($Spec,$Status)}
function Get-PLOSResources {param($Manifest);[pscustomobject]@{pagefile_current_mib=0}}
function Test-PLOSModelLoaded {param($Manifest,$Status);$false}
function Get-PLOSAdmission {param($Manifest,$Id,$Spec,$Resources,$Loaded,$Baseline);[pscustomobject]@{eligible=$true;required_free_physical_bytes=0}}
function Get-PLOSStringSha256 {param($Value);'a'*64}
function ConvertTo-PLOSJson {param($Value);$Value|ConvertTo-Json -Depth 12 -Compress}
function Start-PLOSTarget {param($Id,$Spec);Start-PLOSRerankerTarget $Spec}
function Add-PLOSRequestRecord {param($State,$Id,$Signature,$Response);$script:records+=,[pscustomobject]@{id=$Id;signature=$Signature;response=$Response}}
function Write-PLOSState {param($Manifest,$State);$script:writes++}
$ExpectedHostId='fixture-host';$Execute=$true;$RequestId='request-fixture';$LeaseId='lease-fixture';$Target='fixture';$manifest=[pscustomobject]@{max_switches_per_hour=4}
Reset-Fixture;$r=Invoke-PLOSActivate $manifest
Check ($r.result -ceq 'activation_failed' -and $r.cleanup_verified -and -not $r.lease_present -and $script:writes -eq 1) 'verified failure persisted without lease'
$r=Invoke-PLOSActivate $manifest
Check ($r.result -ceq 'activation_failed' -and $script:starts -eq 1 -and $script:writes -eq 1) 'original failure never replays'
Reset-Fixture;$script:cleanupFails=$true;$caught=$null;try{Invoke-PLOSActivate $manifest}catch{$caught=$_}
Check ($null -ne $caught -and $script:records.Count -eq 0 -and $script:writes -eq 0) 'unknown cleanup has no terminal receipt'
@{passed=$true;checks=$script:checks;host_writes=0;model_calls=0}|ConvertTo-Json -Compress
