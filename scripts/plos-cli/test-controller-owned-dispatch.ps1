#requires -Version 5.1
param([Parameter(Mandatory)][string]$LifecyclePath,[string]$ControllerPath = '')
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
# Model-free: caller imports candidate AST functions; every host mutation is mocked.
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$script:checks=0
function Check($Condition,$Message){if(-not $Condition){throw $Message};$script:checks++}
function Refused([scriptblock]$Body){$caught=$false;try{& $Body|Out-Null}catch{$caught=$true};Check $caught 'Expected custody refusal';Check ($script:writes -eq 0) 'Refusal must not renew host custody'}
function Copy-Json($Value){$Value|ConvertTo-Json -Depth 16|ConvertFrom-Json}
$script:binding=[pscustomobject]@{host_id='fixture-host';target='fixture-ollama';profile='qualified';policy_revision='fixture';model_identity=('digest:'+('a'*64));runtime_identity=('sha256:'+('b'*64));settings=[pscustomobject]@{context_tokens=4096;parallel_requests=1;mtp_enabled=$false};request_contracts=[pscustomobject]@{'cli.vision'= ('c'*64)}}
$script:spec=[pscustomobject]@{adapter_type='ollama_coex';ready_minimum_free_physical_bytes=8}
$script:manifest=[pscustomobject]@{resource_policy=[pscustomobject]@{};lease_ttl_seconds=7200;resource_guards=[pscustomobject]@{}}
$script:Execute=$true;$script:LeaseId='lease-fixture-123';$script:RequestId='heartbeat-fixture-123';$script:DispatchBindingBase64=''
$declaration=[pscustomobject]@{schema='plos.model_host_controller.dispatch.v1';phase='in_flight';original_request_id='original-fixture-123';lease_id=$LeaseId;target='fixture-ollama';capacity_binding=$binding}
function Reset-Fixture {
 $script:writes=0;$script:gpu=500;$script:seenPhases=@();$script:DispatchBindingBase64='';$script:RequestId='heartbeat-fixture-123'
 $script:status=[pscustomobject]@{task_exact=$true;control_exact=$true;executable_exact=$true;running_exact=$true;healthy=$true;model_exact=$true;model_loaded_exact=$false;residency_verified=$true;foreign_loaded_count=0;owned_process_count=1;loopback_listener_count=1;listener_owner_exact=$true;api_metadata_timed_out=$false;model_identity_observed=$true;residency_observed=$true}
 $activation=[pscustomobject]@{schema='plos.model_host_controller.activation.v1';result='already_ready';target='fixture-ollama';lease_id=$LeaseId;resource_assessment=[pscustomobject]@{request_binding=$script:binding}}
 $preflight=[pscustomobject]@{schema='plos.model_host_controller.heartbeat.v1';result='renewed';target='fixture-ollama';lease_id=$LeaseId;resource_assessment=[pscustomobject]@{request_binding=$script:binding}}
 $script:state=[pscustomobject]@{lease=[pscustomobject]@{lease_id=$LeaseId;target='fixture-ollama';model_residency_owned=$true;capacity_binding=$script:binding;pagefile_baseline_mib=0;expires_at='before'};requests=@([pscustomobject]@{request_id='original-fixture-123-activate';signature=('activate|fixture-ollama|'+$LeaseId);response=$activation},[pscustomobject]@{request_id='predispatch-fixture-123';signature=('heartbeat||'+$LeaseId);response=$preflight})}
}
function Initialize-PLOSStateRoot {param($Manifest)}
function Read-PLOSState {param($Manifest);return $script:state}
function Get-PLOSTargetSpec {param($Manifest,$Id);return $script:spec}
function Test-PLOSLeaseExpired {param($Lease);return $false}
function Get-PLOSTargetStatus {param($Id,$Spec,$AllowBusyGateway);return $script:status}
function Get-PLOSResources {param($Manifest);return [ordered]@{pagefile_current_mib=0}}
function Get-PLOSAdmission {
 param($Manifest,$Id,$Spec,$Resources,$Loaded,$Baseline)
 $script:seenPhases+=@($Loaded)
 $minimum=if($Loaded){100}else{600}
 return [pscustomobject]@{table_owned=$true;eligible=($script:gpu -ge $minimum);result='capacity_available';request_binding=$script:binding;phase=$(if($Loaded){'loaded'}else{'cold'});requirements=[ordered]@{gpu_free_bytes=[pscustomobject]@{passed=($script:gpu -ge $minimum)}}}
}
function Add-PLOSRequestRecord {param($State,$Id,$Signature,$Response);$State.requests+=@([pscustomobject]@{request_id=$Id;signature=$Signature;response=$Response})}
function Write-PLOSState {param($Manifest,$State);$script:writes++}
function Encode-Declaration($Value){$script:DispatchBindingBase64=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(($Value|ConvertTo-Json -Depth 16 -Compress)))}
Reset-Fixture
Refused {Invoke-PLOSHeartbeat $manifest}
Check ($script:seenPhases.Count -eq 1 -and -not $script:seenPhases[0]) 'Undeclared heartbeat retains cold requirement'
Reset-Fixture;Encode-Declaration $declaration
$x=Invoke-PLOSHeartbeat $manifest
Check ($x.result -ceq 'renewed' -and $x.resource_assessment.phase -ceq 'loaded') 'Proven owned loading uses existing loaded reserve'
Check (-not $script:status.model_loaded_exact) 'Actual residency remains unloaded'
Check ($script:writes -eq 1) 'Successful renewal writes exactly once'
$x=Invoke-PLOSHeartbeat $manifest
Check ($script:writes -eq 1) 'Exact retry reads receipt without another renewal'
$changed=Copy-Json $declaration;$changed.phase='cold';Encode-Declaration $changed;$script:writes=0
Refused {Invoke-PLOSHeartbeat $manifest}
foreach($field in @('phase','original_request_id','lease_id','target','schema')){
 Reset-Fixture;$bad=Copy-Json $declaration;$bad.$field='foreign-12345678';Encode-Declaration $bad
 Refused {Invoke-PLOSHeartbeat $manifest}
}
Reset-Fixture;$bad=Copy-Json $declaration;$bad|Add-Member override $true;Encode-Declaration $bad
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;$bad=Copy-Json $declaration;$bad.capacity_binding.runtime_identity='sha256:'+('d'*64);Encode-Declaration $bad
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;$state.requests=@($state.requests[0]);Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;$state.lease.model_residency_owned=$false;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
foreach($field in @('task_exact','control_exact','executable_exact','running_exact','healthy','residency_verified')){
 Reset-Fixture;$status.$field=$false;Encode-Declaration $declaration
 Refused {Invoke-PLOSHeartbeat $manifest}
}
Reset-Fixture;$status.foreign_loaded_count=1;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;$status.owned_process_count=2;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
foreach($field in @('model_loaded_exact','residency_verified','owned_process_count','loopback_listener_count','foreign_loaded_count')){
 Reset-Fixture;$status.$field='unknown';Encode-Declaration $declaration
 Refused {Invoke-PLOSHeartbeat $manifest}
}
Reset-Fixture;$state.lease.model_residency_owned=1;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;$state.requests[1].signature+='|dispatch:unproven';Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;$script:gpu=99;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Check ($script:seenPhases.Count -eq 2 -and $script:seenPhases[1]) 'Loaded minimum remains enforced'
# A timeout is not an affirmative health/residency answer. Only exact owned
# in-flight work with its admitted activation/pre-dispatch heartbeat may continue.
function Set-MetadataTimeout {
 $script:status.api_metadata_timed_out=$true;$script:status.healthy=$false;$script:status.running_exact=$false
 $script:status.model_identity_observed=$false;$script:status.model_exact=$false
}
Reset-Fixture;Set-MetadataTimeout;Encode-Declaration $declaration
$x=Invoke-PLOSHeartbeat $manifest
Check ($x.result -ceq 'renewed' -and $script:writes -eq 1) 'Owned tags timeout can renew with independently clear residency'
Check (-not $status.healthy -and -not $status.running_exact -and -not $status.model_exact) 'Timeout never fabricates health or model identity'
Reset-Fixture;Set-MetadataTimeout;$status.residency_observed=$false;$status.residency_verified=$false;$status.foreign_loaded_count=$null;Encode-Declaration $declaration
$x=Invoke-PLOSHeartbeat $manifest
Check ($x.result -ceq 'renewed' -and $x.resource_assessment.phase -ceq 'loaded') 'Owned metadata timeout preserves the loading allocation'
Check (-not $status.residency_verified -and $null -eq $status.foreign_loaded_count) 'Unknown residency stays unknown'
Reset-Fixture;Set-MetadataTimeout
Refused {Invoke-PLOSHeartbeat $manifest}
foreach($field in @('task_exact','control_exact','executable_exact','listener_owner_exact')) {
 Reset-Fixture;Set-MetadataTimeout;$status.$field=$false;Encode-Declaration $declaration
 Refused {Invoke-PLOSHeartbeat $manifest}
}
Reset-Fixture;Set-MetadataTimeout;$status.model_identity_observed=$true;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;Set-MetadataTimeout;$status.foreign_loaded_count=1;$status.residency_verified=$false;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;Set-MetadataTimeout;$state.requests=@($state.requests[0]);Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;Set-MetadataTimeout;$state.lease.model_residency_owned=$false;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;Set-MetadataTimeout;$status.api_metadata_timed_out=$false;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
Reset-Fixture;Set-MetadataTimeout;$script:gpu=99;Encode-Declaration $declaration
Refused {Invoke-PLOSHeartbeat $manifest}
# The separate HTTP controller is optional and is not part of the public export.
$releaseRouteChecked=$false
if (-not [string]::IsNullOrWhiteSpace($ControllerPath)) {
 $ControllerSource=Get-Content -LiteralPath $ControllerPath -Raw
# Execute the actual HTTP release branch, with transport and lifecycle mocked.
$tokens=$null;$errors=$null
$controllerAst=[Management.Automation.Language.Parser]::ParseInput($ControllerSource,[ref]$tokens,[ref]$errors)
$releaseBranch=@($controllerAst.FindAll({param($n)
 $n -is [Management.Automation.Language.IfStatementAst] -and
 $n.Clauses[0].Item1.Extent.Text -ceq '$method -eq ''POST'' -and $path -eq ''/v1/release'''
},$true))
Check ($releaseBranch.Count -eq 1) 'Exact HTTP release branch must be found'
$script:routeCalls=0
function Assert-PLOSK3LeaseFree {param($Manifest);$script:routeCalls++}
function Invoke-PLOSLifecycle {param($Manifest,$LifecycleAction,$LifecycleRequestId,$LifecycleLeaseId,[switch]$LifecycleExecute);$script:routeCalls++;return @{result='released'}}
function Write-PLOSResponse {param($Context,$StatusCode,$Payload);$script:routeCalls++}
$route=[scriptblock]::Create($releaseBranch[0].Clauses[0].Item2.Extent.Text.TrimStart('{').TrimEnd('}'))
$body=[pscustomobject]@{request_id='release-fixture-123';lease_id=$LeaseId;execute=$true;dispatch=$declaration}
$context=$null;$Manifest=$manifest;$script:writes=0
Refused {foreach($once in @(1)){& $route}}
Check ($script:routeCalls -eq 0) 'Release dispatch rejected before host or lifecycle calls'
$body.PSObject.Properties.Remove('dispatch')
foreach($once in @(1)){& $route}
Check ($script:routeCalls -eq 3) 'Original release contract remains accepted'
 $releaseRouteChecked=$true
}
[ordered]@{read_only=$true;model_requests=0;host_writes=0;checks=$script:checks;release_route_checked=$releaseRouteChecked}|ConvertTo-Json -Compress
