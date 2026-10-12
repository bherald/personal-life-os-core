# Model-free behavior harness. The caller imports reviewed source function ASTs only.
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$script:checks=0
function Assert-Test($Condition,$Message) { if(-not $Condition){throw $Message};$script:checks++ }
function Assert-Reject([scriptblock]$Operation) { $rejected=$false;try{& $Operation}catch{$rejected=$true};Assert-Test $rejected 'Expected rejection.' }
function Clone-Spec($Value){return ($Value|ConvertTo-Json -Depth 20|ConvertFrom-Json)}
$hash='a'*64
$spec=[pscustomobject]@{adapter_type='ollama_coex';task_name='shared-daemon';task_user='SYSTEM';task_arguments='serve';control_path='C:\engine\control.ps1';control_sha256=$hash;executable_path='C:\engine\ollama.exe';executable_sha256=$hash;model_path='ollama://alpha';model_identity=('digest:'+$hash);model_api_id='alpha';port=11434;health_path='/api/tags';resource_mode='runtime_managed';startup_timeout_seconds=180;switch_budget_exempt=$true;control_roots=[pscustomobject]@{EngineRoot='C:\engine'};required_process_arguments=[pscustomobject]@{}}
$other=Clone-Spec $spec;$other.model_api_id='beta';$other.model_identity='digest:'+('b'*64);$other.model_path='ollama://beta'
Assert-PLOSSharedTargetPair $spec $other;Assert-Test $true 'Exact Ollama sharing.'
$bad=Clone-Spec $other;$bad.control_sha256='c'*64;Assert-Reject {Assert-PLOSSharedTargetPair $spec $bad}
$bad=Clone-Spec $other;$bad.port=11435;Assert-Reject {Assert-PLOSSharedTargetPair $spec $bad}
$bad=Clone-Spec $other;$bad.adapter_type='scheduled_llama';Assert-Reject {Assert-PLOSSharedTargetPair $spec $bad}
$bad=Clone-Spec $other;$bad.model_api_id='alpha';Assert-Reject {Assert-PLOSSharedTargetPair $spec $bad}
$image=Clone-Spec $spec;$image.adapter_type='authenticated_image';$image.task_name='image-alpha';$image.port=7867
$image|Add-Member interpreter_path 'C:\Python\python.exe';$image|Add-Member interpreter_sha256 $hash
$image|Add-Member gateway_path 'C:\engine\gateway.py';$image|Add-Member gateway_sha256 $hash;$image|Add-Member api_key_path 'C:\engine\key';$image|Add-Member runtime_receipt_path 'C:\engine\alpha.json'
$image2=Clone-Spec $image;$image2.task_name='image-beta';$image2.model_api_id='beta';$image2.runtime_receipt_path='C:\engine\beta.json'
Assert-PLOSSharedTargetPair $image $image2;Assert-Test $true 'Exact image sharing.'
$bad=Clone-Spec $image2;$bad.gateway_sha256='b'*64;Assert-Reject {Assert-PLOSSharedTargetPair $image $bad}
$bad=Clone-Spec $image2;$bad.task_name='image-alpha';Assert-Reject {Assert-PLOSSharedTargetPair $image $bad}
$host1=[pscustomobject]@{engine='ollama';task_name='same';port=11434;model='alpha';health_path='/api/tags';resource_group='serial';runtime_revision='one';engine_identity=$hash;process_names=@('ollama.exe')}
$host2=Clone-Spec $host1;$host2.model='beta';Assert-PLOSSharedHostTargetPair $host1 $host2;Assert-Test $true 'Outer sharing.'
$bad=Clone-Spec $host2;$bad.engine='other';Assert-Reject {Assert-PLOSSharedHostTargetPair $host1 $bad}
$script:ollamaStatusSnapshots=$null;$script:getCalls=0;$script:postCalls=0;$script:loaded=@();$script:failPs=$false
function Get-ScheduledTask { param($TaskName,$ErrorAction) return $null }
function Get-NetTCPConnection {param($State,$LocalPort,$ErrorAction) return @() }
function Get-PLOSOwnedProcesses {param($Id,$Spec) return @() }
function Get-PLOSSha256 {param($Path) return ('a'*64) }
function Invoke-RestMethod {
 param($Uri,$TimeoutSec,$Method,$ContentType,$Body)
 if($Method -eq 'Post'){
  $script:postCalls++;$request=$Body|ConvertFrom-Json
  Assert-Test ($request.model -ceq 'alpha' -and $request.keep_alive -eq 0 -and $request.prompt -ceq '') 'Unload must target only selected model.'
  $script:loaded=@();return [pscustomobject]@{done=$true}
 }
 $script:getCalls++
 if($Uri.EndsWith('/api/tags')){return [pscustomobject]@{models=@([pscustomobject]@{name='alpha';digest=('a'*64)},[pscustomobject]@{name='beta';digest=('b'*64)})}}
 if($script:failPs){throw 'Unavailable metadata'}
 return [pscustomobject]@{models=@($script:loaded)}
}
$script:ollamaStatusSnapshots=@{}
$a=Get-PLOSHealthStatus -Id alpha -Spec $spec;$b=Get-PLOSHealthStatus -Id beta -Spec $other
Assert-Test ($a.residency_verified -and $b.residency_verified -and $script:getCalls -eq 2) 'One shared read snapshot per status invocation.'
$script:ollamaStatusSnapshots=$null
$script:loaded=@([pscustomobject]@{name='beta';digest=('b'*64);size=100;size_vram=100})
$a=Get-PLOSHealthStatus -Id alpha -Spec $spec
Assert-Test (-not $a.residency_verified -and $a.foreign_loaded_count -eq 1) 'Foreign resident must not be eligible.'
$borrowed=[pscustomobject]@{target='alpha';model_loaded_by_controller=$false;model_residency_owned=$false}
$owned=[pscustomobject]@{target='alpha';model_loaded_by_controller=$false;model_residency_owned=$true}
Assert-Test (-not (Clear-PLOSOwnedOllamaResidency $spec $borrowed)) 'Borrowed model residency must not be unloaded.'
Assert-Reject {Clear-PLOSOwnedOllamaResidency $spec $owned}
Assert-Test ($script:postCalls -eq 0) 'Foreign model must not receive unload.'
$script:loaded=@([pscustomobject]@{name='alpha';digest=('a'*64);size=100;size_vram=100})
Assert-Test (Clear-PLOSOwnedOllamaResidency $spec $owned) 'Owned exact selected model unload must complete.'
Assert-Test ($script:postCalls -eq 1 -and $script:loaded.Count -eq 0) 'Only one selected-model unload.'
Assert-Test (-not (Clear-PLOSOwnedOllamaResidency $spec $owned)) 'Already empty residency needs no repeated mutation.'
$script:failPs=$true
Assert-Reject {Clear-PLOSOwnedOllamaResidency $spec $owned}
Assert-Test ($script:postCalls -eq 1) 'Unknown status must never unload.'
$occupied=[pscustomobject]@{owned_process_count=1;loopback_listener_count=1;running_exact=$false;residency_verified=$false}
Assert-Reject {Assert-PLOSAdmissionResidency $spec $occupied}
$busyImage=[pscustomobject]@{loopback_listener_count=1;running_exact=$true;busy=$true}
Assert-Reject {Assert-PLOSAdmissionResidency $image $busyImage}
# Exercise real activation/release orchestration against an already running borrowed daemon.
$script:failPs=$false;$script:loaded=@();$script:leaseState=[pscustomobject]@{lease=$null;last_target=$null;switches=@();requests=@()};$script:starts=0;$script:stops=0
function Initialize-PLOSStateRoot {param($Manifest) return 'mock' }
function Read-PLOSState {param($Manifest) return $script:leaseState }
function Write-PLOSState {param($Manifest,$State) $script:leaseState=$State }
function Get-PLOSRequestRecord {param($State,$Id) return @() }
function Add-PLOSRequestRecord {param($State,$Id,$Signature,$Response) }
function Get-PLOSSwitchesInWindow {param($State) return @() }
function Get-PLOSTargetSpec {param($Manifest,$Id) return $spec }
function Get-PLOSTargetStatus {param($Id,$Spec) return [pscustomobject]@{running_exact=$true;task_exact=$true;control_exact=$true;executable_exact=$true;model_loaded_exact=($script:loaded.Count -eq 1);residency_verified=$true;owned_process_count=1;loopback_listener_count=1} }
function Get-PLOSResources {param($Manifest) return [pscustomobject]@{pagefile_current_mib=0} }
function Get-PLOSAdmission {param($Manifest,$Id,$Spec,$Resources,$Loaded,$Baseline) return [pscustomobject]@{eligible=$true;required_free_physical_bytes=0;request_binding=$null} }
function Test-PLOSModelLoaded {param($Manifest,$Status) return $Status.model_loaded_exact }
function Test-PLOSLeaseExpired {param($Lease) return $false }
function Start-PLOSTarget {param($Id,$Spec) $script:starts++ }
function Stop-PLOSTarget {param($Id,$Spec) $script:stops++ }
$spec|Add-Member ready_minimum_free_physical_bytes 0
$Execute=$true;$Target='alpha';$RequestId='activation-check-123';$LeaseId='lease-check-123'
$manifest=[pscustomobject]@{max_switches_per_hour=4;lease_ttl_seconds=300;resource_guards=[pscustomobject]@{}}
$activation=Invoke-PLOSActivate -Manifest $manifest
Assert-Test ($script:starts -eq 0 -and -not $activation.started_by_controller) 'Borrowed exact daemon must not be started twice.'
Assert-Test ($script:leaseState.lease.model_residency_owned -and -not $activation.model_loaded_by_controller) 'Lease reserves future selected-model residency without claiming a preload.'
$script:loaded=@([pscustomobject]@{name='alpha';digest=('a'*64);size=100;size_vram=100});$RequestId='release-check-123'
$release=Invoke-PLOSRelease -Manifest $manifest
Assert-Test ($release.cleanup_verified -and $release.unloaded_controller_owned_model -and $null -eq $script:leaseState.lease) 'Release proves owned selected-model cleanup and frees lease.'
Assert-Test ($script:stops -eq 0 -and $script:starts -eq 0) 'Release preserves borrowed daemon.'
@{passed=$true;checks=$script:checks;model_calls=0;host_state_changes=0}|ConvertTo-Json -Compress
