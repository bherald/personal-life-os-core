# Image adapter for the existing serial lifecycle. Import defines functions only.
function Get-PLOSImageProcessOwnership {
    param($Spec)
    $gateway=[regex]::Escape([string]$Spec.gateway_path)
    $receipt=[regex]::Escape([string]$Spec.runtime_receipt_path)
    $all=@(Get-CimInstance Win32_Process -Filter "Name='python.exe'" -ErrorAction Stop)
    $matching=@($all | Where-Object {
        [string]$_.CommandLine -cmatch ('(?:^|\s)"?'+$gateway+'"?\s+--controller-receipt\s+"?'+$receipt+'"?\s*$')
    })
    $parents=@($matching|Where-Object{[string]$_.ExecutablePath -ceq [string]$Spec.executable_path})
    $children=@($matching|Where-Object{[string]$_.ExecutablePath -ceq [string]$Spec.interpreter_path})
    $exact=($matching.Count -eq 2 -and $parents.Count -eq 1 -and $children.Count -eq 1)
    if($exact){
        $exact=([int]$children[0].ParentProcessId -eq [int]$parents[0].ProcessId -and
            @($all|Where-Object{[int]$_.ParentProcessId -eq [int]$parents[0].ProcessId}).Count -eq 1)
    }
    return @{processes=$matching;exact=[bool]$exact;listener_pid=$(if($exact){[int]$children[0].ProcessId}else{0})}
}

function Get-PLOSImageOwnedProcesses {
    param($Spec)
    @((Get-PLOSImageProcessOwnership $Spec).processes)
}

function Get-PLOSImageHealth {
    param($Spec)
    try {
        $token=[IO.File]::ReadAllText([string]$Spec.api_key_path).Trim()
        if([string]::IsNullOrWhiteSpace($token) -or $token -match '[\x00-\x20\x7f]'){throw 'Invalid image credential.'}
        $h=Invoke-RestMethod -Uri "http://127.0.0.1:$([int]$Spec.port)/health" -TimeoutSec 5 -Headers @{Authorization=('Bearer '+$token)}
        $c=$h.controller
        $exact=($h.ok -eq $true -and $h.auth_required -eq $true -and
            [string]$c.schema -ceq 'plos.image_controller_runtime.v1' -and
            [string]$c.model -ceq [string]$Spec.model_api_id -and
            [string]$c.receipt_sha256 -ceq [string]$Spec.runtime_receipt_sha256 -and
            [string]$c.gateway_sha256 -ceq [string]$Spec.gateway_sha256 -and $c.offline_only -eq $true -and
            $c.busy -is [bool])
        return @{healthy=$exact;model_exact=$exact;loaded=($exact -and [string]$h.active_model -ceq [string]$Spec.model_api_id);busy=$(if($exact){[bool]$c.busy}else{$null})}
    } catch {return @{healthy=$false;model_exact=$false;loaded=$false;busy=$null}}
    finally {$token=$null}
}

function Get-PLOSImageTargetStatus {
    param($Spec)
    $task=Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction SilentlyContinue
    $principal=if($null -ne $task){[string]$task.Principal.UserId}else{''}
    $principalExact=if([string]$Spec.task_user -eq 'SYSTEM'){$principal -in @('SYSTEM','S-1-5-18','NT AUTHORITY\SYSTEM')}else{$principal -ieq [string]$Spec.task_user}
    $taskExact=($null -ne $task -and @($task.Actions).Count -eq 1 -and
        [string]$task.Actions[0].Execute -ceq [string]$Spec.executable_path -and
        [string]$task.Actions[0].Arguments -ceq [string]$Spec.task_arguments -and
        [string]$task.Actions[0].WorkingDirectory -ceq [IO.Path]::GetDirectoryName([string]$Spec.gateway_path) -and
        @($task.Triggers | Where-Object {$null -ne $_}).Count -eq 0 -and $principalExact)
    $controlExact=$false;$executableExact=$false;$runtimeExact=$false
    try {
        $controlExact=(Get-PLOSSha256 ([string]$Spec.control_path)) -ceq [string]$Spec.control_sha256
        $executableExact=((Get-PLOSSha256 ([string]$Spec.executable_path)) -ceq [string]$Spec.executable_sha256 -and
            (Get-PLOSSha256 ([string]$Spec.interpreter_path)) -ceq [string]$Spec.interpreter_sha256)
        $runtimeExact=(Get-PLOSSha256 ([string]$Spec.gateway_path)) -ceq [string]$Spec.gateway_sha256 -and
            (Get-PLOSSha256 ([string]$Spec.runtime_receipt_path)) -ceq [string]$Spec.runtime_receipt_sha256
    } catch {}
    $ownership=Get-PLOSImageProcessOwnership $Spec;$owned=@($ownership.processes)
    $listeners=@(Get-NetTCPConnection -State Listen -LocalPort ([int]$Spec.port) -ErrorAction SilentlyContinue)
    $loopback=@($listeners|Where-Object{[string]$_.LocalAddress -eq '127.0.0.1'})
    $external=@($listeners|Where-Object{[string]$_.LocalAddress -ne '127.0.0.1'})
    $health=Get-PLOSImageHealth $Spec
    $listenerExact=($ownership.exact -and $loopback.Count -eq 1 -and [int]$loopback[0].OwningProcess -eq [int]$ownership.listener_pid)
    $running=($taskExact -and $controlExact -and $executableExact -and $runtimeExact -and $listenerExact -and $health.healthy)
    return [ordered]@{task_present=($null -ne $task);task_exact=[bool]$taskExact;task_enabled=($null -ne $task -and [bool]$task.Settings.Enabled);
        task_state=$(if($null -ne $task){[string]$task.State}else{'Missing'});control_exact=$controlExact;executable_exact=$executableExact;
        runtime_files_exact=$runtimeExact;owned_process_count=$owned.Count;loopback_listener_count=$loopback.Count;
        external_proxy_listener_count=$external.Count;healthy=[bool]$health.healthy;model_exact=[bool]$health.model_exact;
        model_loaded_exact=($running -and $health.loaded);fully_gpu_resident=$null;running_exact=[bool]$running;busy=$health.busy}
}

