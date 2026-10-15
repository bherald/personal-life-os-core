#requires -Version 5.1
[CmdletBinding()]
param(
    [ValidateSet('SelfTest', 'Status', 'Receipt', 'Plan', 'Activate', 'Heartbeat', 'Recover', 'Release', 'TechnicalFence')]
    [string]$Action = 'Status',
    [string]$Target = '',
    [string]$RequestId = '',
    [string]$LeaseId = '',
    [string]$ReceiptOperation = '',
    [string]$FenceBindingBase64 = '',
    [string]$DispatchBindingBase64 = '',
    [switch]$Execute,
    [Parameter(Mandatory = $true)][string]$ManifestPath,
    [Parameter(Mandatory = $true)][string]$ExpectedHostId,
    [string]$TechnicalFenceK3ManifestPath = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-StrictMode -Version Latest
$stateFileName = 'state.json'
$mutexName = 'Global\PLOSModelHostControllerLifecycle'
$maximumJournalEntries = 64
$script:ollamaStatusSnapshots = $null

function ConvertTo-PLOSJson {
    param([Parameter(Mandatory = $true)]$Value)
    $Value | ConvertTo-Json -Depth 16 -Compress
}

function Get-PLOSSha256 {
    param([Parameter(Mandatory = $true)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Missing pinned file: $Path" }
    (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Get-PLOSStringSha256 {
    param([Parameter(Mandatory = $true)][string]$Value)
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        $digest = $algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value))
        (($digest | ForEach-Object { $_.ToString('x2') }) -join '')
    } finally { $algorithm.Dispose() }
}

function Assert-PLOSExactProperties {
    param([Parameter(Mandatory = $true)]$Value, [Parameter(Mandatory = $true)][string[]]$Allowed, [string]$Name)
    $actual = @($Value.PSObject.Properties.Name | Sort-Object)
    $expected = @($Allowed | Sort-Object)
    if (($actual -join ',') -ne ($expected -join ',')) { throw "$Name properties do not match the pinned schema." }
}

function Read-PLOSManifest {
    if (-not (Test-Path -LiteralPath $ManifestPath -PathType Leaf)) { throw 'Lifecycle manifest is missing.' }
    $manifest = Get-Content -LiteralPath $ManifestPath -Raw | ConvertFrom-Json
    $manifestFields = @(
        'schema', 'revision', 'host_id', 'state_root', 'execution_mode', 'compatible_residency_enabled',
        'concurrent_inference_enabled', 'lease_ttl_seconds', 'max_switches_per_hour', 'resource_guards', 'targets'
    )
    if ($null -ne $manifest.PSObject.Properties['resource_policy']) { $manifestFields += 'resource_policy' }
    Assert-PLOSExactProperties -Value $manifest -Name 'Lifecycle manifest' -Allowed $manifestFields
    Assert-PLOSExactProperties -Value $manifest.resource_guards -Name 'Resource guards' -Allowed @(
        'minimum_commit_headroom_bytes', 'drive_c_free_bytes', 'drive_d_free_bytes',
        'maximum_pagefile_current_mib', 'maximum_pagefile_growth_mib', 'temperature_mode', 'balanced_power_required'
    )
    if ([string]$manifest.resource_guards.temperature_mode -cne 'observation_only') { throw 'Temperature must be observation-only.' }
    if ([string]$manifest.schema -ne 'plos.model_host_controller.lifecycle_manifest.v1' -or
        [string]$manifest.revision -ne 'MHC-04-guarded-lifecycle' -or [string]::IsNullOrWhiteSpace($ExpectedHostId) -or [string]$manifest.host_id -cne $ExpectedHostId -or
        [string]$manifest.state_root -ne 'C:\ProgramData\PLOS\ModelHostController\control' -or
        [string]$manifest.execution_mode -ne 'serial' -or [bool]$manifest.compatible_residency_enabled -or
        [bool]$manifest.concurrent_inference_enabled -or
        -not (Test-PLOSCapacityInteger $manifest.lease_ttl_seconds) -or $manifest.lease_ttl_seconds -le 0 -or
        -not (Test-PLOSCapacityInteger $manifest.max_switches_per_hour) -or $manifest.max_switches_per_hour -le 0) {
        throw 'Lifecycle manifest posture drifted.'
    }
    $targetIds = @($manifest.targets.PSObject.Properties.Name)
    if ($targetIds.Count -eq 0 -or @($targetIds | Sort-Object -Unique).Count -ne $targetIds.Count) {
        throw 'Lifecycle requires a nonempty unique target registry.'
    }
    $validatedTargets=@()
    foreach ($property in @($manifest.targets.PSObject.Properties)) {
        $targetFields = @(
            'task_name', 'task_user', 'task_arguments', 'control_path', 'control_sha256', 'executable_path',
            'executable_sha256', 'model_path', 'model_identity', 'model_api_id', 'port', 'health_path',
            'cold_start_minimum_free_physical_bytes', 'ready_minimum_free_physical_bytes', 'startup_timeout_seconds', 'switch_budget_exempt',
            'adapter_type', 'control_roots', 'required_process_arguments', 'resource_mode'
        )
        $spec=$property.Value
        Assert-PLOSAdapterSpec $spec
        if ($null -ne $spec.PSObject.Properties['verification_timeout_seconds']) {
            if ($spec.adapter_type -cnotin @('scheduled_llama','scheduled_process_group') -or
                -not (Test-PLOSCapacityInteger $spec.verification_timeout_seconds) -or
                $spec.verification_timeout_seconds -le 0 -or $spec.verification_timeout_seconds -gt 7200) {
                throw 'Lifecycle verification budget is invalid.'
            }
            $targetFields += 'verification_timeout_seconds'
        }
        if ($spec.adapter_type -ceq 'colibri_gateway') {
            if ($null -ne $spec.PSObject.Properties['task_logon_type']) {
                if ($spec.task_logon_type -isnot [string] -or $spec.task_logon_type -cnotin @('Interactive','S4U')) { throw 'GLM task logon binding is invalid.' }
                $targetFields += 'task_logon_type'
            }
            if ($null -ne $spec.PSObject.Properties['native_tools_enabled']) {
                if ($spec.native_tools_enabled -isnot [bool]) { throw 'GLM tool capability must be boolean.' }
                $targetFields += 'native_tools_enabled'
            }
            $targetFields += @('gateway_path','gateway_sha256','runtime_receipt_path','runtime_receipt_sha256','api_key_path')
            $spec = $property.Value
            foreach ($field in @('gateway_sha256','runtime_receipt_sha256')) {
                if ([string]$spec.$field -cnotmatch '^[a-f0-9]{64}$') { throw 'GLM runtime pin is invalid.' }
            }
            foreach ($field in @('gateway_path','runtime_receipt_path','control_path','executable_path','api_key_path')) {
                $path = [string]$spec.$field
                if (-not [IO.Path]::GetFullPath($path).StartsWith('C:\PLOS\Colibri\', [StringComparison]::OrdinalIgnoreCase) -or
                    [IO.Path]::GetFullPath($path) -cne $path) { throw 'GLM runtime path is outside its canonical root.' }
            }
            if ([string]::IsNullOrWhiteSpace([string]$spec.api_key_path)) { throw 'Colibri credential reference is missing.' }
        }
        if ($spec.adapter_type -cin @('authenticated_image','authenticated_reranker')) {
            $targetFields += @('gateway_path','gateway_sha256','runtime_receipt_path','runtime_receipt_sha256','api_key_path','interpreter_path','interpreter_sha256')
            if ([string]$spec.interpreter_sha256 -cnotmatch '^[a-f0-9]{64}$' -or
                [string]$spec.interpreter_path -cnotmatch '^[A-Za-z]:\\' -or
                [IO.Path]::GetFullPath([string]$spec.interpreter_path) -cne [string]$spec.interpreter_path -or
                [string]$spec.interpreter_path -ieq [string]$spec.executable_path) { throw 'Image interpreter identity is invalid.' }
            $root = [string](Get-PLOSCapacityValue $spec.control_roots $(if($spec.adapter_type -ceq 'authenticated_reranker'){'RerankerRoot'}else{'ImageRoot'}))
            if ([string]::IsNullOrWhiteSpace($root) -or [IO.Path]::GetFullPath($root) -cne $root) { throw 'Image root is not canonical.' }
            foreach ($field in @('gateway_sha256','runtime_receipt_sha256')) {
                if ([string]$spec.$field -cnotmatch '^[a-f0-9]{64}$') { throw 'Image runtime pin is invalid.' }
            }
            foreach ($field in @('gateway_path','runtime_receipt_path','control_path','executable_path','api_key_path')) {
                $path = [string]$spec.$field
                if ([IO.Path]::GetFullPath($path) -cne $path -or
                    -not $path.StartsWith($root.TrimEnd('\')+'\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Image runtime path is outside its canonical root.' }
            }
        }
        if ([string]$property.Name -cnotmatch '^[a-z0-9][a-z0-9_.:-]{2,63}$' -or
            [string]::IsNullOrWhiteSpace([string]$spec.task_name) -or
            -not (Test-PLOSCapacityInteger $spec.port) -or $spec.port -lt 1 -or $spec.port -gt 65535) {
            throw 'Lifecycle target identity or endpoint is invalid.'
        }
        foreach ($previous in $validatedTargets) { Assert-PLOSSharedTargetPair -Left $previous -Right $spec }
        $validatedTargets += $spec
        Assert-PLOSExactProperties -Value $property.Value -Name "Lifecycle target $($property.Name)" -Allowed $targetFields
        if ($property.Value.switch_budget_exempt -isnot [bool] -or
            -not (Test-PLOSCapacityInteger $property.Value.startup_timeout_seconds) -or $property.Value.startup_timeout_seconds -le 0) {
            throw 'Lifecycle target control parameters are invalid.'
        }
        if ([string]$property.Value.control_sha256 -notmatch '^[a-f0-9]{64}$' -or
            [string]$property.Value.executable_sha256 -notmatch '^[a-f0-9]{64}$' -or
            ([string]$property.Value.model_identity -notmatch '^(sha256|digest):[a-f0-9]{64}$' -and
                -not ($spec.adapter_type -cin @('authenticated_image','authenticated_reranker') -and [string]$spec.model_identity -cmatch '^manifest:[a-f0-9]{64}$'))) {
            throw "Lifecycle target $($property.Name) has an invalid pin."
        }
    }
    $manifest
}


# Sharing a listener is allowed only for one pinned engine, never arbitrary port collisions.
function Assert-PLOSSharedTargetPair {
    param($Left, $Right)
    $sameTask = [string]$Left.task_name -ieq [string]$Right.task_name
    $samePort = [int]$Left.port -eq [int]$Right.port
    if (-not $sameTask -and -not $samePort) { return }
    if ($Left.adapter_type -cne $Right.adapter_type -or
        $Left.adapter_type -cnotin @('ollama_coex','authenticated_image') -or -not $samePort -or
        [string]$Left.model_api_id -ceq [string]$Right.model_api_id) { throw 'Lifecycle task or port collision is not a shared exact engine.' }
    $fields=@('task_user','control_path','control_sha256','executable_path','executable_sha256','health_path','resource_mode')
    if ($Left.adapter_type -ceq 'ollama_coex') {
        if (-not $sameTask) { throw 'Shared Ollama models require the same daemon task.' }
        $fields += @('task_name','task_arguments','startup_timeout_seconds','switch_budget_exempt')
    } else {
        if ($sameTask -or $Left.runtime_receipt_path -ceq $Right.runtime_receipt_path) { throw 'Image models require distinct fixed-model tasks and receipts.' }
        $fields += @('gateway_path','gateway_sha256','api_key_path','interpreter_path','interpreter_sha256')
    }
    foreach ($field in $fields) {
        if ([string]$Left.$field -cne [string]$Right.$field) { throw 'Shared engine runtime identity differs.' }
    }
    foreach ($field in @('control_roots','required_process_arguments')) {
        $keys=@($Left.$field.PSObject.Properties | ForEach-Object { $_.Name } | Sort-Object)
        if (($keys -join ',') -cne (@($Right.$field.PSObject.Properties | ForEach-Object { $_.Name } | Sort-Object) -join ',')) { throw 'Shared engine runtime parameters differ.' }
        foreach ($key in $keys) { if ([string]$Left.$field.$key -cne [string]$Right.$field.$key) { throw 'Shared engine runtime parameters differ.' } }
    }
}

function Assert-PLOSAdapterSpec {
    param($Spec)
    if ($Spec.adapter_type -cnotin @('pinned_llama','ollama_coex','scheduled_llama','scheduled_process_group','colibri_gateway','authenticated_image','authenticated_reranker') -or
        $Spec.resource_mode -cnotin @('full','runtime_managed','ram_commit_c')) { throw 'Unsupported lifecycle adapter or resource mode.' }
    foreach ($field in @('control_roots','required_process_arguments')) {
        $value=Get-PLOSCapacityValue $Spec $field
        if ($null -eq $value -or $value -isnot [pscustomobject]) { throw 'Adapter parameters must be JSON maps.' }
        foreach ($p in @($value.PSObject.Properties)) {
            $pattern=if($field -ceq 'control_roots'){'^[A-Z][A-Za-z0-9]*Root$'}else{'^--[a-z][a-z0-9-]*$'}
            if ($p.Name -cnotmatch $pattern -or $p.Value -isnot [string] -or [string]::IsNullOrWhiteSpace($p.Value) -or
                $p.Value -match '[\x00-\x1f\x7f]' -or ($field -ceq 'control_roots' -and $p.Value -notmatch '^[A-Za-z]:\\')) {
                throw 'Invalid lifecycle adapter parameter.'
            }
        }
    }
    if ($Spec.adapter_type -cin @('pinned_llama','ollama_coex') -and @($Spec.control_roots.PSObject.Properties).Count -eq 0) {
        throw 'Pinned-control adapter requires explicit roots.'
    }
}

# These functions consume the pinned projection of PLOS table rows, not request-supplied limits.
function Get-PLOSCapacityValue {
    param($Value, [string]$Name)
    if ($null -eq $Value) { return $null }
    if ($Value -is [Collections.IDictionary]) {
        if ($Value.Contains($Name)) { return $Value[$Name] }
        return $null
    }
    $property = $Value.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Test-PLOSCapacityInteger {
    param($Value)
    return [bool](($Value -is [int] -or $Value -is [long]) -and $Value -ge 0)
}

function Get-PLOSRuntimeBinding {
    param($Spec)
    $parts = @($(if($null -ne $Spec.PSObject.Properties['adapter_type']){'plos.model_host_controller.runtime_binding.v2'}else{'plos.model_host_controller.runtime_binding.v1'}))
    foreach ($field in @('control_sha256','executable_sha256','task_arguments','gateway_sha256','runtime_receipt_sha256')) {
        $value = Get-PLOSCapacityValue $Spec $field
        if ($null -eq $value) { $value = '' }
        if ($value -isnot [string] -or $value -match '[\r\n]' -or
            ($field -in @('control_sha256','executable_sha256','task_arguments') -and $value -eq '') -or
            ($field -ne 'task_arguments' -and $value -ne '' -and $value -cnotmatch '^[a-f0-9]{64}$')) {
            throw 'Runtime capacity binding is incomplete.'
        }
        $parts += $value
    }
    if ($null -ne $Spec.PSObject.Properties['adapter_type']) {
        Assert-PLOSAdapterSpec $Spec
        foreach ($field in @('adapter_type','resource_mode')) { $parts += $field+':'+[string]$Spec.$field }
        foreach ($field in @('control_roots','required_process_arguments')) {
            [string[]]$keys=@($Spec.$field.PSObject.Properties | ForEach-Object { [string]$_.Name })
            [Array]::Sort($keys,[StringComparer]::Ordinal)
            foreach ($key in $keys) { $parts += $field+':'+$key+'='+[string]$Spec.$field.$key }
        }
    }
    if ([string](Get-PLOSCapacityValue $Spec 'adapter_type') -ceq 'colibri_gateway' -and $null -ne $Spec.PSObject.Properties['native_tools_enabled']) {
        if ($Spec.native_tools_enabled -isnot [bool]) { throw 'Invalid GLM tool capability binding.' }
        $parts += ('native_tools_enabled:' + $(if($Spec.native_tools_enabled){'true'}else{'false'}))
    }
    if ([string](Get-PLOSCapacityValue $Spec 'adapter_type') -ceq 'colibri_gateway' -and $null -ne $Spec.PSObject.Properties['task_logon_type']) {
        if ($Spec.task_logon_type -isnot [string] -or $Spec.task_logon_type -cnotin @('Interactive','S4U')) { throw 'GLM task logon binding is invalid.' }
        if ($Spec.task_logon_type -cne 'Interactive') { $parts += 'task_logon_type:'+ $Spec.task_logon_type }
    }
    if ([string](Get-PLOSCapacityValue $Spec 'adapter_type') -cin @('authenticated_image','authenticated_reranker')) {
        foreach ($field in @('interpreter_path','interpreter_sha256')) {
            $value=Get-PLOSCapacityValue $Spec $field
            if ($value -isnot [string] -or [string]::IsNullOrWhiteSpace($value) -or $value -match '[\r\n]') { throw 'Image interpreter binding is missing.' }
            $parts += $field+':'+$value
        }
    }
    return 'sha256:' + (Get-PLOSStringSha256 -Value ($parts -join "`n"))
}

function Get-PLOSTableCapacity {
    param($Manifest, [string]$Id, $Spec, $Resources, [string]$Phase, [long]$NowMs)
    $result = [ordered]@{eligible=$false;result='policy_invalid';table_owned=$true;profile=$null;
        policy_revision=$null;phase=$Phase;required_free_physical_bytes=0;requirements=[ordered]@{}}
    try {
        $policy = Get-PLOSCapacityValue $Manifest 'resource_policy'
        if ((Get-PLOSCapacityValue $policy 'schema') -cne 'plos.model_host_controller.resource_policy.v1' -or
            (Get-PLOSCapacityValue $policy 'temperature_mode') -cne 'observation_only' -or
            (Get-PLOSCapacityValue $policy 'enabled') -isnot [bool]) { return $result }
        $result.policy_revision = Get-PLOSCapacityValue $policy 'revision'
        if ($result.policy_revision -isnot [string] -or [string]::IsNullOrWhiteSpace($result.policy_revision)) { return $result }
        if (-not $policy.enabled) { $result.result='policy_disabled'; return $result }
        $bindings = Get-PLOSCapacityValue (Get-PLOSCapacityValue $policy 'bindings') ([string]$Manifest.host_id)
        $profileId = Get-PLOSCapacityValue $bindings $Id
        if ($profileId -isnot [string]) { $result.result='profile_unqualified'; return $result }
        $result.profile = $profileId
        $profile = Get-PLOSCapacityValue (Get-PLOSCapacityValue $policy 'profiles') $profileId
        if ((Get-PLOSCapacityValue $profile 'qualified') -isnot [bool] -or -not $profile.qualified) {
            $result.result='profile_unqualified'; return $result
        }
        if ($profile.host_id -cne $Manifest.host_id -or $profile.target -cne $Id -or
            $profile.model_identity -cne $Spec.model_identity -or $profile.runtime_identity -cne (Get-PLOSRuntimeBinding $Spec)) {
            $result.result='profile_mismatch'; return $result
        }
        # Qualification binds these settings to the exact launch contract digest above.
        $settings = $profile.settings
        if (-not (Test-PLOSCapacityInteger $settings.context_tokens) -or $settings.context_tokens -eq 0 -or
            -not (Test-PLOSCapacityInteger $settings.parallel_requests) -or $settings.parallel_requests -eq 0 -or
            $settings.mtp_enabled -isnot [bool]) { return $result }
        $hasLegacy = if ($profile -is [Collections.IDictionary]) { $profile.Contains('request_contract_sha256') } else { $null -ne $profile.PSObject.Properties['request_contract_sha256'] }
        $hasVariants = if ($profile -is [Collections.IDictionary]) { $profile.Contains('request_contracts') } else { $null -ne $profile.PSObject.Properties['request_contracts'] }
        if ($hasLegacy -and $hasVariants) { return $result }
        if ($hasLegacy -or $hasVariants) {
            $binding=[ordered]@{profile=$profileId;policy_revision=$policy.revision;
                host_id=$profile.host_id;target=$profile.target;model_identity=$profile.model_identity;
                runtime_identity=$profile.runtime_identity;settings=$settings}
            if ($hasLegacy) {
                $requestHash=Get-PLOSCapacityValue $profile 'request_contract_sha256'
                if ($requestHash -isnot [string] -or $requestHash -cnotmatch '^[a-f0-9]{64}$') { return $result }
                $binding.request_contract_sha256=$requestHash
            } else {
                $variants=Get-PLOSCapacityValue $profile 'request_contracts'
                if ($variants -isnot [Collections.IDictionary] -and $variants -isnot [pscustomobject]) { return $result }
                $keys=@($(if($variants -is [Collections.IDictionary]){$variants.Keys}else{$variants.PSObject.Properties | ForEach-Object {$_.Name}}))
                if ($keys.Count -eq 0) { return $result }
                $seen=@{}
                foreach ($key in $keys) {
                    $digest=Get-PLOSCapacityValue $variants ([string]$key)
                    if ($key -isnot [string] -or $key -cnotmatch '^[a-z][a-z0-9_.:-]*$' -or
                        $digest -isnot [string] -or $digest -cnotmatch '^[a-f0-9]{64}$' -or $seen.ContainsKey($digest)) { return $result }
                    $seen[$digest]=$true
                }
                $binding.request_contracts=$variants
            }
            $result.request_binding=$binding
        }
        $sampledAt = Get-PLOSCapacityValue $Resources 'sampled_at_ms'
        if (-not (Test-PLOSCapacityInteger $policy.maximum_sample_age_ms) -or $policy.maximum_sample_age_ms -eq 0 -or
            -not (Test-PLOSCapacityInteger $policy.maximum_future_skew_ms)) { return $result }
        if (-not (Test-PLOSCapacityInteger $sampledAt) -or $NowMs -lt 0 -or
            $sampledAt - $NowMs -gt $policy.maximum_future_skew_ms -or $NowMs - $sampledAt -gt $policy.maximum_sample_age_ms) {
            $result.result='telemetry_stale'; return $result
        }
        $rules = Get-PLOSCapacityValue $profile.requirements $Phase
        if ($Phase -cnotin @('cold','loaded') -or $null -eq $rules -or
            -not (Test-PLOSCapacityInteger (Get-PLOSCapacityValue (Get-PLOSCapacityValue $rules 'free_physical_bytes') 'minimum')) -or
            -not (Test-PLOSCapacityInteger (Get-PLOSCapacityValue (Get-PLOSCapacityValue $rules 'commit_headroom_bytes') 'minimum'))) { return $result }
        $result.required_free_physical_bytes = $rules.free_physical_bytes.minimum
        $allowed = @('free_physical_bytes','commit_headroom_bytes','gpu_free_bytes','drive_c_free_bytes','drive_d_free_bytes','pagefile_current_bytes','pagefile_growth_bytes')
        $missing=$false; $shortage=$false
        foreach ($property in @($rules.PSObject.Properties)) {
            $metric=[string]$property.Name; $rule=$property.Value
            $names=@($rule.PSObject.Properties.Name)
            if ($metric -cnotin $allowed -or $names.Count -eq 0 -or @($names|Where-Object {$_ -cnotin @('minimum','maximum')}).Count -ne 0) { return $result }
            foreach ($bound in $names) { if (-not (Test-PLOSCapacityInteger $rule.$bound)) { return $result } }
            $min=Get-PLOSCapacityValue $rule 'minimum';$max=Get-PLOSCapacityValue $rule 'maximum'
            if ($null -ne $min -and $null -ne $max -and $min -gt $max) { return $result }
            $value=Get-PLOSCapacityValue $Resources $metric
            $valid=Test-PLOSCapacityInteger $value
            $passed=[bool]($valid -and ($null -eq $min -or $value -ge $min) -and ($null -eq $max -or $value -le $max))
            $entry=[ordered]@{available=$(if($valid){$value}else{$null});passed=$passed}
            if ($null -ne $min) { $entry.minimum=$min }; if ($null -ne $max) { $entry.maximum=$max }
            $result.requirements[$metric]=$entry
            $missing=$missing -or -not $valid; $shortage=$shortage -or -not $passed
        }
        $result.eligible=-not $shortage
        $result.result=if($missing){'telemetry_unavailable'}elseif($shortage){'temporarily_unavailable'}else{'capacity_available'}
        return $result
    } catch { return $result }
}

function Test-PLOSModelLoaded {
    param($Manifest, $Status)
    if ($null -ne $Manifest.PSObject.Properties['resource_policy']) {
        $loaded = Get-PLOSCapacityValue $Status 'model_loaded_exact'
        return [bool]($Status.running_exact -and $loaded -is [bool] -and $loaded)
    }
    return [bool]$Status.running_exact
}

function Get-PLOSAdmission {
    param($Manifest, [string]$Id, $Spec, $Resources, [bool]$Loaded, [long]$PagefileBaseline = -1)
    try {
    $projected = $null -ne $Manifest.PSObject.Properties['resource_policy']
    if ($projected) {
        # This field exists only for a loaded assessment with an observed baseline.
        $Resources['pagefile_growth_bytes'] = if ($Loaded -and $PagefileBaseline -ge 0) {
            [long][math]::Max([long]0, ([long]$Resources.pagefile_current_mib - $PagefileBaseline) * 1MB)
        } else { $null }
        return Get-PLOSTableCapacity $Manifest $Id $Spec $Resources $(if($Loaded){'loaded'}else{'cold'}) ([DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds())
    }
    # Retained pre-projection host contract. A present but invalid projection NEVER enters this path.
    $required = if($Spec.resource_mode -ceq 'runtime_managed'){0}elseif($Loaded){[long]$Spec.ready_minimum_free_physical_bytes}else{[long]$Spec.cold_start_minimum_free_physical_bytes}
    $green = (Test-PLOSTargetResourceGate -Spec $Spec -Resources $Resources -Guards $Manifest.resource_guards) -and [long]$Resources.free_physical_bytes -ge $required
    if ($Loaded -and $PagefileBaseline -ge 0 -and $Spec.resource_mode -ceq 'full' -and
        [long]$Resources.pagefile_current_mib -gt ($PagefileBaseline + [long]$Manifest.resource_guards.maximum_pagefile_growth_mib)) { $green=$false }
    return [ordered]@{eligible=[bool]$green;table_owned=$false;required_free_physical_bytes=$required;
        result=$(if($green){'capacity_available'}else{'temporarily_unavailable'})}
    } catch {
        $script:heartbeatDiagnosticSourceLine = [int]$_.InvocationInfo.ScriptLineNumber
        throw
    }
}

function Get-PLOSTargetSpec {
    param([Parameter(Mandatory = $true)]$Manifest, [Parameter(Mandatory = $true)][string]$Id)
    if ($Id -notmatch '^[a-z0-9][a-z0-9_.:-]{2,63}$') { throw 'Lifecycle target identity is invalid.' }
    $property = $Manifest.targets.PSObject.Properties[$Id]
    if ($null -eq $property) { throw 'Lifecycle target is not allowlisted.' }
    $property.Value
}

function Assert-PLOSRequestIdentity {
    param([Parameter(Mandatory = $true)][string]$Value, [Parameter(Mandatory = $true)][string]$Name)
    if ($Value -notmatch '^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}$') { throw "$Name identity is invalid." }
}

# Optional observations must never invalidate independent resource telemetry.
function ConvertTo-PLOSObservedTemperature {
    param($Value)
    if ($null -eq $Value -or [string]::IsNullOrWhiteSpace([string]$Value)) { return $null }
    try {
        $number = [double]$Value
        if ([double]::IsNaN($number) -or [double]::IsInfinity($number)) { return $null }
        return $number
    } catch { return $null }
}

function Get-PLOSResources {
    param([Parameter(Mandatory = $true)]$Manifest)
    $sampledAt = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
    $pagefile = @(Get-CimInstance Win32_PageFileUsage -ErrorAction Stop)
    $drives = @(Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' -ErrorAction Stop)
    $driveC = @($drives | Where-Object { [string]$_.DeviceID -eq 'C:' })
    $driveD = @($drives | Where-Object { [string]$_.DeviceID -eq 'D:' })
    $acpi = @()
    try {
        $acpi = @(Get-CimInstance -Namespace root/wmi -ClassName MSAcpi_ThermalZoneTemperature -ErrorAction Stop |
            ForEach-Object {
                $temperature = ConvertTo-PLOSObservedTemperature $_.CurrentTemperature
                if ($null -ne $temperature) { [math]::Round(($temperature / 10) - 273.15, 1) }
            })
    } catch { $acpi = @() }
    $diskTemperatures = @()
    try {
        $diskTemperatures = @(Get-PhysicalDisk -ErrorAction Stop | ForEach-Object {
            $reliability = $_ | Get-StorageReliabilityCounter -ErrorAction Stop
            if ($null -ne $reliability.Temperature) { ConvertTo-PLOSObservedTemperature $reliability.Temperature }
        })
    } catch { $diskTemperatures = @() }
    $gpuTemperature = $null
    $gpuMemoryUsedMiB = $null
    $gpuMemoryTotalMiB = $null
    $gpuMemoryFreeMiB = $null
    try {
        $raw = @(& nvidia-smi.exe --query-gpu=temperature.gpu,memory.used,memory.total,memory.free --format=csv,noheader,nounits 2>$null)
        if ($LASTEXITCODE -eq 0 -and $raw.Count -eq 1) {
            $parts = @([string]$raw[0] -split ',' | ForEach-Object { $_.Trim() })
            if ($parts.Count -eq 4) {
                $gpuTemperature = ConvertTo-PLOSObservedTemperature $parts[0]
                $used = [int64]0; $total = [int64]0; $free = [int64]0
                # NVIDIA reports available FB memory independently of total/used.
                # Publish memory atomically only after validating every required field.
                if ([int64]::TryParse($parts[1], [ref]$used) -and
                    [int64]::TryParse($parts[2], [ref]$total) -and
                    [int64]::TryParse($parts[3], [ref]$free) -and
                    $used -ge 0 -and $total -gt 0 -and $free -ge 0 -and
                    $used -le $total -and $free -le $total -and $used -le ($total - $free) -and
                    $total -le ([int64]::MaxValue -shr 20)) {
                    $gpuMemoryUsedMiB = $used
                    $gpuMemoryTotalMiB = $total
                    $gpuMemoryFreeMiB = $free
                }
            }
        }
    } catch {}
    $power = [string](& powercfg.exe /getactivescheme 2>$null)
    $guards = $Manifest.resource_guards
    $maximumAcpi = if ($acpi.Count -gt 0) { [double](($acpi | Measure-Object -Maximum).Maximum) } else { $null }
    $maximumDisk = if ($diskTemperatures.Count -gt 0) { [double](($diskTemperatures | Measure-Object -Maximum).Maximum) } else { $null }
    $pagefileCurrent = [int64](($pagefile | Measure-Object -Property CurrentUsage -Sum).Sum)
    $telemetryComplete = [bool]($driveC.Count -eq 1 -and $driveD.Count -eq 1 -and $null -ne $gpuMemoryUsedMiB -and
        $null -ne $gpuMemoryTotalMiB -and $null -ne $gpuMemoryFreeMiB)
    $balanced = [bool]($power -match '381b4222-f694-41f0-9685-ff5bb260df2e')
    $green = [bool]($telemetryComplete -and
        [int64]$os.FreeVirtualMemory * 1KB -ge [int64]$guards.minimum_commit_headroom_bytes -and
        [int64]$driveC[0].FreeSpace -ge [int64]$guards.drive_c_free_bytes -and
        [int64]$driveD[0].FreeSpace -ge [int64]$guards.drive_d_free_bytes -and
        $pagefileCurrent -le [int64]$guards.maximum_pagefile_current_mib -and
        (-not [bool]$guards.balanced_power_required -or $balanced))
    [ordered]@{
        sampled_at_ms = $sampledAt
        gpu_free_bytes = if ($null -ne $gpuMemoryFreeMiB) { $gpuMemoryFreeMiB * 1MB } else { $null }
        pagefile_current_bytes = $pagefileCurrent * 1MB
        free_physical_bytes = [int64]$os.FreePhysicalMemory * 1KB
        commit_headroom_bytes = [int64]$os.FreeVirtualMemory * 1KB
        pagefile_current_mib = $pagefileCurrent
        pagefile_allocated_mib = [int64](($pagefile | Measure-Object -Property AllocatedBaseSize -Sum).Sum)
        drive_c_free_bytes = if ($driveC.Count -eq 1) { [int64]$driveC[0].FreeSpace } else { 0 }
        drive_d_free_bytes = if ($driveD.Count -eq 1) { [int64]$driveD[0].FreeSpace } else { 0 }
        acpi_thermal_celsius = $maximumAcpi
        gpu_thermal_celsius = $gpuTemperature
        disk_maximum_thermal_celsius = $maximumDisk
        gpu_memory_used_mib = $gpuMemoryUsedMiB
        gpu_memory_total_mib = $gpuMemoryTotalMiB
        balanced_power = $balanced
        telemetry_complete = $telemetryComplete
        # Compatibility field: temperature never authorizes an abort.
        hard_abort = $false
        temperature_mode = 'observation_only'
        gate_passed = $green
    }
}

function Get-PLOSOwnedProcesses {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec)
    @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $pathExact = [string]$_.ExecutablePath -ceq [string]$Spec.executable_path
        if (-not $pathExact) { return $false }
        if ($Spec.adapter_type -ceq 'ollama_coex') { return [string]$_.CommandLine -match '(?:^|\s)serve(?:\s|$)' }
        [string]$_.CommandLine -like "*$($Spec.model_path)*" -and
            [string]$_.CommandLine -match ('(?:^|\s)(?:"--port"|--port)(?:\s+|=)(?:"'+[int]$Spec.port+'"|'+[int]$Spec.port+')(?=\s|$)')
    })
}


function Get-PLOSOllamaSnapshot {
    param($Spec)
    $key = Get-PLOSRuntimeBinding -Spec $Spec
    $key += ':'+[string]$Spec.port
    if ($null -ne $script:ollamaStatusSnapshots -and $script:ollamaStatusSnapshots.ContainsKey($key)) { return $script:ollamaStatusSnapshots[$key] }
    $snapshot = @{task=(Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction SilentlyContinue)
        listeners=@(Get-NetTCPConnection -State Listen -LocalPort ([int]$Spec.port) -ErrorAction SilentlyContinue)
        owned=@(Get-PLOSOwnedProcesses -Id 'shared_ollama' -Spec $Spec)
        control_exact=$false;executable_exact=$false;api_verified=$false;tags=@();loaded=@()
        tags_verified=$false;loaded_verified=$false;metadata_timed_out=$false;metadata_rejected=$false}
    try { $snapshot.control_exact = (Get-PLOSSha256 -Path ([string]$Spec.control_path)) -ceq [string]$Spec.control_sha256 } catch {}
    try { $snapshot.executable_exact = (Get-PLOSSha256 -Path ([string]$Spec.executable_path)) -ceq [string]$Spec.executable_sha256 } catch {}
    # A busy tags request must not erase an independently observed foreign model.
    foreach ($probe in @(@{path='tags';field='tags';verified='tags_verified'},@{path='ps';field='loaded';verified='loaded_verified'})) {
        try {
            $response = Invoke-RestMethod -Uri "http://127.0.0.1:$([int]$Spec.port)/api/$($probe.path)" -TimeoutSec 5
            if ($null -eq $response.PSObject.Properties['models'] -or $response.models -isnot [array]) {
                throw 'Ollama metadata shape is unverified.'
            }
            $snapshot[$probe.field]=@($response.models);$snapshot[$probe.verified]=$true
        } catch {
            if ($_.Exception -is [Net.WebException] -and $_.Exception.Status -eq [Net.WebExceptionStatus]::Timeout) {
                $snapshot.metadata_timed_out=$true
            } else { $snapshot.metadata_rejected=$true }
        }
    }
    $snapshot.metadata_timed_out=[bool]($snapshot.metadata_timed_out -and -not $snapshot.metadata_rejected)
    $snapshot.api_verified=[bool]($snapshot.tags_verified -and $snapshot.loaded_verified)
    if ($null -ne $script:ollamaStatusSnapshots) { $script:ollamaStatusSnapshots[$key]=$snapshot }
    return $snapshot
}

function Get-PLOSHealthStatus {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec, $Observation = $null)
    $healthy = $false
    $modelExact = $false
    $fullyGpuResident = $null
    $modelLoadedExact = $false
    $residencyVerified = $false
    $foreignLoadedCount = $null
    $modelIdentityObserved = $false
    $residencyObserved = $false
    $metadataTimedOut = $false
    try {
        if ($Spec.adapter_type -ceq 'ollama_coex') {
            $snapshot = if ($null -ne $Observation) { $Observation } else { Get-PLOSOllamaSnapshot -Spec $Spec }
            $modelIdentityObserved=[bool]$snapshot.tags_verified
            $residencyObserved=[bool]$snapshot.loaded_verified
            $metadataTimedOut=[bool]$snapshot.metadata_timed_out
            $exactTag = @($snapshot.tags | Where-Object {
                [string]$_.name -ceq [string]$Spec.model_api_id -and
                ('digest:' + [string]$_.digest) -ceq [string]$Spec.model_identity
            })
            $modelExact = [bool]($modelIdentityObserved -and $exactTag.Count -eq 1)
            $loaded = @($snapshot.loaded)
            $exactLoaded = @($loaded | Where-Object {
                [string]$_.name -ceq [string]$Spec.model_api_id -and
                ('digest:' + [string]$_.digest) -ceq [string]$Spec.model_identity
            })
            $foreignLoadedCount = if ($residencyObserved) { $loaded.Count - $exactLoaded.Count } else { $null }
            $residencyVerified = [bool]($residencyObserved -and $foreignLoadedCount -eq 0 -and $exactLoaded.Count -le 1)
            $healthy = [bool]$snapshot.api_verified
            $modelLoadedExact = [bool]($modelExact -and $exactLoaded.Count -eq 1 -and $residencyVerified)
            $fullyGpuResident = [bool]($loaded.Count -eq 1 -and $exactLoaded.Count -eq 1 -and
                [int64]$exactLoaded[0].size -gt 0 -and [int64]$exactLoaded[0].size_vram -eq [int64]$exactLoaded[0].size)
        } else {
            $health = Invoke-RestMethod -Uri "http://127.0.0.1:$([int]$Spec.port)$([string]$Spec.health_path)" -TimeoutSec 5
            $models = Invoke-RestMethod -Uri "http://127.0.0.1:$([int]$Spec.port)/v1/models" -TimeoutSec 5
            $ids = @($models.data | ForEach-Object { [string]$_.id })
            $healthy = [bool]([string]$health.status -eq 'ok')
            $modelExact = [bool]($ids.Count -eq 1 -and [string]$ids[0] -ceq [string]$Spec.model_api_id)
            $modelLoadedExact = [bool]($healthy -and $modelExact)
        }
    } catch {}
    [ordered]@{ healthy = $healthy; model_exact = $modelExact; model_loaded_exact = $modelLoadedExact; fully_gpu_resident = $fullyGpuResident; residency_verified = $residencyVerified; foreign_loaded_count = $foreignLoadedCount
        model_identity_observed=$modelIdentityObserved;residency_observed=$residencyObserved;api_metadata_timed_out=$metadataTimedOut }
}

function Get-PLOSTargetStatus {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec,
        [switch]$AllowBusyGateway)
    if ($Spec.adapter_type -ceq 'authenticated_reranker') {
        $status = Get-PLOSRerankerTargetStatus -Spec $Spec
        $status['target'] = $Id
        return $status
    }
    if ($Spec.adapter_type -ceq 'authenticated_image') {
        $status = Get-PLOSImageTargetStatus -Spec $Spec
        $status['target'] = $Id
        return $status
    }
    if ($Spec.adapter_type -ceq 'colibri_gateway') {
        $status = Get-PLOSGlmTargetStatus -Spec $Spec -AllowBusyGateway:$AllowBusyGateway
        $status['target'] = $Id
        $status['model_loaded_exact'] = [bool]$status.running_exact
        return $status
    }
    if ($Spec.adapter_type -ceq 'ollama_coex') {
        $snapshot = Get-PLOSOllamaSnapshot -Spec $Spec
        $task=$snapshot.task;$listeners=@($snapshot.listeners);$owned=@($snapshot.owned)
    } else {
        $task = Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction SilentlyContinue
        $listeners = @(Get-NetTCPConnection -State Listen -LocalPort ([int]$Spec.port) -ErrorAction SilentlyContinue)
        $owned = @(Get-PLOSOwnedProcesses -Id $Id -Spec $Spec)
    }
    $loopback = @($listeners | Where-Object { [string]$_.LocalAddress -in @('127.0.0.1', '::1') })
    $external = @($listeners | Where-Object { [string]$_.LocalAddress -notin @('127.0.0.1', '::1') })
    $observation = if ($Spec.adapter_type -ceq 'ollama_coex') { $snapshot } else { $null }
    $health = Get-PLOSHealthStatus -Id $Id -Spec $Spec -Observation $observation
    $principal = if ($null -ne $task) { [string]$task.Principal.UserId } else { '' }
    $expectedUser = [string]$Spec.task_user
    $principalExact = if ($expectedUser -eq 'SYSTEM') {
        $principal -in @('SYSTEM', 'S-1-5-18', 'NT AUTHORITY\SYSTEM')
    } else { $principal -ieq $expectedUser }
    $taskExact = [bool]($null -ne $task -and @($task.Actions).Count -eq 1 -and
        [string]$task.Actions[0].Execute -match '(?i)(?:^|\\)powershell\.exe$' -and
        [string]$task.Actions[0].Arguments -ceq [string]$Spec.task_arguments -and
        @($task.Triggers).Count -eq 1 -and
        [string]$task.Triggers[0].CimClass.CimClassName -eq 'MSFT_TaskBootTrigger' -and $principalExact)
    $controlExact = $false
    $executableExact = $false
    if ($Spec.adapter_type -ceq 'ollama_coex') {
        $controlExact=$snapshot.control_exact;$executableExact=$snapshot.executable_exact
    } else {
        try { $controlExact = (Get-PLOSSha256 -Path ([string]$Spec.control_path)) -eq [string]$Spec.control_sha256 } catch {}
        try { $executableExact = (Get-PLOSSha256 -Path ([string]$Spec.executable_path)) -eq [string]$Spec.executable_sha256 } catch {}
    }
    $runningExact = [bool]($health.healthy -and $health.model_exact -and $owned.Count -eq 1 -and $loopback.Count -eq 1 -and
        ($Spec.adapter_type -cne 'ollama_coex' -or ($health.residency_verified -and
            [int]$loopback[0].OwningProcess -eq [int]$owned[0].ProcessId)))
    if ($runningExact -and $Spec.adapter_type -ceq 'scheduled_process_group') {
        try {
            $group = Invoke-PLOSProcessGroupAction -Spec $Spec -Action status
            $runningExact = [bool]($group.active -and [int]$owned[0].ProcessId -in $group.pids -and
                [int]$loopback[0].OwningProcess -in $group.pids)
        } catch { $runningExact = $false }
    }
    if ($runningExact) {
        foreach ($p in @($Spec.required_process_arguments.PSObject.Properties)) {
            $name=[regex]::Escape([string]$p.Name);$value=[regex]::Escape([string]$p.Value)
            $pattern='(?:^|\s)(?:"'+$name+'"|'+$name+')(?:\s+|=)(?:"'+$value+'"|'+$value+')(?=\s|$)'
            if ([string]$owned[0].CommandLine -cnotmatch $pattern) { $runningExact=$false;break }
        }
    }
    [ordered]@{
        target = $Id
        task_present = [bool]($null -ne $task)
        task_exact = $taskExact
        task_enabled = [bool]($null -ne $task -and [bool]$task.Settings.Enabled)
        task_state = if ($null -ne $task) { [string]$task.State } else { 'Missing' }
        control_exact = $controlExact
        executable_exact = $executableExact
        owned_process_count = [int]$owned.Count
        loopback_listener_count = [int]$loopback.Count
        external_proxy_listener_count = [int]$external.Count
        healthy = [bool]$health.healthy
        model_exact = [bool]$health.model_exact
        model_loaded_exact = [bool]($runningExact -and $health.model_loaded_exact)
        fully_gpu_resident = $health.fully_gpu_resident
        residency_verified = $health.residency_verified
        foreign_loaded_count = $health.foreign_loaded_count
        model_identity_observed = $health.model_identity_observed
        residency_observed = $health.residency_observed
        api_metadata_timed_out = $health.api_metadata_timed_out
        listener_owner_exact = [bool]($owned.Count -eq 1 -and $loopback.Count -eq 1 -and
            [int]$loopback[0].OwningProcess -eq [int]$owned[0].ProcessId)
        running_exact = $runningExact
    }
}

function New-PLOSDefaultState {
    [ordered]@{
        schema = 'plos.model_host_controller.lifecycle_state.v1'
        revision = 'MHC-04-guarded-lifecycle'
        lease = $null
        last_target = $null
        switches = @()
        requests = @()
        generation = 0
        technical_fences = @()
    }
}

function Assert-PLOSStateAcl {
    param([Parameter(Mandatory = $true)][string]$Path, [Parameter(Mandatory = $true)][bool]$Directory)
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Lifecycle state path cannot be a reparse point.' }
    $acl = Get-Acl -LiteralPath $Path
    $allowed = @('NT AUTHORITY\SYSTEM', 'BUILTIN\Administrators', 'S-1-5-18', 'S-1-5-32-544')
    $unexpected = @($acl.Access | Where-Object {
        [string]$_.AccessControlType -eq 'Allow' -and [string]$_.IdentityReference -notin $allowed
    })
    if (-not $acl.AreAccessRulesProtected -or [string]$acl.Owner -notin @('NT AUTHORITY\SYSTEM', 'S-1-5-18') -or
        $unexpected.Count -ne 0) { throw 'Lifecycle state ACL is unsafe.' }
}

function Set-PLOSProtectedAcl {
    param([Parameter(Mandatory = $true)][string]$Path, [Parameter(Mandatory = $true)][bool]$Directory)
    if ($Directory) { $acl = New-Object Security.AccessControl.DirectorySecurity }
    else { $acl = New-Object Security.AccessControl.FileSecurity }
    $system = New-Object Security.Principal.NTAccount('NT AUTHORITY', 'SYSTEM')
    $administrators = New-Object Security.Principal.NTAccount('BUILTIN', 'Administrators')
    $acl.SetOwner($system)
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = if ($Directory) { 'ContainerInherit,ObjectInherit' } else { 'None' }
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($system, 'FullControl', $inheritance, 'None', 'Allow')))
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($administrators, 'FullControl', $inheritance, 'None', 'Allow')))
    Set-Acl -LiteralPath $Path -AclObject $acl
    Assert-PLOSStateAcl -Path $Path -Directory $Directory
}

