# MIT License; Copyright (c) 2026 PLOS Contributors.
# Reviewed native function excerpts. No top-level execution or host binding.
# See NOTICE.md and README.md for scope and Windows qualification.

function ConvertTo-PLOSJson {
    param([Parameter(Mandatory)]$Value)
    return ($Value | ConvertTo-Json -Depth 12 -Compress)
}

function Assert-PLOSExactProperties {
    param(
        [Parameter(Mandatory)]$Value,
        [Parameter(Mandatory)][string[]]$Allowed,
        [Parameter(Mandatory)][string]$Name
    )
    $actual = @($Value.PSObject.Properties.Name | Sort-Object)
    $expected = @($Allowed | Sort-Object)
    if (($actual -join ',') -ne ($expected -join ',')) {
        throw "$Name properties do not match the pinned schema."
    }
}

function Assert-PLOSSharedHostTargetPair {
    param($Left, $Right)
    $sameTask = [string]$Left.task_name -ieq [string]$Right.task_name
    $samePort = [int]$Left.port -eq [int]$Right.port
    if (-not $sameTask -and -not $samePort) { return }
    if (-not $samePort -or $Left.engine -cne $Right.engine -or $Left.engine -cnotin @('ollama','diffusers') -or
        $Left.model -ceq $Right.model) { throw 'Model-host task or port collision is not a shared exact engine.' }
    $fields=@('health_path','resource_group','runtime_revision')
    if ($Left.engine -ceq 'ollama') {
        if (-not $sameTask) { throw 'Shared Ollama models require one daemon task.' }
        $fields += 'engine_identity'
    } elseif ($sameTask) { throw 'Image models require distinct fixed-model tasks.' }
    foreach ($field in $fields) { if ([string]$Left.$field -cne [string]$Right.$field) { throw 'Shared model-host engine identity differs.' } }
    if ((@($Left.process_names | Sort-Object) -join ',') -cne (@($Right.process_names | Sort-Object) -join ',')) { throw 'Shared model-host process identities differ.' }
}

function Get-PLOSTarget {
    param([Parameter(Mandatory)]$Manifest, [Parameter(Mandatory)][string]$Id)
    if ($Id -notmatch '^[a-z0-9][a-z0-9_.:-]{2,63}$') {
        throw 'Requested model-host target identity is invalid.'
    }
    $property = $Manifest.targets.PSObject.Properties[$Id]
    if ($null -eq $property) {
        throw 'Requested model-host target is not allowlisted.'
    }
    return $property.Value
}

function Get-PLOSRuntimeStatus {
    param([Parameter(Mandatory)][string]$Id, [Parameter(Mandatory)]$Spec, $Observation = $null)
    if ($null -eq $Observation) {
        $Observation=@{task=(Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction SilentlyContinue)
            listeners=@(Get-NetTCPConnection -State Listen -LocalPort ([int]$Spec.port) -ErrorAction SilentlyContinue)}
    }
    $task=$Observation.task;$listeners=@($Observation.listeners)
    $loopbackListeners = @($listeners |
        Where-Object { [string]$_.LocalAddress -in @('127.0.0.1', '::1') })
    $externalListeners = @($listeners |
        Where-Object { [string]$_.LocalAddress -notin @('127.0.0.1', '::1') })
    return [ordered]@{
        target = $Id
        model = [string]$Spec.model
        engine = [string]$Spec.engine
        policy_state = [string]$Spec.state
        activation_allowed = [bool]$Spec.activation_allowed
        task_present = $null -ne $task
        task_state = if ($null -ne $task) { [string]$task.State } else { 'Missing' }
        listener_count = $listeners.Count
        loopback_listener_count = $loopbackListeners.Count
        external_listener_count = $externalListeners.Count
        loopback_only = $loopbackListeners.Count -gt 0 -and $externalListeners.Count -eq 0
        port = [int]$Spec.port
    }
}

function Get-PLOSStringSha256 {
    param([Parameter(Mandatory)][string]$Value)
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        $digest = $algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes($Value))
        return (($digest | ForEach-Object { $_.ToString('x2') }) -join '')
    } finally {
        $algorithm.Dispose()
    }
}

function Test-PLOSFixedTimeBytes {
    param(
        [Parameter(Mandatory)][byte[]]$Left,
        [Parameter(Mandatory)][byte[]]$Right
    )
    if ($Left.Length -ne $Right.Length) { return $false }
    [int]$difference = 0
    for ($index = 0; $index -lt $Left.Length; $index++) {
        $difference = $difference -bor ($Left[$index] -bxor $Right[$index])
    }
    return $difference -eq 0
}

function Assert-PLOSTokenFile {
    if (-not (Test-Path -LiteralPath $TokenPath -PathType Leaf)) {
        throw 'Model-host controller token file is missing.'
    }
    $item = Get-Item -LiteralPath $TokenPath -Force
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw 'Model-host controller token file cannot be a reparse point.'
    }
    $acl = Get-Acl -LiteralPath $TokenPath
    if (-not [bool]$acl.AreAccessRulesProtected -or
        [string]$acl.Owner -notin @('NT AUTHORITY\SYSTEM', 'S-1-5-18')) {
        throw 'Model-host controller token ACL must be explicit and owned.'
    }
    $allowed = @([string]$acl.Owner, 'NT AUTHORITY\SYSTEM', 'BUILTIN\Administrators', 'S-1-5-18', 'S-1-5-32-544')
    foreach ($rule in @($acl.Access)) {
        if ([string]$rule.AccessControlType -eq 'Allow' -and
            [string]$rule.IdentityReference -notin $allowed) {
            throw 'Model-host controller token ACL grants an unapproved principal.'
        }
    }
}

function Test-PLOSToken {
    param([Parameter(Mandatory)][AllowEmptyString()][string]$Authorization)
    try { Assert-PLOSTokenFile } catch { return $false }
    if ($Authorization -notmatch '^Bearer ([A-Za-z0-9._~-]{32,512})$') { return $false }
    $expected = (Get-Content -LiteralPath $TokenPath -Raw).Trim()
    $actual = [string]$Matches[1]
    if ($expected.Length -lt 32 -or $expected.Length -gt 512) { return $false }
    $left = [Text.Encoding]::UTF8.GetBytes($expected)
    $right = [Text.Encoding]::UTF8.GetBytes($actual)
    return Test-PLOSFixedTimeBytes -Left $left -Right $right
}
