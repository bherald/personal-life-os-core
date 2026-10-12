# MIT License; Copyright (c) 2026 PLOS Contributors.
# Synthetic-only native contract checks. No host observation or launch calls.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
. (Join-Path $PSScriptRoot 'controller-contract.ps1')
. (Join-Path $PSScriptRoot 'native-launch-contract.ps1')

$spec = [pscustomobject]@{
    model = 'fictional-model'; engine = 'llama.cpp'; state = 'staged'
    activation_allowed = $false; port = 11438; task_name = 'Example-Chat'
}
$observed = @{ task = $null; listeners = @() }
$status = Get-PLOSRuntimeStatus -Id 'example_chat_a' -Spec $spec -Observation $observed
if ($status.task_present -or $status.listener_count -ne 0 -or $status.activation_allowed) {
    throw 'Absent synthetic runtime did not remain unavailable.'
}
$observed = @{task=[pscustomobject]@{State='Running'};listeners=@(
    [pscustomobject]@{LocalAddress='127.0.0.1'},[pscustomobject]@{LocalAddress='198.51.100.10'})}
$status = Get-PLOSRuntimeStatus -Id 'example_chat_a' -Spec $spec -Observation $observed
if ($status.loopback_only -or $status.external_listener_count -ne 1 -or $status.activation_allowed) {
    throw 'Synthetic external listener was incorrectly treated as loopback-only or admitted.'
}
if (-not (Test-PLOSFixedTimeBytes -Left ([byte[]]@(1,2,3)) -Right ([byte[]]@(1,2,3)))) {
    throw 'Byte comparison failed.'
}
if (Test-PLOSFixedTimeBytes -Left ([byte[]]@(1,2,3)) -Right ([byte[]]@(1,2,4))) {
    throw 'Different bytes accepted.'
}
$rejected=$false
try { Assert-PLOSExactProperties -Value ([pscustomobject]@{a=1;b=2}) -Allowed @('a') -Name 'Example' }
catch { $rejected=$true }
if (-not $rejected) { throw 'Unexpected schema property was accepted.' }
[ordered]@{schema='plos.offline_controller.contract_self_test.v1';fictional=$true;
    host_operations_performed=$false;process_started=$false;checks=5;result='pass'} | ConvertTo-Json -Compress