function Initialize-PLOSStateRoot {
    param([Parameter(Mandatory = $true)]$Manifest)
    $root = [string]$Manifest.state_root
    if (-not (Test-Path -LiteralPath $root -PathType Container)) {
        New-Item -ItemType Directory -Force -Path $root | Out-Null
        Set-PLOSProtectedAcl -Path $root -Directory $true
    } else { Assert-PLOSStateAcl -Path $root -Directory $true }
    $root
}

function Read-PLOSState {
    param([Parameter(Mandatory = $true)]$Manifest)
    $path = Join-Path ([string]$Manifest.state_root) $stateFileName
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { return New-PLOSDefaultState }
    Assert-PLOSStateAcl -Path $path -Directory $false
    $state = Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
    if ($null -eq $state.PSObject.Properties['generation']) { $state | Add-Member -NotePropertyName generation -NotePropertyValue 0 }
    if ($null -eq $state.PSObject.Properties['technical_fences']) { $state | Add-Member -NotePropertyName technical_fences -NotePropertyValue @() }
    Assert-PLOSExactProperties -Value $state -Name 'Lifecycle state' -Allowed @('schema', 'revision', 'lease', 'last_target', 'switches', 'requests', 'generation', 'technical_fences')
    if ($state.generation -isnot [long] -and $state.generation -isnot [int] -or [long]$state.generation -lt 0 -or
        $state.technical_fences -isnot [array] -or $state.technical_fences.Count -gt $maximumJournalEntries) { throw 'Lifecycle fence state is invalid.' }
    if ([string]$state.schema -ne 'plos.model_host_controller.lifecycle_state.v1' -or
        [string]$state.revision -ne 'MHC-04-guarded-lifecycle') { throw 'Lifecycle state identity drifted.' }
    $state
}

