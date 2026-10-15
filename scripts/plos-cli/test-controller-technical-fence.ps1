#requires -Version 5.1
param([Parameter(Mandatory)][string]$LifecyclePath)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($LifecyclePath,[ref]$tokens,[ref]$errors)
if($errors.Count){throw ($errors|Out-String)}
foreach($name in @('Assert-PLOSTechnicalFenceK3Clear','ConvertTo-PLOSJson','Invoke-PLOSTechnicalFence','Invoke-PLOSActivate','Read-PLOSState','Write-PLOSState','New-PLOSDefaultState','Get-PLOSSha256','Get-PLOSReceipt','Get-PLOSRequestRecord','Add-PLOSRequestRecord','Assert-PLOSRequestIdentity','Assert-PLOSExactProperties')){
 $nodes=@($ast.FindAll({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name},$false));if($nodes.Count -ne 1){throw "Missing helper $name"};. ([scriptblock]::Create($nodes[0].Extent.Text))
}
$actualK3Check = ${function:Assert-PLOSTechnicalFenceK3Clear}
$root=Join-Path ([IO.Path]::GetTempPath()) ('plos-fence-test-'+[guid]::NewGuid());$null=New-Item -ItemType Directory -Path $root
$manifest=[pscustomobject]@{state_root=$root;host_id='fixture-host'};$stateFileName='state.json';$maximumJournalEntries=64;$Execute=$true
function Assert-PLOSStateAcl {param($Path,$Directory)}
function Set-PLOSProtectedAcl {param($Path,$Directory)}
function Initialize-PLOSStateRoot {param($Manifest);$Manifest.state_root}
function Get-PLOSTargetSpec {param($Manifest,$Id);if($Id -cne 'fixture'){throw 'Foreign target'};[pscustomobject]@{adapter_type=$script:adapterType}}
$script:observed=$null;$script:race=$false;$script:k3Clear=$true
function Assert-PLOSTechnicalFenceK3Clear {if(-not $script:k3Clear){throw 'Foreign K3 custody'}}
function Get-PLOSTargetStatus {param($Id,$Spec);if($script:race){Add-Content (Join-Path $root $stateFileName) ' ';};$script:observed}
function fixture {
 $script:adapterType='ollama_coex'
 $script:observed=[ordered]@{task_exact=$true;control_exact=$true;executable_exact=$true;task_enabled=$true;task_state='Running';owned_process_count=1;loopback_listener_count=1;running_exact=$true;healthy=$true;model_loaded_exact=$false;residency_verified=$true;foreign_loaded_count=0;busy=$false}
 $script:race=$false;$script:k3Clear=$true;$state=New-PLOSDefaultState;Write-PLOSState $manifest $state
 $script:binding=[ordered]@{owner='11111111-1111-4111-8111-111111111111';original_request_id='original-12345678';activation_request_id='original-12345678-activate';lease_id='lease-12345678';target='fixture';request_id='fence-12345678';custody_digest=('a'*64);expected_host_generation=(Get-PLOSSha256 (Join-Path $root $stateFileName));operator_authority_sha256=('b'*64)}
 $script:FenceBindingBase64=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(($script:binding|ConvertTo-Json -Compress)))
}
$passed=0
try {
 # Real protected K3 custody reader, with only ACL checks mocked on Linux.
 $k3Root=Join-Path $root 'k3';$null=New-Item -ItemType Directory -Path $k3Root
 $ManifestPath=Join-Path $root 'lifecycle.json';$ExpectedHostId='fixture-host';$TechnicalFenceK3ManifestPath=Join-Path $root 'fixture-k3.json'
 @{host_id=$ExpectedHostId;state_root=$k3Root}|ConvertTo-Json|Set-Content $TechnicalFenceK3ManifestPath
 & $actualK3Check;$passed++
 foreach($k3Fault in @('lease','unknown')) {
  @{schema='plos.model_host_controller.mhc08_k3.v1';revision='MHC-08-k3-explicit-v1';phase='active';lease=$(if($k3Fault -eq 'lease'){@{lease_id='foreign'}}else{$null})}|ConvertTo-Json|Set-Content (Join-Path $k3Root 'state.json')
  $failed=$false;try{& $actualK3Check}catch{$failed=$true};if(-not $failed){throw 'Foreign or ambiguous K3 custody passed'};$passed++
 }
 fixture;$legacy=New-PLOSDefaultState;$legacy.Remove('generation');$legacy.Remove('technical_fences');$legacy|ConvertTo-Json|Set-Content (Join-Path $root $stateFileName)
 $before=Get-PLOSSha256 (Join-Path $root $stateFileName);$read=Read-PLOSState $manifest
 if($read.generation -ne 0 -or $read.technical_fences.Count -ne 0 -or (Get-PLOSSha256 (Join-Path $root $stateFileName)) -cne $before){throw 'Legacy read mutated state'};$passed++
 foreach($fault in @('k3_lease','lease','model_loaded','foreign_model','residency_unknown','busy','busy_unknown','identity','generation_race','changed_generation')){
  fixture
  switch($fault){
   'k3_lease'{$script:k3Clear=$false}
   'lease'{$s=Read-PLOSState $manifest;$s.lease=[pscustomobject]@{lease_id='foreign'};Write-PLOSState $manifest $s}
   'model_loaded'{$script:observed.model_loaded_exact=$true}
   'foreign_model'{$script:observed.foreign_loaded_count=1}
   'residency_unknown'{$script:observed.residency_verified=$false}
   'busy'{$script:observed.busy=$true}
   'busy_unknown'{$script:observed.busy=$null}
   'identity'{$script:observed.control_exact=$false}
   'generation_race'{$script:race=$true}
   'changed_generation'{Add-Content (Join-Path $root $stateFileName) ' '}
  }
  $before=(Get-Content (Join-Path $root $stateFileName) -Raw);$failed=$false
  try{$null=Invoke-PLOSTechnicalFence $manifest}catch{$failed=$true}
  if(-not $failed){throw "Unsafe fence passed: $fault"}
  if((Read-PLOSState $manifest).technical_fences.Count -ne 0){throw 'Held request persisted a fence'};$passed++
 }
 # A stopped authenticated reranker has no health endpoint to report busy.
 foreach($fault in @('none','busy','foreign_process','listener','identity','runtime_pin','enabled')) {
  fixture;$script:adapterType='authenticated_reranker'
  $script:observed.task_enabled=$false;$script:observed.task_state='Disabled'
  $script:observed.owned_process_count=0;$script:observed.loopback_listener_count=0
  $script:observed.running_exact=$false;$script:observed.busy=$null
  $script:observed['runtime_files_exact']=$true
  switch($fault) {
   'busy'{$script:observed.busy=$true}
   'foreign_process'{$script:observed.owned_process_count=1}
   'listener'{$script:observed.loopback_listener_count=1}
   'identity'{$script:observed.control_exact=$false}
   'runtime_pin'{$script:observed.runtime_files_exact=$false}
   'enabled'{$script:observed.task_enabled=$true}
  }
  $failed=$false;try{$r=Invoke-PLOSTechnicalFence $manifest}catch{$failed=$true}
  if(($fault -ceq 'none') -eq $failed){throw ('Stopped reranker fence failed boundary '+$fault)}
  if($fault -ceq 'none') {
   if($r.runtime_mutated -or $r.inference_outcome -cne 'not_dispatched'){throw 'Stopped fence invented runtime work'}
  } elseif((Read-PLOSState $manifest).technical_fences.Count -ne 0){throw 'Rejected reranker fence persisted'}
  $passed++
 }
 fixture;$receipt=Invoke-PLOSTechnicalFence $manifest
 if($receipt.activation_outcome -cne 'unknown' -or $receipt.inference_outcome -cne 'not_dispatched' -or $receipt.runtime_mutated -or $receipt.model_replayed){throw 'Fence invented a runtime outcome'};$passed++
 $read=Get-PLOSReceipt $manifest technical_fence 'fence-12345678' 'lease-12345678' fixture
 if(-not $read.record_found -or $read.mutation_available){throw 'Durable fence readback failed'};$passed++
 # Replay recovery returns the existing truthful receipt, without another state write.
 $before=Get-PLOSSha256 (Join-Path $root $stateFileName);$again=Invoke-PLOSTechnicalFence $manifest
 if((Get-PLOSSha256 (Join-Path $root $stateFileName)) -cne $before){throw 'Fence replay rewrote state'};$passed++
 # Prune normal receipts, then reread from disk to model process restart.
 $state=Read-PLOSState $manifest
 for($i=0;$i -lt 80;$i++){Add-PLOSRequestRecord $state ('other-'+$i) 'fixture' ([pscustomobject]@{result='fixture'})}
 Write-PLOSState $manifest $state;$state=Read-PLOSState $manifest
 if($state.requests.Count -ne 64 -or $state.technical_fences.Count -ne 1){throw 'Pruning lost the activation fence'};$passed++
 $RequestId='original-12345678-activate';$LeaseId='lease-12345678';$Target='fixture';$failed=$false
 try{$null=Invoke-PLOSActivate $manifest}catch{if($_.Exception.Message -like '*technically fenced*'){$failed=$true}else{throw}}
 if(-not $failed){throw 'Original activation was replayed'};$passed++
 # Fresh independently approved generation may settle only the identical old custody.
 $script:binding.request_id='fresh-fence-12345678';$script:binding.expected_host_generation=Get-PLOSSha256 (Join-Path $root $stateFileName);$script:binding.operator_authority_sha256='d'*64
 foreach($fault in @('owner','digest','lease','activity')) {
  $changed=[ordered]@{};foreach($key in $script:binding.Keys){$changed[$key]=$script:binding[$key]}
  if($fault -eq 'owner'){$changed.owner='22222222-2222-4222-8222-222222222222'}
  if($fault -eq 'digest'){$changed.custody_digest='e'*64}
  if($fault -eq 'lease'){$script:k3Clear=$false}
  if($fault -eq 'activity'){$script:observed.model_loaded_exact=$true}
  $FenceBindingBase64=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(($changed|ConvertTo-Json -Compress)))
  $failed=$false;try{$null=Invoke-PLOSTechnicalFence $manifest}catch{$failed=$true};if(-not $failed){throw 'Unsafe fresh-generation fence passed'};$passed++
  $script:k3Clear=$true;$script:observed.model_loaded_exact=$false
 }
 $FenceBindingBase64=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(($script:binding|ConvertTo-Json -Compress)))
 $fresh=Invoke-PLOSTechnicalFence $manifest
 if((Read-PLOSState $manifest).technical_fences.Count -ne 2 -or $fresh.activation_outcome -cne 'unknown'){throw 'Fresh-generation receipt lost prior custody'};$passed++
 $script:binding.custody_digest='c'*64;$FenceBindingBase64=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes(($script:binding|ConvertTo-Json -Compress)));$failed=$false
 try{$null=Invoke-PLOSTechnicalFence $manifest}catch{$failed=$true};if(-not $failed){throw 'Conflicting fence replay passed'};$passed++
 [ordered]@{passed=$true;cases=$passed;model_requests=0;live_controller_mutations=0}|ConvertTo-Json -Compress
}finally{Remove-Item -LiteralPath $root -Recurse -Force}
