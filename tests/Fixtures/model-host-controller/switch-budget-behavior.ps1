# Model-free behavior checks. Caller imports lifecycle function definitions only.
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$script:checks=0;$script:starts=0;$script:ready=$false;$script:failStart=$false
function Assert-Test($Condition,[string]$Message){if(-not$Condition){throw $Message};$script:checks++}
function Initialize-PLOSStateRoot {param($Manifest)return 'mock'}
function Read-PLOSState {param($Manifest)return $script:state}
function Write-PLOSState {param($Manifest,$State)$script:state=$State}
function Get-PLOSTargetSpec {param($Manifest,$Id)return $script:spec}
function Get-PLOSTargetStatus {param($Id,$Spec)return [pscustomobject]@{running_exact=$script:ready;task_exact=$true;control_exact=$true;executable_exact=$true;model_loaded_exact=$script:ready;owned_process_count=0;loopback_listener_count=0}}
function Assert-PLOSAdmissionResidency {param($Spec,$Status)}
function Get-PLOSResources {param($Manifest)return [pscustomobject]@{pagefile_current_mib=0}}
function Get-PLOSAdmission {param($Manifest,$Id,$Spec,$Resources,$Loaded,$Baseline)return [pscustomobject]@{eligible=$true;required_free_physical_bytes=0;request_binding=$null}}
function Test-PLOSModelLoaded {param($Manifest,$Status)return $Status.model_loaded_exact}
function Start-PLOSTarget {param($Id,$Spec)if($script:failStart){throw 'mock startup failure'};$script:starts++;$script:ready=$true}
function Stop-PLOSTarget {param($Id,$Spec)$script:ready=$false}
$script:spec=[pscustomobject]@{switch_budget_exempt=$true;adapter_type='scheduled_llama';ready_minimum_free_physical_bytes=0}
$script:state=[pscustomobject]@{lease=$null;last_target=$null;switches=@();requests=@()}
$manifest=[pscustomobject]@{max_switches_per_hour=4;lease_ttl_seconds=300;resource_guards=[pscustomobject]@{}}
$maximumJournalEntries=256;$Execute=$true;$Target='fixture';$LeaseId='lease-fixture-001';$RequestId='request-fixture-001'
$legacy=@([DateTimeOffset]::UtcNow.AddMinutes(-30).ToString('o'),[DateTimeOffset]::UtcNow.AddMinutes(-20).ToString('o'))
$script:state.switches=@($legacy)
$a=Invoke-PLOSActivate $manifest
Assert-Test ($a.started_by_controller -and $script:starts-eq1) 'Exempt actual activation executes normally.'
Assert-Test (($script:state.switches -join '|')-ceq($legacy -join '|')) 'Exempt activation preserves all legacy timestamps without appending.'
$again=Invoke-PLOSActivate $manifest
Assert-Test ($script:starts-eq1 -and $again.request_id-ceq$a.request_id -and $script:state.switches.Count-eq2) 'Exact receipt replay does not consume another switch.'
$script:state.lease=$null;$script:spec.switch_budget_exempt=$false;$RequestId='request-fixture-002';$LeaseId='lease-fixture-002'
$a=Invoke-PLOSActivate $manifest
Assert-Test ($a.already_ready -and $script:state.switches.Count-eq3) 'Already-ready non-exempt lease retains existing accounting.'
$script:state.switches=@($legacy)
$script:state.lease=$null;$script:ready=$false;$RequestId='request-fixture-003';$LeaseId='lease-fixture-003'
$a=Invoke-PLOSActivate $manifest
Assert-Test ($a.started_by_controller -and $script:state.switches.Count-eq3) 'Actual non-exempt activation consumes exactly one switch.'
Assert-Test ($script:state.switches[0]-ceq$legacy[0] -and $script:state.switches[1]-ceq$legacy[1]) 'New accounting never rewrites legacy timestamps.'
$script:state.lease=$null;$script:ready=$false;$RequestId='request-fixture-004';$LeaseId='lease-fixture-004'
$null=Invoke-PLOSActivate $manifest
Assert-Test ($script:state.switches.Count-eq4) 'Fourth non-exempt activation remains allowed.'
$script:state.lease=$null;$script:ready=$false;$RequestId='request-fixture-005';$LeaseId='lease-fixture-005';$priorStarts=$script:starts;$rejected=$false
try{$null=Invoke-PLOSActivate $manifest}catch{$rejected=$_.Exception.Message-ceq'Lifecycle switch-rate budget is exhausted.'}
Assert-Test ($rejected -and $script:starts-eq$priorStarts -and $script:state.switches.Count-eq4) 'Full budget refuses non-exempt activation before runtime mutation.'
$script:spec.switch_budget_exempt=$true;$RequestId='request-fixture-006';$LeaseId='lease-fixture-006'
$null=Invoke-PLOSActivate $manifest
Assert-Test ($script:starts-eq($priorStarts+1) -and $script:state.switches.Count-eq4) 'Exempt activation bypasses full budget without increasing it.'
$RequestId='request-fixture-007';$LeaseId='lease-fixture-007';$priorStarts=$script:starts;$rejected=$false
try{$null=Invoke-PLOSActivate $manifest}catch{$rejected=$_.Exception.Message-ceq'An unexpired lifecycle lease is already active.'}
Assert-Test ($rejected -and $script:starts-eq$priorStarts) 'Exemption preserves serial lease exclusion.'
$script:state.lease=$null;$script:state.switches=@([DateTimeOffset]::UtcNow.AddHours(-2).ToString('o'))+$legacy;$script:ready=$false;$RequestId='request-fixture-008';$LeaseId='lease-fixture-008'
$null=Invoke-PLOSActivate $manifest
Assert-Test (($script:state.switches -join '|')-ceq($legacy -join '|')) 'Only normal one-hour expiry removes old legacy timestamps.'
$script:state.lease=$null;$script:spec.switch_budget_exempt=$false;$script:ready=$false;$script:failStart=$true;$RequestId='request-fixture-009';$LeaseId='lease-fixture-009';$rejected=$false
try{$null=Invoke-PLOSActivate $manifest}catch{$rejected=$true}
Assert-Test ($rejected -and $script:state.switches.Count-eq2) 'Failed uncompleted activation does not create a successful switch entry.'
# Malformed values are never exemptions, even if manifest validation is bypassed in this fixture.
$script:failStart=$false
foreach($invalid in @('true','false',1)) {
    $script:spec.switch_budget_exempt=$invalid;$script:state.lease=$null;$script:state.switches=@($legacy);$script:ready=$false
    $RequestId='request-type-'+([guid]::NewGuid().ToString());$LeaseId='lease-type-'+([guid]::NewGuid().ToString())
    $null=Invoke-PLOSActivate $manifest
    Assert-Test ($script:state.switches.Count-eq3) 'Malformed exemption value consumes one switch.'
    $script:state.lease=$null;$script:state.switches=@($legacy)+@($legacy);$script:ready=$false
    $RequestId='request-full-'+([guid]::NewGuid().ToString());$LeaseId='lease-full-'+([guid]::NewGuid().ToString());$priorStarts=$script:starts;$rejected=$false
    try{$null=Invoke-PLOSActivate $manifest}catch{$rejected=$_.Exception.Message-ceq'Lifecycle switch-rate budget is exhausted.'}
    Assert-Test ($rejected -and $script:starts-eq$priorStarts) 'Malformed exemption cannot bypass full budget.'
}
$script:spec.PSObject.Properties.Remove('switch_budget_exempt');$script:state.lease=$null;$script:state.switches=@($legacy);$script:ready=$false
$RequestId='request-missing';$LeaseId='lease-missing';$priorStarts=$script:starts;$rejected=$false
try{$null=Invoke-PLOSActivate $manifest}catch{$rejected=$true}
Assert-Test ($rejected -and $script:starts-eq$priorStarts -and $script:state.switches.Count-eq2) 'Missing required exemption property fails closed before activation.'
@{passed=$true;checks=$script:checks;host_state_changes=0;model_calls=0}|ConvertTo-Json -Compress