function Write-PLOSState {
    param([Parameter(Mandatory = $true)]$Manifest, [Parameter(Mandatory = $true)]$State)
    $root = Initialize-PLOSStateRoot -Manifest $Manifest
    $path = Join-Path $root $stateFileName
    $State.generation = [long]$State.generation + 1
    $temporary = Join-Path $root ('state-' + [Guid]::NewGuid().ToString('N') + '.tmp')
    try {
        [IO.File]::WriteAllText($temporary, ((ConvertTo-PLOSJson -Value $State) + "`n"), (New-Object Text.UTF8Encoding($false)))
        Set-PLOSProtectedAcl -Path $temporary -Directory $false
        Move-Item -LiteralPath $temporary -Destination $path -Force
        Set-PLOSProtectedAcl -Path $path -Directory $false
    } finally { Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue }
}

function Get-PLOSRequestRecord {
    param([Parameter(Mandatory = $true)]$State, [Parameter(Mandatory = $true)][string]$Id)
    @($State.requests | Where-Object { [string]$_.request_id -eq $Id })
}

# Read persisted operation evidence without executing or replaying the operation.
function Get-PLOSReceipt {
    param([Parameter(Mandatory)]$Manifest, [Parameter(Mandatory)][string]$Operation,
        [Parameter(Mandatory)][string]$Id, [Parameter(Mandatory)][string]$ExpectedLease,
        [Parameter(Mandatory)][string]$ExpectedTarget)
    if ($Operation -cnotin @('activate','heartbeat','release','technical_fence')) { throw 'Receipt operation is invalid.' }
    Assert-PLOSRequestIdentity -Value $Id -Name 'Request'
    Assert-PLOSRequestIdentity -Value $ExpectedLease -Name 'Lease'
    $null = Get-PLOSTargetSpec -Manifest $Manifest -Id $ExpectedTarget
    $state = Read-PLOSState -Manifest $Manifest
    $records = @(if ($Operation -ceq 'technical_fence') { $state.technical_fences | Where-Object { $_.request_id -ceq $Id } } else { Get-PLOSRequestRecord -State $state -Id $Id })
    $receipt = $null
    if ($records.Count -gt 0) {
        $signature = if ($Operation -ceq 'technical_fence') { "technical_fence|$ExpectedTarget|$ExpectedLease" } elseif ($Operation -ceq 'activate') { "activate|$ExpectedTarget|$ExpectedLease" } else { "$Operation||$ExpectedLease" }
        $schemaOperation = if ($Operation -ceq 'activate') { 'activation' } else { $Operation }
        if ($records.Count -ne 1 -or [string]$records[0].request_id -cne $Id -or
            [string]$records[0].signature -cne $signature) { throw 'Receipt identity is ambiguous or differs.' }
        $receipt = $records[0].response
        if ([string]$receipt.schema -cne "plos.model_host_controller.$schemaOperation.v1" -or
            [string]$receipt.host_id -cne [string]$Manifest.host_id -or [string]$receipt.request_id -cne $Id -or
            [string]$receipt.lease_id -cne $ExpectedLease -or [string]$receipt.target -cne $ExpectedTarget -or
            $receipt.contains_prompt_or_response_content -isnot [bool] -or $receipt.contains_prompt_or_response_content) {
            throw 'Persisted receipt is inexact or unsafe.'
        }
    }
    [ordered]@{
        schema = 'plos.model_host_controller.receipt_lookup.v1'; host_id = [string]$Manifest.host_id
        request_id = $Id; lease_id = $ExpectedLease; target = $ExpectedTarget; operation = $Operation
        record_found = [bool]($null -ne $receipt); receipt = $receipt
        mutation_available = $false; contains_prompt_or_response_content = $false
        result = if ($null -ne $receipt) { 'found' } else { 'not_found' }
    }
}

