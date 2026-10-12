# Caller imports outer controller function definitions. All host operations are mocked.
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$script:checks=0;$script:lifecycleCalls=0;$script:k3Busy=$false;$script:wrongTarget=$false
$spec=[pscustomobject]@{model='fixture-model';engine='fixture-engine';state='production';activation_allowed=$true}
function Get-PLOSTarget {param($Manifest,$Id)if($Id-cne'fixture'){throw 'Wrong target'};return $spec}
function Assert-PLOSK3LeaseFree {param($Manifest)if($script:k3Busy){throw 'K3 custody remains exclusive'}}
function Invoke-PLOSLifecycle {param($Manifest,$LifecycleAction,$LifecycleTarget)
 if($LifecycleAction-cne'Status'-or$LifecycleTarget-cne'fixture'){throw 'Broad or mutating lifecycle probe'}
 $script:lifecycleCalls++
 return [pscustomobject]@{targets=@([pscustomobject]@{target=$(if($script:wrongTarget){'foreign'}else{'fixture'});task_exact=$true});resources=[pscustomobject]@{gate_passed=$true}}
}
function Get-PLOSStatus {throw 'Full status must not be called'}
function Get-ScheduledTask {throw 'Additional task lookup must not be called'}
function Get-NetTCPConnection {throw 'Additional listener lookup must not be called'}
function Assert-Test($value,$message){if(-not$value){throw $message};$script:checks++}
$m=[pscustomobject]@{revision='fixture-revision'}
$r=Get-PLOSTargetStatusRead $m 'fixture'
Assert-Test ($script:lifecycleCalls-eq1) 'One exact target observation.'
Assert-Test ($r.target_descriptor.target-ceq'fixture'-and$r.target_descriptor.model-ceq$spec.model-and$r.target_descriptor.engine-ceq$spec.engine) 'Descriptor preserves exact manifest identity.'
Assert-Test ($r.target_descriptor.policy_state-ceq'production'-and$r.target_descriptor.activation_allowed-eq$true) 'Descriptor preserves admission policy.'
Assert-Test ($r.mutation_available-eq$false-and$r.k3_lease_active-eq$false-and$r.contains_prompt_or_response_content-eq$false) 'Read-only ownership guarantees remain explicit.'
$script:k3Busy=$true;$blocked=$false;try{$null=Get-PLOSTargetStatusRead $m 'fixture'}catch{$blocked=$true}
Assert-Test ($blocked-and$script:lifecycleCalls-eq1) 'Foreign K3 custody refuses before selected observation.'
$script:k3Busy=$false;$script:wrongTarget=$true;$blocked=$false;try{$null=Get-PLOSTargetStatusRead $m 'fixture'}catch{$blocked=$true}
Assert-Test $blocked 'Foreign runtime identity refuses descriptor response.'
@{passed=$true;checks=$script:checks;host_writes=0;model_calls=0}|ConvertTo-Json -Compress