function Start-PLOSImageTarget {
    param($Spec)
    $before=Get-PLOSImageTargetStatus $Spec
    if(-not $before.task_exact -or -not $before.control_exact -or -not $before.executable_exact -or -not $before.runtime_files_exact){throw 'Image start identity changed.'}
    if($before.running_exact){return}
    if($before.owned_process_count -ne 0 -or $before.loopback_listener_count -ne 0){throw 'Image endpoint ownership is unresolved.'}
    Enable-ScheduledTask -TaskName ([string]$Spec.task_name)|Out-Null
    Start-ScheduledTask -TaskName ([string]$Spec.task_name)
    $deadline=[DateTimeOffset]::UtcNow.AddSeconds([int]$Spec.startup_timeout_seconds)
    do {
        $status=Get-PLOSImageTargetStatus $Spec
        if($status.running_exact){return}
        if($status.task_state -notin @('Running','Queued')){throw 'Image launcher exited before readiness; reconciliation required.'}
        Start-Sleep -Milliseconds 500
    }while([DateTimeOffset]::UtcNow -lt $deadline)
    throw 'Image startup acknowledgement unavailable; reconciliation required.'
}

function Stop-PLOSImageTarget {
    param($Spec)
    $s=Get-PLOSImageTargetStatus $Spec
    if(-not $s.task_exact -or -not $s.control_exact -or -not $s.executable_exact -or -not $s.runtime_files_exact){throw 'Image stop identity changed.'}
    if($s.owned_process_count -eq 0 -and $s.loopback_listener_count -eq 0){Disable-ScheduledTask -TaskName ([string]$Spec.task_name)|Out-Null;return}
    if(-not $s.running_exact -or $s.busy -ne $false){throw 'Image work is busy or unknown; keep ownership held.'}
    $ownership=Get-PLOSImageProcessOwnership $Spec;$owned=@($ownership.processes)
    if(-not$ownership.exact -or $owned.Count -ne 2){throw 'Image process ownership changed.'}
    $ownedIds=@($owned|ForEach-Object{[int]$_.ProcessId})
    Disable-ScheduledTask -TaskName ([string]$Spec.task_name)|Out-Null
    Stop-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction Stop
    $deadline=[DateTimeOffset]::UtcNow.AddSeconds(15)
    do {
        if(@(Get-CimInstance Win32_Process -Filter "Name='python.exe'" -ErrorAction Stop|Where-Object{[int]$_.ProcessId -in $ownedIds}).Count -eq 0 -and @(Get-PLOSImageOwnedProcesses $Spec).Count -eq 0 -and @(Get-NetTCPConnection -State Listen -LocalPort ([int]$Spec.port) -ErrorAction SilentlyContinue|Where-Object{[string]$_.LocalAddress -eq '127.0.0.1'}).Count -eq 0){return}
        Start-Sleep -Milliseconds 200
    }while([DateTimeOffset]::UtcNow -lt $deadline)
    throw 'Image process cleanup is unverified; keep ownership held.'
}