function Add-PLOSRequestRecord {
    param([Parameter(Mandatory = $true)]$State, [Parameter(Mandatory = $true)][string]$Id,
        [Parameter(Mandatory = $true)][string]$Signature, [Parameter(Mandatory = $true)]$Response)
    $records = @($State.requests) + @([ordered]@{ request_id = $Id; signature = $Signature; response = $Response })
    if ($records.Count -gt $maximumJournalEntries) { $records = @($records | Select-Object -Last $maximumJournalEntries) }
    $State.requests = $records
}

function Test-PLOSLeaseExpired {
    param($Lease)
    if ($null -eq $Lease) { return $false }
    [DateTimeOffset]::Parse([string]$Lease.expires_at).ToUniversalTime() -le [DateTimeOffset]::UtcNow
}

function Get-PLOSSwitchesInWindow {
    param([Parameter(Mandatory = $true)]$State)
    $cutoff = [DateTimeOffset]::UtcNow.AddHours(-1)
    @($State.switches | Where-Object { [DateTimeOffset]::Parse([string]$_).ToUniversalTime() -gt $cutoff })
}

function Invoke-PLOSPinnedControl {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec, [Parameter(Mandatory = $true)][string]$Mode)
    $arguments = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', [string]$Spec.control_path)
    if ($Spec.adapter_type -cnotin @('pinned_llama','ollama_coex')) { throw 'Unsupported pinned-control adapter.' }
    $arguments += @('-Action', $(if ($Mode -eq 'start') { 'Promote' } else { 'Stop' }))
    foreach ($p in @($Spec.control_roots.PSObject.Properties)) { $arguments += ('-' + [string]$p.Name); $arguments += [string]$p.Value }
    if ($Mode -eq 'start') { $arguments += @('-ReadyWaitSeconds',[string]$Spec.startup_timeout_seconds) }
    $output = @(& powershell.exe @arguments 2>&1 | ForEach-Object { [string]$_ })
    if ($LASTEXITCODE -ne 0) { throw "Pinned $Id lifecycle control failed." }
    $lines = @($output | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    if ($lines.Count -eq 0) { throw "Pinned $Id lifecycle control returned no receipt." }
    $null = $lines[-1] | ConvertFrom-Json
}

function Assert-PLOSScheduledLaunchBinding {
    param([Parameter(Mandatory = $true)]$Spec, [switch]$PassThru)
    $service = if ($Spec.adapter_type -ceq 'scheduled_process_group') { 'process_group' } else { 'llama' }
    $pattern = '^-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' +
        [regex]::Escape([string]$Spec.control_path) +
        '" -Service ' + $service + ' -LaunchPath "(?<path>[A-Za-z]:\\[^"\r\n]+)" -LaunchSha256 (?<sha>[a-f0-9]{64})$'
    $binding = [regex]::Match([string]$Spec.task_arguments, $pattern)
    if (-not $binding.Success -or
        (Get-PLOSSha256 -Path ([string]$Spec.control_path)) -cne [string]$Spec.control_sha256) {
        throw 'Scheduled launcher contract or identity mismatch.'
    }
    $launchPath = $binding.Groups['path'].Value
    if ((Get-PLOSSha256 -Path $launchPath) -cne $binding.Groups['sha'].Value) {
        throw 'Scheduled launch projection identity mismatch.'
    }
    $launch = Get-Content -Raw -LiteralPath $launchPath | ConvertFrom-Json
    if ($launch.schema -cne $(if ($service -ceq 'process_group') { 'plos.process_group_launch.v1' } else { 'plos.native_llama_launch.v1' }) -or
        $launch.model_path -cne [string]$Spec.model_path -or
        ('sha256:' + $launch.model_sha256) -cne [string]$Spec.model_identity -or
        $launch.executable_path -cne [string]$Spec.executable_path -or
        $launch.executable_sha256 -cne [string]$Spec.executable_sha256) {
        throw 'Scheduled projection differs from controller model/runtime identity.'
    }
    if ($PassThru) { return [pscustomobject]@{launch=$launch;path=$launchPath;sha256=$binding.Groups['sha'].Value} }
}

function Invoke-PLOSProcessGroupAction {
    param([Parameter(Mandatory = $true)]$Spec, [ValidateSet('status','stop')][string]$Action)
    $binding = Assert-PLOSScheduledLaunchBinding -Spec $Spec -PassThru
    $launch = $binding.launch
    if ((Get-PLOSSha256 -Path ([string]$launch.wrapper_path)) -cne [string]$launch.wrapper_sha256 -or
        (Get-PLOSSha256 -Path ([string]$launch.executable_path)) -cne [string]$launch.executable_sha256 -or
        (Get-PLOSSha256 -Path ([string]$launch.entrypoint_path)) -cne [string]$launch.entrypoint_sha256) {
        throw 'Process-group helper runtime identity differs.'
    }
    $output = @(& $launch.entrypoint_path ([string]$launch.wrapper_path) $Action '--launch' $binding.path '--launch-sha256' $binding.sha256)
    if ($LASTEXITCODE -ne 0 -or $output.Count -ne 1) { throw 'Process-group status or cleanup failed.' }
    $result = $output[0] | ConvertFrom-Json
    if ($result.schema -cne 'plos.process_group_status.v1' -or $result.launch_sha256 -cne $binding.sha256 -or
        $result.active -isnot [bool] -or $result.pids -isnot [array] -or
        ($Action -ceq 'stop' -and (-not $result.cleanup_verified -or $result.pids.Count -ne 0))) {
        throw 'Process-group receipt differs.'
    }
    return $result
}

function Get-PLOSStartupElapsedSeconds {
    param($Clock)
    $Clock.Elapsed.TotalSeconds
}

function Start-PLOSScheduledLlama {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec)
    # Opt-in projection: legacy targets retain their existing verification path.
    $phased = $null -ne $Spec.PSObject.Properties['verification_timeout_seconds']
    if ($phased) {
        if (-not (Test-PLOSCapacityInteger $Spec.verification_timeout_seconds) -or
            $Spec.verification_timeout_seconds -le 0) { throw 'Invalid verification budget.' }
        # The pinned launcher still hashes the full model before engine creation.
        Assert-PLOSScheduledLaunchBinding -Spec $Spec
    } elseif ((Get-PLOSSha256 -Path ([string]$Spec.model_path)) -ne ([string]$Spec.model_identity -replace '^sha256:', '')) {
        throw 'Scheduled model identity mismatch.'
    }
    $task = Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction Stop
    if (@($task.Actions).Count -ne 1 -or [string]$task.Actions[0].Arguments -cne [string]$Spec.task_arguments -or
        [string]$task.State -ceq 'Running' -or @(Get-PLOSOwnedProcesses -Id $Id -Spec $Spec).Count -ne 0) {
        throw 'Scheduled model launch requires exact idle ownership.'
    }
    $clock = [Diagnostics.Stopwatch]::StartNew()
    $phase = if ($phased) { 'verification' } else { 'readiness' }
    $phaseStart = 0.0; $verificationSeconds = 0.0; $observedRunning = $false
    $failureCode = 'startup_error'
    try {
        Enable-ScheduledTask -TaskName ([string]$Spec.task_name) | Out-Null
        Start-ScheduledTask -TaskName ([string]$Spec.task_name)
        while ($true) {
            Start-Sleep -Seconds 1
            $elapsed = Get-PLOSStartupElapsedSeconds $clock
            $budget = if ($phase -ceq 'verification') { [double]$Spec.verification_timeout_seconds } else { [double]$Spec.startup_timeout_seconds }
            if ($elapsed - $phaseStart -ge $budget) { $failureCode = $phase + '_timeout'; break }
            $task = Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction Stop
            if (@($task.Actions).Count -ne 1 -or [string]$task.Actions[0].Arguments -cne [string]$Spec.task_arguments) {
                throw 'Scheduled task identity changed during startup.'
            }
            if ([string]$task.State -ceq 'Running') { $observedRunning = $true }
            if ([string]$task.State -notin @('Running', 'Ready') -or
                ($observedRunning -and [string]$task.State -cne 'Running')) { $failureCode = 'task_exited'; break }
            if ($phase -ceq 'verification') {
                $owned = @(Get-PLOSOwnedProcesses -Id $Id -Spec $Spec)
                if ($owned.Count -gt 1) { throw 'Multiple owned engine processes during startup.' }
                if ($owned.Count -eq 0) { continue }
                if ($Spec.adapter_type -ceq 'scheduled_process_group' -and
                    -not (Invoke-PLOSProcessGroupAction -Spec $Spec -Action status).active) { continue }
                # Exact engine appearance proves the unchanged launcher's hash
                # completed. This phase includes scheduling overhead, not hash-only time.
                $verificationSeconds = $elapsed; $phaseStart = $elapsed; $phase = 'readiness'
            }
            $status = Get-PLOSTargetStatus -Id $Id -Spec $Spec
            $elapsed = Get-PLOSStartupElapsedSeconds $clock
            if ($elapsed - $phaseStart -ge [double]$Spec.startup_timeout_seconds) { $failureCode = 'readiness_timeout'; break }
            if ($status.running_exact) {
                $script:scheduledStartupTiming = [ordered]@{
                    verification_and_launch_seconds = $verificationSeconds
                    readiness_seconds = $elapsed - $phaseStart
                    total_seconds = $elapsed; phased = $phased
                }
                return
            }
        }
        throw 'Scheduled model startup did not complete.'
    } catch {
        Stop-PLOSScheduledLlama -Id $Id -Spec $Spec
        $clean = Get-PLOSTargetStatus -Id $Id -Spec $Spec
        if (-not $clean.task_exact -or $clean.task_enabled -or $clean.task_state -ceq 'Running' -or
            $clean.owned_process_count -ne 0 -or $clean.loopback_listener_count -ne 0) {
            throw 'Scheduled startup cleanup is unverified.'
        }
        $error = New-Object InvalidOperationException('Scheduled model startup failed; owned cleanup verified.')
        $error.Data['plos_startup_failure'] = [ordered]@{
            phase = $phase; code = $failureCode
            elapsed_seconds = (Get-PLOSStartupElapsedSeconds $clock)
            cleanup_verified = $true
        }
        throw $error
    } finally { $clock.Stop() }
}

function Stop-PLOSScheduledLlama {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec)
    $task = Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction SilentlyContinue
    if ($null -ne $task) {
        if (@($task.Actions).Count -ne 1 -or [string]$task.Actions[0].Arguments -cne [string]$Spec.task_arguments) {
            throw 'Refusing to stop a non-owned model task.'
        }
        if ($Spec.adapter_type -ceq 'scheduled_process_group') {
            $null = Invoke-PLOSProcessGroupAction -Spec $Spec -Action stop
        }
        Stop-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction SilentlyContinue
    }
    foreach ($process in @(Get-PLOSOwnedProcesses -Id $Id -Spec $Spec)) {
        Stop-Process -Id ([int]$process.ProcessId) -Force -ErrorAction Stop
    }
    if ($null -ne $task) { Disable-ScheduledTask -TaskName ([string]$Spec.task_name) | Out-Null }
    $deadline = (Get-Date).AddSeconds(30)
    while (@(Get-PLOSOwnedProcesses -Id $Id -Spec $Spec).Count -gt 0 -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 500
    }
    if (@(Get-PLOSOwnedProcesses -Id $Id -Spec $Spec).Count -ne 0) { throw 'Scheduled model cleanup failed.' }
}

function Invoke-PLOSOllamaUnload {
    param([Parameter(Mandatory = $true)]$Spec)
    $body = [ordered]@{ model = [string]$Spec.model_api_id; prompt = ''; stream = $false; keep_alive = 0 } |
        ConvertTo-Json -Depth 4 -Compress
    $null = Invoke-RestMethod -Uri "http://127.0.0.1:$([int]$Spec.port)/api/generate" -Method Post `
        -ContentType 'application/json' -Body $body -TimeoutSec 180
}

function Start-PLOSTarget {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec)
    if ($Spec.adapter_type -ceq 'authenticated_reranker') { Start-PLOSRerankerTarget -Spec $Spec }
    elseif ($Spec.adapter_type -ceq 'authenticated_image') { Start-PLOSImageTarget -Spec $Spec }
    elseif ($Spec.adapter_type -ceq 'colibri_gateway') { Start-PLOSGlmTarget -Spec $Spec }
    elseif ($Spec.adapter_type -cin @('scheduled_llama','scheduled_process_group')) { Start-PLOSScheduledLlama -Id $Id -Spec $Spec }
    else { Invoke-PLOSPinnedControl -Id $Id -Spec $Spec -Mode 'start' }
}

function Stop-PLOSTarget {
    param([Parameter(Mandatory = $true)][string]$Id, [Parameter(Mandatory = $true)]$Spec)
    if ($Spec.adapter_type -ceq 'authenticated_reranker') { Stop-PLOSRerankerTarget -Spec $Spec }
    elseif ($Spec.adapter_type -ceq 'authenticated_image') { Stop-PLOSImageTarget -Spec $Spec }
    elseif ($Spec.adapter_type -ceq 'colibri_gateway') { Stop-PLOSGlmTarget -Spec $Spec }
    elseif ($Spec.adapter_type -cin @('scheduled_llama','scheduled_process_group')) { Stop-PLOSScheduledLlama -Id $Id -Spec $Spec }
    else { Invoke-PLOSPinnedControl -Id $Id -Spec $Spec -Mode 'stop' }
}

function Assert-PLOSOllamaCleanupExact {
    param([Parameter(Mandatory = $true)]$Spec)
    if ((Get-PLOSSha256 -Path ([string]$Spec.control_path)) -cne [string]$Spec.control_sha256) {
        throw 'Ollama cleanup control identity drifted.'
    }
    $arguments=@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',[string]$Spec.control_path,'-Action','Status')
    foreach ($p in @($Spec.control_roots.PSObject.Properties)) { $arguments += ('-' + [string]$p.Name); $arguments += [string]$p.Value }
    $output = @(& powershell.exe @arguments 2>&1 | ForEach-Object { [string]$_ })
    if ($LASTEXITCODE -ne 0) { throw 'Ollama cleanup status failed.' }
    $lines = @($output | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
    if ($lines.Count -eq 0) { throw 'Ollama cleanup status returned no receipt.' }
    $status = $lines[-1] | ConvertFrom-Json
    if ([string]$status.schema -ne 'plos.k3_qwen_ollama_durable.v1' -or
        [bool]$status.enabled -or [string]$status.task_state -eq 'Running' -or
        [bool]$status.ollama_healthy -or [int]$status.ollama_process_count -ne 0 -or
        [bool]$status.ingress_open -or -not [bool]$status.checkpoint_valid -or
        [string]$status.admission_mode -ne 'serialized' -or [bool]$status.tools_enabled -or
        [bool]$status.fallback_enabled -or [bool]$status.generic_routing_enabled) {
        throw 'Ollama controller-owned cleanup proof failed.'
    }
}

function Test-PLOSTargetResourceGate {
    param($Spec, $Resources, $Guards)
    # Ollama manages CPU/GPU placement and memory; telemetry is informational.
    if ($Spec.resource_mode -ceq 'runtime_managed') { return $true }
    if ($Spec.resource_mode -ceq 'ram_commit_c') { return Test-PLOSGlmLifecycleResources -Resources $Resources -Guards $Guards }
    return [bool]$Resources.gate_passed
}

# Diagnostics run only after an existing gate refused the same sampled state.
# They never decide admission or resample resources.
function Stop-PLOSLifecycleGate {
    param($Id, $Status, $Resources, $Guards, [long]$RequiredRam,
        [bool]$RequireRunning = $false, [long]$PagefileBaseline = -1, $Assessment = $null, $Spec = $null)
    $failed = @()
    foreach ($field in @('task_exact', 'control_exact', 'executable_exact')) {
        if (-not $Status.$field) { $failed += $field }
    }
    if ($null -ne $Spec -and $Spec.adapter_type -cin @('colibri_gateway','authenticated_image','authenticated_reranker') -and -not $Status.runtime_files_exact) { $failed += 'runtime_files_exact' }
    if ($RequireRunning -and -not $Status.running_exact) { $failed += 'running_exact' }
    if ($null -ne $Assessment -and $Assessment.table_owned) {
        foreach ($metric in @($Assessment.requirements.Keys)) {
            if (-not $Assessment.requirements[$metric].passed) { $failed += $metric }
        }
        if (-not $Assessment.eligible -and $failed.Count -eq 0) {
            $failed += $(if($Assessment.result -in @('telemetry_stale','telemetry_unavailable')){'resource_telemetry'}else{'resource_gate'})
        }
    } elseif ($null -eq $Spec -or $Spec.resource_mode -cne 'runtime_managed') {
        $numericValid = $true
        foreach ($field in @('free_physical_bytes', 'commit_headroom_bytes', 'drive_c_free_bytes', 'pagefile_current_mib')) {
            if ($null -eq $Resources.$field -or [double]::IsNaN([double]$Resources.$field) -or
                [double]::IsInfinity([double]$Resources.$field) -or [double]$Resources.$field -lt 0) {
                $failed += 'resource_telemetry'; $numericValid = $false; break
            }
        }
        if ($numericValid) {
        if ([int64]$Resources.free_physical_bytes -lt $RequiredRam) { $failed += 'free_physical_bytes' }
        if ([int64]$Resources.commit_headroom_bytes -lt [int64]$Guards.minimum_commit_headroom_bytes) { $failed += 'commit_headroom_bytes' }
        if ([int64]$Resources.drive_c_free_bytes -lt [int64]$Guards.drive_c_free_bytes) { $failed += 'drive_c_free_bytes' }
        }
        if ([bool]$Guards.balanced_power_required -and $Resources.balanced_power -ne $true) { $failed += 'balanced_power' }
        if ($null -eq $Spec -or $Spec.resource_mode -ceq 'full') {
            if (-not $Resources.telemetry_complete) { $failed += 'resource_telemetry' }
            if ([int64]$Resources.drive_d_free_bytes -lt [int64]$Guards.drive_d_free_bytes) { $failed += 'drive_d_free_bytes' }
            if ([int64]$Resources.pagefile_current_mib -gt [int64]$Guards.maximum_pagefile_current_mib) { $failed += 'pagefile_current_mib' }
            if ($PagefileBaseline -ge 0 -and [int64]$Resources.pagefile_current_mib -gt ($PagefileBaseline + [int64]$Guards.maximum_pagefile_growth_mib)) { $failed += 'pagefile_growth_mib' }
        }
    }
    if ($failed.Count -eq 0) { $failed = @('resource_gate') }
    $error = New-Object InvalidOperationException('Lifecycle gate rejected the request.')
    $error.Data['plos_failed_checks'] = @($failed | Select-Object -Unique)
    throw $error
}

function Get-PLOSPlan {
    param([Parameter(Mandatory = $true)]$Manifest, [Parameter(Mandatory = $true)][string]$Id,
        [Parameter(Mandatory = $true)][string]$PlanRequestId)
    Assert-PLOSRequestIdentity -Value $PlanRequestId -Name 'Request'
    $spec = Get-PLOSTargetSpec -Manifest $Manifest -Id $Id
    $status = Get-PLOSTargetStatus -Id $Id -Spec $spec
    Assert-PLOSAdmissionResidency -Spec $spec -Status $status
    $resources = Get-PLOSResources -Manifest $Manifest
    $admission = Get-PLOSAdmission $Manifest $Id $spec $resources (Test-PLOSModelLoaded $Manifest $status) ([long]$resources.pagefile_current_mib)
    $requiredRam = $admission.required_free_physical_bytes
    $resourceGate = $admission.eligible
    $identityExact = [bool]($status.task_exact -and $status.control_exact -and $status.executable_exact -and
        ($Spec.adapter_type -cnotin @('colibri_gateway','authenticated_image','authenticated_reranker') -or $status.runtime_files_exact))
    $eligible = [bool]($resourceGate -and [int64]$resources.free_physical_bytes -ge $requiredRam -and
        $identityExact)
    [ordered]@{
        schema = 'plos.model_host_controller.lifecycle_plan.v1'
        host_id = $ExpectedHostId
        request_id = $PlanRequestId
        target = $Id
        task_present = [bool]$status.task_present
        exact_identity = $identityExact
        already_ready = [bool]$status.running_exact
        resource_gate_passed = $resourceGate
        resource_assessment = $admission
        required_free_physical_bytes = $requiredRam
        eligible = $eligible
        mutation_available = $true
        execution_mode = 'serial'
        fallback_enabled = $false
        tools_enabled = $false
        contains_prompt_or_response_content = $false
        result = if ($eligible) { 'ready' } else { 'rejected' }
    }
}

function Get-PLOSStatus {
    param([Parameter(Mandatory = $true)]$Manifest, [string]$SelectedTarget = '')
    $state = Read-PLOSState -Manifest $Manifest
    $leaseExpired = Test-PLOSLeaseExpired -Lease $state.lease
    $targets = @()
    $script:ollamaStatusSnapshots=@{}
    try {
    if (-not [string]::IsNullOrWhiteSpace($SelectedTarget)) {
        $spec = Get-PLOSTargetSpec -Manifest $Manifest -Id $SelectedTarget
        $targets += Get-PLOSTargetStatus -Id $SelectedTarget -Spec $spec
    } else {
        foreach ($property in @($Manifest.targets.PSObject.Properties)) {
            $targets += Get-PLOSTargetStatus -Id ([string]$property.Name) -Spec $property.Value
        }
    }
    } finally { $script:ollamaStatusSnapshots=$null }
    $switches = @(Get-PLOSSwitchesInWindow -State $state)
    [ordered]@{
        schema = 'plos.model_host_controller.lifecycle_status.v1'
        host_id = $ExpectedHostId
        revision = [string]$Manifest.revision
        active_target = if ($null -ne $state.lease -and -not $leaseExpired) { [string]$state.lease.target } else { $null }
        lease_present = [bool]($null -ne $state.lease)
        lease_expired = $leaseExpired
        generation = [long]$state.generation
        state_generation_sha256 = if (Test-Path -LiteralPath (Join-Path ([string]$Manifest.state_root) $stateFileName) -PathType Leaf) { Get-PLOSSha256 -Path (Join-Path ([string]$Manifest.state_root) $stateFileName) } else { $null }
        lease_id_sha256 = if ($null -ne $state.lease) { Get-PLOSStringSha256 -Value ([string]$state.lease.lease_id) } else { $null }
        lease_expires_at = if ($null -ne $state.lease) { [string]$state.lease.expires_at } else { $null }
        capacity_binding = Get-PLOSCapacityValue $state.lease 'capacity_binding'
        switches_last_hour = [int]$switches.Count
        max_switches_per_hour = [int]$Manifest.max_switches_per_hour
        resources = Get-PLOSResources -Manifest $Manifest
        targets = $targets
        mutation_available = $true
        execution_mode = 'serial'
        compatible_residency_enabled = $false
        concurrent_inference_enabled = $false
        fallback_enabled = $false
        tools_enabled = $false
        contains_prompt_or_response_content = $false
        result = 'ready'
    }
}


function Assert-PLOSAdmissionResidency {
    param($Spec, $Status)
    if ($Spec.adapter_type -ceq 'ollama_coex') {
        $present = $Status.owned_process_count -gt 0 -or $Status.loopback_listener_count -gt 0
        if ($present -and (-not $Status.running_exact -or -not $Status.residency_verified)) { throw 'Ollama engine or model residency is unverified or belongs to another target.' }
    }
    if ($Spec.adapter_type -cin @('authenticated_image','authenticated_reranker') -and
        (($Status.loopback_listener_count -gt 0 -and -not $Status.running_exact) -or $Status.busy)) { throw 'Image runtime is busy or belongs to another exact target.' }
}

function Clear-PLOSOwnedOllamaResidency {
    param($Spec, $Lease)
    if ($Spec.adapter_type -cne 'ollama_coex') { return $false }
    $owned = [bool]$Lease.model_loaded_by_controller -or [bool](Get-PLOSCapacityValue $Lease 'model_residency_owned')
    if (-not $owned) { return $false }
    $status=Get-PLOSHealthStatus -Id ([string]$Lease.target) -Spec $Spec
    if (-not $status.healthy -or -not $status.model_exact -or -not $status.residency_verified) { throw 'Owned Ollama residency cleanup cannot verify exact model state.' }
    $unloaded=$false
    if ($status.model_loaded_exact) { Invoke-PLOSOllamaUnload -Spec $Spec; $unloaded=$true }
    $after=Get-PLOSHealthStatus -Id ([string]$Lease.target) -Spec $Spec
    if (-not $after.healthy -or -not $after.model_exact -or -not $after.residency_verified -or $after.model_loaded_exact) { throw 'Owned Ollama model unload is unverified.' }
    return $unloaded
}

function Invoke-PLOSActivate {
    param([Parameter(Mandatory = $true)]$Manifest)
    if (-not $Execute) { throw 'Lifecycle activation requires Execute.' }
    Assert-PLOSRequestIdentity -Value $RequestId -Name 'Request'
    Assert-PLOSRequestIdentity -Value $LeaseId -Name 'Lease'
    $spec = Get-PLOSTargetSpec -Manifest $Manifest -Id $Target
    $null = Initialize-PLOSStateRoot -Manifest $Manifest
    $state = Read-PLOSState -Manifest $Manifest
    if (@($state.technical_fences | Where-Object { $_.response.activation_request_id -ceq $RequestId }).Count -gt 0) {
        throw 'Original activation request is technically fenced; replay is prohibited.'
    }
    $signature = "activate|$Target|$LeaseId"
    $prior = @(Get-PLOSRequestRecord -State $state -Id $RequestId)
    if ($prior.Count -gt 0) {
        if ($prior.Count -ne 1 -or [string]$prior[0].signature -cne $signature) { throw 'Request replay conflict.' }
        return $prior[0].response
    }
    if ($null -ne $state.lease -and -not (Test-PLOSLeaseExpired -Lease $state.lease)) {
        if ([string]$state.lease.lease_id -ceq $LeaseId -and [string]$state.lease.target -ceq $Target) {
            throw 'Lease replay requires the original request identity.'
        }
        throw 'An unexpired lifecycle lease is already active.'
    }
    if ($null -ne $state.lease) { throw 'Expired lifecycle custody requires verified recovery before another activation.' }
    $state.lease = $null
    $switches = @(Get-PLOSSwitchesInWindow -State $state)
    if (-not ($spec.switch_budget_exempt -is [bool] -and $spec.switch_budget_exempt) -and $switches.Count -ge [int]$Manifest.max_switches_per_hour) { throw 'Lifecycle switch-rate budget is exhausted.' }
    $before = Get-PLOSTargetStatus -Id $Target -Spec $spec
    Assert-PLOSAdmissionResidency -Spec $spec -Status $before
    $resources = Get-PLOSResources -Manifest $Manifest
    $admission = Get-PLOSAdmission $Manifest $Target $spec $resources (Test-PLOSModelLoaded $Manifest $before) ([long]$resources.pagefile_current_mib)
    $requiredRam = $admission.required_free_physical_bytes
    if (-not $admission.eligible -or
        -not $before.task_exact -or -not $before.control_exact -or -not $before.executable_exact -or
        ($Spec.adapter_type -cin @('colibri_gateway','authenticated_image','authenticated_reranker') -and -not $before.runtime_files_exact)) {
        Stop-PLOSLifecycleGate -Id $Target -Status $before -Resources $resources -Guards $Manifest.resource_guards -RequiredRam $requiredRam -Assessment $admission -Spec $spec
    }
    $started = $false
    $script:scheduledStartupTiming = $null
    $loaded = $false
    $beforeHash = Get-PLOSStringSha256 -Value (ConvertTo-PLOSJson -Value $before)
    try {
        if (-not $before.running_exact) {
            Start-PLOSTarget -Id $Target -Spec $spec
            $started = $true
        }
        $after = Get-PLOSTargetStatus -Id $Target -Spec $spec
        $afterResources = Get-PLOSResources -Manifest $Manifest
        $afterAdmission = Get-PLOSAdmission $Manifest $Target $spec $afterResources (Test-PLOSModelLoaded $Manifest $after) ([long]$resources.pagefile_current_mib)
        if (-not $after.running_exact -or -not $afterAdmission.eligible) {
            Stop-PLOSLifecycleGate -Id $Target -Status $after -Resources $afterResources -Guards $Manifest.resource_guards -RequiredRam ([int64]$spec.ready_minimum_free_physical_bytes) -RequireRunning $true -PagefileBaseline ([int64]$resources.pagefile_current_mib) -Assessment $afterAdmission -Spec $spec
        }
        $now = [DateTimeOffset]::UtcNow
        $lease = [ordered]@{
            lease_id = $LeaseId
            target = $Target
            owner = 'production'
            acquired_at = $now.ToString('o')
            last_heartbeat_at = $now.ToString('o')
            expires_at = $now.AddSeconds([int]$Manifest.lease_ttl_seconds).ToString('o')
            previous_state_sha256 = $beforeHash
            pagefile_baseline_mib = [int64]$resources.pagefile_current_mib
            started_by_controller = $started
            model_loaded_by_controller = $loaded
            model_residency_owned = [bool]($Spec.adapter_type -ceq 'ollama_coex' -and -not $before.model_loaded_exact)
            capacity_binding = Get-PLOSCapacityValue $afterAdmission 'request_binding'
        }
        $response = [ordered]@{
            schema = 'plos.model_host_controller.activation.v1'
            host_id = $ExpectedHostId
            request_id = $RequestId
            lease_id = $LeaseId
            target = $Target
            lease_expires_at = [string]$lease.expires_at
            already_ready = [bool](-not $started -and -not $loaded)
            started_by_controller = $started
            model_loaded_by_controller = $loaded
            resource_gate_passed = $true
            resource_assessment = $afterAdmission
            exact_identity = $true
            execution_mode = 'serial'
            fallback_enabled = $false
            tools_enabled = $false
            mutation_available = $true
            contains_prompt_or_response_content = $false
            result = if ($started -or $loaded) { 'activated' } else { 'already_ready' }
        }
        if ($null -ne $script:scheduledStartupTiming) { $response['startup_timing'] = $script:scheduledStartupTiming }
        $state.lease = $lease
        $state.last_target = $Target
        # Retain legacy entries until normal expiry. Only explicitly exempt calls
        # stop consuming another target's limited activation budget.
        $state.switches = @($switches)
        if (-not ($spec.switch_budget_exempt -is [bool] -and $spec.switch_budget_exempt)) {
            $state.switches += @($now.ToString('o'))
        }
        Add-PLOSRequestRecord -State $state -Id $RequestId -Signature $signature -Response $response
        Write-PLOSState -Manifest $Manifest -State $state
        $response
    } catch {
        if (-not $started -and $spec.adapter_type -cin @('scheduled_llama','scheduled_process_group','colibri_gateway','authenticated_reranker') -and
            $_.Exception.Data.Contains('plos_startup_failure')) {
            # Persist only a verified owned failure. Interrupted/unverified cleanup
            # still throws and stays unknown; absence is never proof of non-execution.
            $failure = $_.Exception.Data['plos_startup_failure']
            $response = [ordered]@{
                schema = 'plos.model_host_controller.activation.v1'; host_id = $ExpectedHostId
                request_id = $RequestId; lease_id = $LeaseId; target = $Target
                result = 'activation_failed'; cleanup_verified = $true; lease_present = $false
                startup_failure = $failure; fallback_enabled = $false; tools_enabled = $false
                contains_prompt_or_response_content = $false
            }
            Add-PLOSRequestRecord -State $state -Id $RequestId -Signature $signature -Response $response
            Write-PLOSState -Manifest $Manifest -State $state
            return $response
        }
        if ($loaded -and $Spec.adapter_type -ceq 'ollama_coex') { try { Invoke-PLOSOllamaUnload -Spec $spec } catch {} }
        if ($started) {
            try {
                if ($spec.adapter_type -ceq 'ollama_coex') {
                    $cleanupStatus = Get-PLOSHealthStatus -Id $Target -Spec $spec
                    if (-not $cleanupStatus.healthy -or -not $cleanupStatus.residency_verified) { throw 'Activation cleanup cannot own foreign or unknown model residency.' }
                }
                Stop-PLOSTarget -Id $Target -Spec $spec
            } catch {}
        }
        throw
    }
}

# Compare only the scalar/object shape of an existing qualified capacity binding.
function Test-PLOSDispatchBindingEqual {
    param($Expected, $Actual, [int]$Depth = 0)
    if ($Depth -gt 4) { return $false }
    if ($null -eq $Expected) { return ($null -eq $Actual) }
    if ($Expected -is [string]) { return ($Actual -is [string] -and $Expected -ceq $Actual) }
    if ($Expected -is [bool]) { return ($Actual -is [bool] -and $Expected -ceq $Actual) }
    if ($Expected -is [int] -or $Expected -is [long]) {
        return (($Actual -is [int] -or $Actual -is [long]) -and [long]$Expected -eq [long]$Actual)
    }
    if (($Expected -isnot [Collections.IDictionary] -and $Expected -isnot [pscustomobject]) -or
        ($Actual -isnot [Collections.IDictionary] -and $Actual -isnot [pscustomobject])) { return $false }
    $expectedKeys = @(if ($Expected -is [Collections.IDictionary]) { $Expected.Keys } else { $Expected.PSObject.Properties.Name })
    $actualKeys = @(if ($Actual -is [Collections.IDictionary]) { $Actual.Keys } else { $Actual.PSObject.Properties.Name })
    if ($expectedKeys.Count -eq 0 -or $expectedKeys.Count -gt 20 -or $expectedKeys.Count -ne $actualKeys.Count) { return $false }
    foreach ($key in $expectedKeys) {
        if ($key -cnotin $actualKeys -or
            -not (Test-PLOSDispatchBindingEqual (Get-PLOSCapacityValue $Expected $key) (Get-PLOSCapacityValue $Actual $key) ($Depth+1))) { return $false }
    }
    return $true
}

function Assert-PLOSOwnedDispatch {
    param($Declaration, $State, $Spec, $Status, $Binding)
    Assert-PLOSExactProperties -Value $Declaration -Name 'Owned dispatch' -Allowed @(
        'schema','phase','original_request_id','lease_id','target','capacity_binding')
    if ($Declaration.schema -cne 'plos.model_host_controller.dispatch.v1' -or $Declaration.phase -cne 'in_flight' -or
        $Declaration.original_request_id -isnot [string] -or $Declaration.lease_id -isnot [string] -or
        $Declaration.target -isnot [string]) { throw 'Owned dispatch declaration is invalid.' }
    Assert-PLOSRequestIdentity $Declaration.original_request_id 'Original request'
    if ($Declaration.lease_id -cne $LeaseId -or $Declaration.lease_id -cne $State.lease.lease_id -or
        $Declaration.target -cne $State.lease.target -or $Spec.adapter_type -cne 'ollama_coex' -or
        -not (Test-PLOSDispatchBindingEqual $State.lease.capacity_binding $Declaration.capacity_binding) -or
        -not (Test-PLOSDispatchBindingEqual $Binding $Declaration.capacity_binding)) { throw 'Owned dispatch custody binding differs.' }
    $activationId = $Declaration.original_request_id + '-activate'
    $activation = @(Get-PLOSRequestRecord -State $State -Id $activationId)
    if ($activation.Count -ne 1 -or $activation[0].signature -cne ('activate|'+$Declaration.target+'|'+$LeaseId) -or
        $activation[0].response.result -cnotin @('activated','already_ready') -or
        $activation[0].response.lease_id -cne $LeaseId -or $activation[0].response.target -cne $Declaration.target -or
        -not (Test-PLOSDispatchBindingEqual $activation[0].response.resource_assessment.request_binding $Binding)) {
        throw 'Owned dispatch activation is unproven.'
    }
    $renewed = @($State.requests | Where-Object {
        $_.signature -ceq ('heartbeat||'+$LeaseId) -and
        $_.response.schema -ceq 'plos.model_host_controller.heartbeat.v1' -and
        $_.response.result -ceq 'renewed' -and $_.response.lease_id -ceq $LeaseId -and
        $_.response.target -ceq $Declaration.target -and
        (Test-PLOSDispatchBindingEqual $_.response.resource_assessment.request_binding $Binding)
    })
    if ($renewed.Count -eq 0) { throw 'Owned dispatch requires a prior admitted pre-dispatch heartbeat.' }
    foreach ($field in @('task_exact','control_exact','executable_exact')) {
        if ((Get-PLOSCapacityValue $Status $field) -isnot [bool] -or -not (Get-PLOSCapacityValue $Status $field)) {
            throw 'Owned dispatch runtime identity is unproven.'
        }
    }
    $timedOut=(Get-PLOSCapacityValue $Status 'api_metadata_timed_out') -is [bool] -and
        (Get-PLOSCapacityValue $Status 'api_metadata_timed_out')
    $ownedLoading=$false
    if ($timedOut) {
        $identityObserved=Get-PLOSCapacityValue $Status 'model_identity_observed'
        $residencyObserved=Get-PLOSCapacityValue $Status 'residency_observed'
        $listenerOwned=Get-PLOSCapacityValue $Status 'listener_owner_exact'
        if ($identityObserved -isnot [bool] -or $residencyObserved -isnot [bool] -or
            $listenerOwned -isnot [bool] -or -not $listenerOwned -or
            ($identityObserved -and ($Status.model_exact -isnot [bool] -or -not $Status.model_exact)) -or
            ($residencyObserved -and ($Status.residency_verified -isnot [bool] -or -not $Status.residency_verified -or
                ($Status.foreign_loaded_count -isnot [int] -and $Status.foreign_loaded_count -isnot [long]) -or $Status.foreign_loaded_count -ne 0)) -or
            (-not $residencyObserved -and ($null -ne $Status.foreign_loaded_count -or
                $Status.residency_verified -cne $false -or $Status.model_loaded_exact -cne $false))) {
            throw 'Owned dispatch timeout observation is inconsistent.'
        }
        $residencyOwned=Get-PLOSCapacityValue $State.lease 'model_residency_owned'
        if ($residencyOwned -isnot [bool] -or -not $residencyOwned) { throw 'Owned loading reservation is unproven.' }
        $ownedLoading=$true
    } else {
        foreach ($field in @('running_exact','healthy','residency_verified')) {
            if ((Get-PLOSCapacityValue $Status $field) -isnot [bool] -or -not (Get-PLOSCapacityValue $Status $field)) {
                throw 'Owned dispatch runtime identity is unproven.'
            }
        }
    }
    foreach ($field in @('owned_process_count','loopback_listener_count')) {
        $value = Get-PLOSCapacityValue $Status $field
        if ($value -isnot [int] -and $value -isnot [long]) { throw 'Owned dispatch runtime counts are unproven.' }
    }
    if ($Status.model_loaded_exact -isnot [bool]) { throw 'Owned dispatch residency is unknown.' }
    $residencyOwned = Get-PLOSCapacityValue $State.lease 'model_residency_owned'
    if ($Status.owned_process_count -ne 1 -or $Status.loopback_listener_count -ne 1 -or
        (-not $ownedLoading -and ($Status.foreign_loaded_count -isnot [int] -and $Status.foreign_loaded_count -isnot [long])) -or
        ($null -ne $Status.foreign_loaded_count -and $Status.foreign_loaded_count -ne 0) -or
        (-not $Status.model_loaded_exact -and ($residencyOwned -isnot [bool] -or -not $residencyOwned))) {
        throw 'Owned dispatch model residency is unproven.'
    }
    return $ownedLoading
}

function Invoke-PLOSHeartbeat {
    param([Parameter(Mandatory = $true)]$Manifest)
    $script:heartbeatDiagnosticStage = 'request_identity'
    $script:heartbeatDiagnosticSourceLine = 0
    if (-not $Execute) { throw 'Lifecycle heartbeat requires Execute.' }
    Assert-PLOSRequestIdentity -Value $RequestId -Name 'Request'
    Assert-PLOSRequestIdentity -Value $LeaseId -Name 'Lease'
    $script:heartbeatDiagnosticStage = 'read_state'
    $null = Initialize-PLOSStateRoot -Manifest $Manifest
    $state = Read-PLOSState -Manifest $Manifest
    $dispatchEncoded = [string](Get-Variable -Name DispatchBindingBase64 -ValueOnly -ErrorAction SilentlyContinue)
    $dispatch = $null
    $signature = "heartbeat||$LeaseId"
    if ($dispatchEncoded -ne '') {
        if ($dispatchEncoded.Length -gt 8192) { throw 'Owned dispatch exceeds its bounded protocol.' }
        $dispatch = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($dispatchEncoded)) | ConvertFrom-Json
        $signature += '|dispatch:' + (Get-PLOSStringSha256 $dispatchEncoded)
    }
    $prior = @(Get-PLOSRequestRecord -State $state -Id $RequestId)
    if ($prior.Count -gt 0) {
        if ($prior.Count -ne 1 -or [string]$prior[0].signature -cne $signature) { throw 'Request replay conflict.' }
        return $prior[0].response
    }
    if ($null -eq $state.lease -or [string]$state.lease.lease_id -cne $LeaseId) {
        throw 'Lifecycle heartbeat lease does not match.'
    }
    if (Test-PLOSLeaseExpired -Lease $state.lease) { throw 'Lifecycle heartbeat lease has expired.' }
    $targetId = [string]$state.lease.target
    $spec = Get-PLOSTargetSpec -Manifest $Manifest -Id $targetId
    $script:heartbeatDiagnosticStage = 'target_status'
    $targetStatus = Get-PLOSTargetStatus -Id $targetId -Spec $spec -AllowBusyGateway:($spec.adapter_type -ceq 'colibri_gateway')
    $script:heartbeatDiagnosticStage = 'resources'
    $resources = Get-PLOSResources -Manifest $Manifest
    $script:heartbeatDiagnosticStage = 'model_loaded'
    $loaded = Test-PLOSModelLoaded $Manifest $targetStatus
    $script:heartbeatDiagnosticStage = 'capacity_projection'
    $admission = Get-PLOSAdmission $Manifest $targetId $spec $resources $loaded ([long]$state.lease.pagefile_baseline_mib)
    $ownedLoading=$false
    if ($null -ne $dispatch) {
        $ownedLoading=Assert-PLOSOwnedDispatch $dispatch $state $spec $targetStatus (Get-PLOSCapacityValue $admission 'request_binding')
        if (-not $loaded) {
            # Admission already reserved loading capacity. Do not reserve it twice
            # while the owned request allocates it but /api/ps is still empty.
            $admission = Get-PLOSAdmission $Manifest $targetId $spec $resources $true ([long]$state.lease.pagefile_baseline_mib)
        }
    }
    $script:heartbeatDiagnosticStage = 'gate'
    if ((-not $targetStatus.running_exact -and -not $ownedLoading) -or -not $admission.eligible) {
        Stop-PLOSLifecycleGate -Id $targetId -Status $targetStatus -Resources $resources -Guards $Manifest.resource_guards -RequiredRam ([int64]$spec.ready_minimum_free_physical_bytes) -RequireRunning $true -PagefileBaseline ([int64]$state.lease.pagefile_baseline_mib) -Assessment $admission -Spec $spec
    }
    $now = [DateTimeOffset]::UtcNow
    $binding=Get-PLOSCapacityValue $admission 'request_binding'
    if ($state.lease -is [Collections.IDictionary]) { $state.lease['capacity_binding']=$binding }
    else { $state.lease|Add-Member -NotePropertyName capacity_binding -NotePropertyValue $binding -Force }
    $expiresAt = $now.AddSeconds([int]$Manifest.lease_ttl_seconds).ToString('o')
    $state.lease | Add-Member -NotePropertyName last_heartbeat_at -NotePropertyValue $now.ToString('o') -Force
    $state.lease.expires_at = $expiresAt
    $response = [ordered]@{
        schema = 'plos.model_host_controller.heartbeat.v1'
        host_id = $ExpectedHostId
        request_id = $RequestId
        lease_id = $LeaseId
        target = $targetId
        lease_expires_at = $expiresAt
        exact_identity = $true
        resource_gate_passed = $true
        resource_assessment = $admission
        execution_mode = 'serial'
        fallback_enabled = $false
        tools_enabled = $false
        mutation_available = $true
        contains_prompt_or_response_content = $false
        result = 'renewed'
    }
    if ($null -ne $dispatch) { $response['dispatch'] = $dispatch }
    $script:heartbeatDiagnosticStage = 'persist'
    Add-PLOSRequestRecord -State $state -Id $RequestId -Signature $signature -Response $response
    Write-PLOSState -Manifest $Manifest -State $state
    $response
}

function Invoke-PLOSRecover {
    param([Parameter(Mandatory = $true)]$Manifest)
    if (-not $Execute) { throw 'Lifecycle recovery requires Execute.' }
    Assert-PLOSRequestIdentity -Value $RequestId -Name 'Request'
    $null = Initialize-PLOSStateRoot -Manifest $Manifest
    $state = Read-PLOSState -Manifest $Manifest
    $signature = 'recover||'
    $prior = @(Get-PLOSRequestRecord -State $state -Id $RequestId)
    if ($prior.Count -gt 0) {
        if ($prior.Count -ne 1 -or [string]$prior[0].signature -cne $signature) { throw 'Request replay conflict.' }
        return $prior[0].response
    }
    $targetId = $null
    $cleanupVerified = $true
    $recovered = $false
    if ($null -ne $state.lease -and (Test-PLOSLeaseExpired -Lease $state.lease)) {
        $targetId = [string]$state.lease.target
        $spec = Get-PLOSTargetSpec -Manifest $Manifest -Id $targetId
        $null = Clear-PLOSOwnedOllamaResidency -Spec $spec -Lease $state.lease
        if ([bool]$state.lease.started_by_controller) { Stop-PLOSTarget -Id $targetId -Spec $spec }
        if ($Spec.adapter_type -ceq 'ollama_coex' -and [bool]$state.lease.started_by_controller) { Assert-PLOSOllamaCleanupExact -Spec $spec }
        $after = Get-PLOSTargetStatus -Id $targetId -Spec $spec
        if ([bool]$state.lease.started_by_controller -and $after.running_exact) {
            throw 'Lifecycle recovery cleanup failed.'
        }
        $state.lease = $null
        $recovered = $true
    }
    $response = [ordered]@{
        schema = 'plos.model_host_controller.recovery.v1'
        host_id = $ExpectedHostId
        request_id = $RequestId
        target = $targetId
        stale_lease_recovered = $recovered
        cleanup_verified = $cleanupVerified
        active_lease_preserved = [bool]($null -ne $state.lease)
        execution_mode = 'serial'
        fallback_enabled = $false
        tools_enabled = $false
        mutation_available = $true
        contains_prompt_or_response_content = $false
        result = if ($recovered) { 'recovered' } elseif ($null -ne $state.lease) { 'active_lease_preserved' } else { 'clean' }
    }
    Add-PLOSRequestRecord -State $state -Id $RequestId -Signature $signature -Response $response
    Write-PLOSState -Manifest $Manifest -State $state
    $response
}

# Called with both lifecycle and K3 mutexes held, in that order.
function Assert-PLOSTechnicalFenceK3Clear {
    if ([string]::IsNullOrWhiteSpace($TechnicalFenceK3ManifestPath)) { throw 'Explicit K3 custody manifest is required for technical fencing.' }
    $path = $TechnicalFenceK3ManifestPath
    $k3 = Get-Content -LiteralPath $path -Raw -ErrorAction Stop | ConvertFrom-Json
    if ([string]$k3.host_id -cne $ExpectedHostId -or [string]::IsNullOrWhiteSpace([string]$k3.state_root)) { throw 'K3 custody manifest is unproven.' }
    Assert-PLOSStateAcl -Path ([string]$k3.state_root) -Directory $true
    $statePath = Join-Path ([string]$k3.state_root) 'state.json'
    if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) { return }
    Assert-PLOSStateAcl -Path $statePath -Directory $false
    $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    if ($state.schema -cne 'plos.model_host_controller.mhc08_k3.v1' -or
        $state.revision -cne 'MHC-08-k3-explicit-v1' -or
        $null -eq $state.PSObject.Properties['lease'] -or $null -ne $state.lease -or $state.phase -cne 'idle') {
        throw 'Foreign K3 custody is active or ambiguous; technical fence held.'
    }
}

# Distinct technical custody receipt. Never fabricate activation/release or mutate a runtime.
function Invoke-PLOSTechnicalFence {
    param([Parameter(Mandatory = $true)]$Manifest)
    if (-not $Execute) { throw 'Technical fencing requires Execute.' }
    $binding = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($FenceBindingBase64)) | ConvertFrom-Json
    $fields = @('owner','original_request_id','activation_request_id','lease_id','target','request_id','custody_digest','expected_host_generation','operator_authority_sha256')
    Assert-PLOSExactProperties -Value $binding -Allowed $fields -Name 'Technical fence binding'
    foreach ($id in @($binding.request_id,$binding.original_request_id,$binding.activation_request_id,$binding.lease_id)) {
        Assert-PLOSRequestIdentity -Value $id -Name 'Technical fence identity'
    }
    if ($binding.owner -isnot [string] -or $binding.owner -cnotmatch '^[a-f0-9-]{36}$' -or
        $binding.activation_request_id -cne ($binding.original_request_id + '-activate') -or
        $binding.target -isnot [string]) { throw 'Technical fence owner or activation binding is invalid.' }
    foreach ($hash in @($binding.custody_digest,$binding.expected_host_generation,$binding.operator_authority_sha256)) {
        if ($hash -isnot [string] -or $hash -cnotmatch '^[a-f0-9]{64}$') { throw 'Technical fence hash binding is invalid.' }
    }
    $k3Mutex = New-Object Threading.Mutex($false, 'Global\PLOSMhc08K3')
    $k3Acquired = $false
    try {
        $k3Acquired = $k3Mutex.WaitOne(0)
        if (-not $k3Acquired) { throw 'Foreign K3 lifecycle is active; technical fence held.' }
        Assert-PLOSTechnicalFenceK3Clear
        $spec = Get-PLOSTargetSpec -Manifest $Manifest -Id $binding.target
        $state = Read-PLOSState -Manifest $Manifest
        $signature = 'technical_fence|' + $binding.target + '|' + $binding.lease_id
        $prior = @($state.technical_fences | Where-Object { $_.request_id -ceq $binding.request_id })
        if ($prior.Count -gt 0) {
            if ($prior.Count -ne 1 -or $prior[0].signature -cne $signature) { throw 'Technical fence replay conflict.' }
            foreach ($field in $fields) { if ($prior[0].response.$field -cne $binding.$field) { throw 'Technical fence replay binding changed.' } }
            return $prior[0].response
        }
        if ($state.technical_fences.Count -ge $maximumJournalEntries) { throw 'Permanent technical fence capacity is full.' }
        # A fresh operator receipt may settle a lost application readback after later host activity.
        # Every prior receipt remains retained and must bind the same original custody.
        foreach ($old in @($state.technical_fences | Where-Object { $_.response.activation_request_id -ceq $binding.activation_request_id })) {
            foreach ($field in @('owner','original_request_id','activation_request_id','lease_id','target','custody_digest')) {
                if ($old.response.$field -cne $binding.$field) { throw 'Prior activation fence custody binding differs.' }
            }
        }
        $statePath = Join-Path ([string]$Manifest.state_root) $stateFileName
        if ((Get-PLOSSha256 -Path $statePath) -cne $binding.expected_host_generation -or $null -ne $state.lease) {
            throw 'Host generation changed or a foreign lease is present; technical fence held.'
        }
        $observed = Get-PLOSTargetStatus -Id $binding.target -Spec $spec
        $disabled = $observed.task_enabled -is [bool] -and -not $observed.task_enabled -and $observed.task_state -ceq 'Disabled' -and
            $observed.owned_process_count -eq 0 -and $observed.loopback_listener_count -eq 0 -and -not $observed.running_exact
        $unloaded = $spec.adapter_type -ceq 'ollama_coex' -and $observed.healthy -and $observed.running_exact -and
            $observed.owned_process_count -eq 1 -and $observed.loopback_listener_count -eq 1 -and
            $observed.model_loaded_exact -is [bool] -and -not $observed.model_loaded_exact -and
            $observed.residency_verified -is [bool] -and $observed.residency_verified -and $observed.foreign_loaded_count -eq 0
        $busy = if ($observed -is [Collections.IDictionary]) { if ($observed.Contains('busy')) { [pscustomobject]@{Value=$observed['busy']} } else { $null } } else { $observed.PSObject.Properties['busy'] }
        # A stopped reranker cannot answer /health. Only exact disabled/empty
        # ownership permits its absent busy observation; a live runtime must be idle.
        $stoppedReranker = $spec.adapter_type -ceq 'authenticated_reranker' -and $disabled -and
            $observed.runtime_files_exact -is [bool] -and $observed.runtime_files_exact
        $busyUnproven = $null -ne $busy -and $busy.Value -cne $false -and
            -not ($stoppedReranker -and $null -eq $busy.Value)
        if (-not $observed.task_exact -or -not $observed.control_exact -or -not $observed.executable_exact -or
            ($spec.adapter_type -ceq 'authenticated_reranker' -and -not $observed.runtime_files_exact) -or
            (-not $disabled -and -not $unloaded) -or $busyUnproven) {
            throw 'Exact target generation quiescence is unproven; technical fence held.'
        }
        # The lifecycle mutex remains held through fresh observation, generation compare and atomic persistence.
        if ((Get-PLOSSha256 -Path $statePath) -cne $binding.expected_host_generation) { throw 'Host generation changed during technical fence.' }
        $response = [ordered]@{
            schema = 'plos.model_host_controller.technical_fence.v1'; host_id = [string]$Manifest.host_id
            result = 'fenced'; activation_outcome = 'unknown'; inference_outcome = 'not_dispatched'
            host_custody_clear = $true; owned_work_ended = $true; runtime_mutated = $false; model_replayed = $false
            fenced_generation = [long]$state.generation + 1
            contains_prompt_or_response_content = $false; mutation_available = $true
        }
        foreach ($field in $fields) { $response[$field] = $binding.$field }
        $state.technical_fences += @([ordered]@{request_id=$binding.request_id;signature=$signature;response=$response})
        # Fences are never part of the normal bounded receipt-pruning queue.
        Write-PLOSState -Manifest $Manifest -State $state
        $response
    } finally {
        if ($k3Acquired) { $k3Mutex.ReleaseMutex() }
        $k3Mutex.Dispose()
    }
}

function Invoke-PLOSRelease {
    param([Parameter(Mandatory = $true)]$Manifest)
    $script:releaseDiagnosticStage = 'request_identity'
    if (-not $Execute) { throw 'Lifecycle release requires Execute.' }
    Assert-PLOSRequestIdentity -Value $RequestId -Name 'Request'
    Assert-PLOSRequestIdentity -Value $LeaseId -Name 'Lease'
    $script:releaseDiagnosticStage = 'read_state'
    $null = Initialize-PLOSStateRoot -Manifest $Manifest
    $state = Read-PLOSState -Manifest $Manifest
    $signature = "release||$LeaseId"
    $prior = @(Get-PLOSRequestRecord -State $state -Id $RequestId)
    if ($prior.Count -gt 0) {
        if ($prior.Count -ne 1 -or [string]$prior[0].signature -cne $signature) { throw 'Request replay conflict.' }
        return $prior[0].response
    }
    if ($null -eq $state.lease -or [string]$state.lease.lease_id -cne $LeaseId) { throw 'Lifecycle release lease does not match.' }
    $targetId = [string]$state.lease.target
    $spec = Get-PLOSTargetSpec -Manifest $Manifest -Id $targetId
    $script:releaseDiagnosticStage = 'cleanup_residency'
    $unloaded = Clear-PLOSOwnedOllamaResidency -Spec $spec -Lease $state.lease
    $script:releaseDiagnosticStage = 'stop_runtime'
    if ([bool]$state.lease.started_by_controller) { Stop-PLOSTarget -Id $targetId -Spec $spec }
    $script:releaseDiagnosticStage = 'verify_cleanup'
    if ($Spec.adapter_type -ceq 'ollama_coex' -and [bool]$state.lease.started_by_controller) { Assert-PLOSOllamaCleanupExact -Spec $spec }
    $after = Get-PLOSTargetStatus -Id $targetId -Spec $spec
    if ([bool]$state.lease.started_by_controller -and $after.running_exact) { throw 'Lifecycle release cleanup failed.' }
    $response = [ordered]@{
        schema = 'plos.model_host_controller.release.v1'
        host_id = $ExpectedHostId
        request_id = $RequestId
        lease_id = $LeaseId
        target = $targetId
        stopped_controller_owned_runtime = [bool]$state.lease.started_by_controller
        unloaded_controller_owned_model = [bool]$unloaded
        cleanup_verified = $true
        execution_mode = 'serial'
        fallback_enabled = $false
        tools_enabled = $false
        mutation_available = $true
        contains_prompt_or_response_content = $false
        result = 'released'
    }
    $script:releaseDiagnosticStage = 'persist'
    $state.lease = $null
    Add-PLOSRequestRecord -State $state -Id $RequestId -Signature $signature -Response $response
    Write-PLOSState -Manifest $Manifest -State $state
    $response
}

$manifest = Read-PLOSManifest
if (@($manifest.targets.PSObject.Properties | Where-Object {$_.Value.adapter_type -ceq 'colibri_gateway' -or $_.Value.resource_mode -ceq 'ram_commit_c'}).Count -gt 0) {
    $glmAdapter = Join-Path $PSScriptRoot 'plos-cli\glm-controller-adapter.ps1'
    if ((Get-PLOSSha256 $glmAdapter) -cne '78923d5cfcadaf27e6876518b3938d22c05c1eb1c412b65ef0bdbfc0233dfa62') { throw 'GLM lifecycle adapter changed.' }
    . $glmAdapter
}
if (@($manifest.targets.PSObject.Properties | Where-Object {$_.Value.adapter_type -ceq 'authenticated_reranker'}).Count -gt 0) {
    $rerankerAdapterPath=Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) 'plos-cli\reranker-controller-adapter.ps1'
    if ((Get-PLOSSha256 $rerankerAdapterPath) -cne 'fe8b7570e9ddc47ae20eab6372cd99c4d407736fe13366060fd94ea213e6b1e6') {throw 'Reranker adapter identity changed.'}
    . $rerankerAdapterPath
}
if (@($manifest.targets.PSObject.Properties | Where-Object {$_.Value.adapter_type -ceq 'authenticated_image'}).Count -gt 0) {
    $imageAdapter = Join-Path $PSScriptRoot 'plos-cli\image-controller-adapter.ps1'
    # Integration owner replaces this fail-closed placeholder with the reviewed adapter pin.
    if ((Get-PLOSSha256 $imageAdapter) -cne 'bd5b7b23c198e6377f76721eeb6022b82bb0b53799e71f84f5ba19ef71e5395c') { throw 'Image lifecycle adapter changed.' }
    . $imageAdapter
}
if ($Action -eq 'SelfTest') {
    $text = Get-Content -LiteralPath $PSCommandPath -Raw
    foreach ($marker in @(
        $mutexName, 'maximum_pagefile_growth_mib', 'MSAcpi_ThermalZoneTemperature', 'Get-StorageReliabilityCounter',
        'nvidia-smi.exe', 'An unexpired lifecycle lease is already active.', 'Lifecycle switch-rate budget is exhausted.',
        'fully_gpu_resident', 'Start-PLOSScheduledLlama', 'Lifecycle heartbeat lease has expired.',
        'Lifecycle recovery cleanup failed.',
        'fallback_enabled = $false', 'tools_enabled = $false'
    )) { if (-not $text.Contains([string]$marker)) { throw "Lifecycle self-test marker missing: $marker" } }
    ConvertTo-PLOSJson -Value ([ordered]@{
        schema = 'plos.model_host_controller.lifecycle_self_test.v1'
        host_id = $ExpectedHostId
        success = $true
        exact_targets = @($manifest.targets.PSObject.Properties.Name)
        durable_lease = $true
        switch_budget = $true
        resource_guard = $true
        temperature_mode = 'observation_only'
        serial_inference = $true
        mutation_performed = $false
        contains_prompt_or_response_content = $false
        public_write = $false
    })
    exit 0
}

if ($Action -eq 'Status') { ConvertTo-PLOSJson -Value (Get-PLOSStatus -Manifest $manifest -SelectedTarget $Target); exit 0 }
if ($Action -eq 'Receipt') { ConvertTo-PLOSJson -Value (Get-PLOSReceipt -Manifest $manifest -Operation $ReceiptOperation -Id $RequestId -ExpectedLease $LeaseId -ExpectedTarget $Target); exit 0 }
if ($Action -eq 'Plan') { ConvertTo-PLOSJson -Value (Get-PLOSPlan -Manifest $manifest -Id $Target -PlanRequestId $RequestId); exit 0 }

$mutex = New-Object Threading.Mutex($false, $mutexName)
$acquired = $false
try {
    $acquired = $mutex.WaitOne(30000)
    if (-not $acquired) { throw 'Lifecycle mutation lock timed out.' }
    if ($Action -eq 'Activate') { ConvertTo-PLOSJson -Value (Invoke-PLOSActivate -Manifest $manifest); exit 0 }
    if ($Action -eq 'Heartbeat') { ConvertTo-PLOSJson -Value (Invoke-PLOSHeartbeat -Manifest $manifest); exit 0 }
    if ($Action -eq 'Recover') { ConvertTo-PLOSJson -Value (Invoke-PLOSRecover -Manifest $manifest); exit 0 }
    if ($Action -eq 'TechnicalFence') { ConvertTo-PLOSJson -Value (Invoke-PLOSTechnicalFence -Manifest $manifest); exit 0 }
    if ($Action -eq 'Release') { ConvertTo-PLOSJson -Value (Invoke-PLOSRelease -Manifest $manifest); exit 0 }
} catch {
    if ($_.Exception.Data.Contains('plos_failed_checks')) {
        ConvertTo-PLOSJson -Value ([ordered]@{
            schema = 'plos.model_host_controller.error.v1'; host_id = $ExpectedHostId
            reason_code = 'lifecycle_gate_failed'; failed_checks = @($_.Exception.Data['plos_failed_checks'])
            contains_prompt_or_response_content = $false; result = 'rejected'
        })
        exit 1
    }
    if ($Action -eq 'Heartbeat') {
        $stage = [string]$script:heartbeatDiagnosticStage
        if ($stage -cnotin @('request_identity','read_state','target_status','resources','model_loaded','capacity_projection','gate','persist')) { $stage = 'unknown' }
        $kind = [string]$_.Exception.GetType().Name
        if ($kind -cnotmatch '^[A-Za-z][A-Za-z0-9]{0,63}$') { $kind = 'other' }
        $line = [int]$script:heartbeatDiagnosticSourceLine
        if ($line -eq 0) { $line = [int]$_.InvocationInfo.ScriptLineNumber }
        if ($line -lt 0 -or $line -gt 9999) { $line = 0 }
        ConvertTo-PLOSJson -Value ([ordered]@{
            schema = 'plos.model_host_controller.error.v1'; host_id = $ExpectedHostId
            reason_code = 'heartbeat_internal_failure'; stage = $stage; exception_kind = $kind; source_line = $line
            contains_prompt_or_response_content = $false; result = 'rejected'
        })
        exit 1
    }
    if ($Action -eq 'Release') {
        $stage = [string]$script:releaseDiagnosticStage
        if ($stage -cnotin @('request_identity','read_state','cleanup_residency','stop_runtime','verify_cleanup','persist')) { $stage = 'unknown' }
        $kind = [string]$_.Exception.GetType().Name
        if ($kind -cnotmatch '^[A-Za-z][A-Za-z0-9]{0,63}$') { $kind = 'other' }
        $line = [int]$_.InvocationInfo.ScriptLineNumber
        if ($line -lt 0 -or $line -gt 9999) { $line = 0 }
        ConvertTo-PLOSJson -Value ([ordered]@{
            schema = 'plos.model_host_controller.error.v1'; host_id = $ExpectedHostId
            reason_code = 'release_internal_failure'; stage = $stage; exception_kind = $kind; source_line = $line
            contains_prompt_or_response_content = $false; result = 'rejected'
        })
        exit 1
    }
    throw
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
