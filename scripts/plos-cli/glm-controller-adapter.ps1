# GLM adapter for the existing serial model-host lifecycle. No actions on import.
function Test-PLOSGlmLifecycleResources {
    param($Resources, $Guards)
    foreach ($field in @('free_physical_bytes','commit_headroom_bytes','drive_c_free_bytes','pagefile_current_mib')) {
        if ($null -eq $Resources.$field -or [double]::IsNaN([double]$Resources.$field) -or
            [double]::IsInfinity([double]$Resources.$field) -or [double]$Resources.$field -lt 0) { return $false }
    }
    foreach ($field in @('minimum_commit_headroom_bytes','drive_c_free_bytes','maximum_pagefile_growth_mib')) {
        if ($null -eq $Guards.$field -or [double]::IsNaN([double]$Guards.$field) -or
            [double]::IsInfinity([double]$Guards.$field) -or [double]$Guards.$field -le 0) { return $false }
    }
    # Temperature is informational for GLM. The old absolute pagefile
    # ceiling and growth limit are for the other targets. GLM uses actual
    # available RAM/commit and C: reserve; Colibri is installed entirely on C:.
    # D: space is informational for GLM; paging growth remains telemetry.
    return [bool]([int64]$Resources.commit_headroom_bytes -ge [int64]$Guards.minimum_commit_headroom_bytes -and
        [int64]$Resources.drive_c_free_bytes -ge [int64]$Guards.drive_c_free_bytes -and
        (-not [bool]$Guards.balanced_power_required -or $Resources.balanced_power -eq $true))
}

function Get-PLOSGlmOwnedProcesses {
    param($Spec)
    @(Get-CimInstance Win32_Process -Filter "Name='colibri.exe'" -ErrorAction Stop | Where-Object {
        [string]$_.ExecutablePath -ceq [string]$Spec.executable_path
    })
}

# Windows command lines use double quotes. Require exactly one port option;
# accepting a matching first occurrence alone could hide a later override.
function Test-PLOSGlmGatewayPort {
    param([string]$CommandLine,[int]$Port)
    $options=[regex]::Matches($CommandLine,'(?:^|\s)(?:--port(?=\s|=|$)|"--port"(?=\s|=|$)|"--port=)')
    $values=[regex]::Matches($CommandLine,'(?:^|\s)(?:"--port=(?<port>[0-9]+)"|(?:--port|"--port")(?:\s+|=)(?:"(?<port>[0-9]+)"|(?<port>[0-9]+)))(?=\s|$)')
    return [bool]($options.Count -eq 1 -and $values.Count -eq 1 -and
        $values[0].Groups['port'].Value -ceq [string]$Port)
}

function Get-PLOSGlmGatewayProcesses {
    param($Spec)
    $runtime = [IO.Path]::GetDirectoryName([string]$Spec.gateway_path)
    @(Get-CimInstance Win32_Process -Filter "Name='python.exe'" -ErrorAction Stop | Where-Object {
        $_.CommandLine -and ($_.CommandLine.Contains((Join-Path $runtime 'coli')) -or
            $_.CommandLine.Contains([string]$Spec.gateway_path)) -and
        $_.CommandLine.Contains([string]$Spec.model_path) -and
        (Test-PLOSGlmGatewayPort ([string]$_.CommandLine) ([int]$Spec.port))
    })
}

function Get-PLOSGlmHealth {
    param($Spec)
    $token = $null
    $probeStarted = $false
    try {
        $token = [IO.File]::ReadAllText([string]$Spec.api_key_path).Trim()
        if ([string]::IsNullOrWhiteSpace($token) -or $token -match '[\x00-\x20\x7f]') { throw 'Invalid GLM credential.' }
        $probeStarted = $true
        $health = Invoke-RestMethod -Uri "http://127.0.0.1:$([int]$Spec.port)/health" -TimeoutSec 5 -Headers @{Authorization=('Bearer '+$token)}
        $runtime = $health.plos_cli_runtime
        $expectedTools = if ($null -eq $Spec.PSObject.Properties['native_tools_enabled']) { $true } else { $Spec.native_tools_enabled }
        if ($expectedTools -isnot [bool]) { throw 'Invalid GLM tool capability binding.' }
        return [ordered]@{observed=$true;healthy=([string]$health.status -ceq 'ok');model_exact=([string]$runtime.model -ceq [string]$Spec.model_api_id);
            gateway_exact=([string]$runtime.schema -ceq 'plos.cli.colibri-live.v1' -and
                [string]$runtime.gateway_sha256 -ceq [string]$Spec.gateway_sha256 -and
                [string]$runtime.engine_sha256 -ceq [string]$Spec.executable_sha256 -and
                $runtime.lossless_output -eq $true -and $runtime.raw_output -eq $true -and ($runtime.native_tools_enabled -is [bool] -and $runtime.native_tools_enabled -eq $expectedTools))}
    } catch {
        $timedOut = ($probeStarted -and $_.Exception -is [Net.WebException] -and
            $_.Exception.Status -eq [Net.WebExceptionStatus]::Timeout)
        return [ordered]@{observed=(-not $timedOut);healthy=$false;model_exact=$false;gateway_exact=$false}
    } finally { $token = $null }
}

function Test-PLOSGlmTaskIdentity {
    param($Task, $Spec)
    $expectedLogon = 'Interactive'
    if ($null -ne $Spec.PSObject.Properties['task_logon_type']) {
        if ($Spec.task_logon_type -isnot [string] -or $Spec.task_logon_type -cnotin @('Interactive','S4U')) { return $false }
        $expectedLogon = $Spec.task_logon_type
    }
    return [bool]($null -ne $Task -and @($Task.Actions).Count -eq 1 -and
        [string]$Task.Actions[0].Execute -match '(?i)(?:^|\\)powershell\.exe$' -and
        [string]$Task.Actions[0].Arguments -ceq [string]$Spec.task_arguments -and
        [string]$Task.Actions[0].WorkingDirectory -ceq 'C:\PLOS\Colibri' -and
        [string]$Task.Principal.UserId -ceq [string]$Spec.task_user -and
        [string]$Task.Principal.LogonType -ceq $expectedLogon -and
        [string]$Task.Principal.RunLevel -ceq 'Highest' -and @($Task.Triggers | Where-Object {$null -ne $_}).Count -eq 0)
}

function Get-PLOSGlmTargetStatus {
    param($Spec, [switch]$AllowBusyGateway)
    $task = Get-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction SilentlyContinue
    $taskExact = Test-PLOSGlmTaskIdentity -Task $task -Spec $Spec
    $controlExact=$false;$executableExact=$false;$gatewayExact=$false
    try {
        $controlExact=(Get-PLOSSha256 ([string]$Spec.control_path)) -ceq [string]$Spec.control_sha256
        $executableExact=(Get-PLOSSha256 ([string]$Spec.executable_path)) -ceq [string]$Spec.executable_sha256
        $gatewayExact=(Get-PLOSSha256 ([string]$Spec.gateway_path)) -ceq [string]$Spec.gateway_sha256 -and
            (Get-PLOSSha256 ([string]$Spec.runtime_receipt_path)) -ceq [string]$Spec.runtime_receipt_sha256
    } catch {}
    $owned=@(Get-PLOSGlmOwnedProcesses $Spec);$gateways=@(Get-PLOSGlmGatewayProcesses $Spec)
    $listeners=@(Get-NetTCPConnection -State Listen -LocalPort ([int]$Spec.port) -ErrorAction SilentlyContinue)
    $loopback=@($listeners|Where-Object{[string]$_.LocalAddress -in @('127.0.0.1','::1')})
    $external=@($listeners|Where-Object{[string]$_.LocalAddress -notin @('127.0.0.1','::1')})
    $health=Get-PLOSGlmHealth $Spec
    $listenerOwned=($gateways.Count -eq 1 -and $loopback.Count -eq 1 -and [int]$loopback[0].OwningProcess -eq [int]$gateways[0].ProcessId)
    # An owned, in-flight GLM request can hold the gateway's Python worker.
    # Heartbeat alone may use independently pinned task/process/port identity
    # when the health probe has no response. An explicit negative health reply
    # still blocks renewal. Cold start and ordinary status stay strict.
    $identityExact=($taskExact -and $controlExact -and $executableExact -and $gatewayExact -and $owned.Count -eq 1 -and $listenerOwned)
    $healthExact=($health.healthy -and $health.model_exact -and $health.gateway_exact)
    $busyUnobserved=($AllowBusyGateway -and -not $health.observed)
    [ordered]@{task_present=($null -ne $task);task_exact=$taskExact;
        task_enabled=($null -ne $task -and [bool]$task.Settings.Enabled);
        task_state=$(if($null -ne $task){[string]$task.State}else{'Missing'});
        control_exact=$controlExact;executable_exact=$executableExact;runtime_files_exact=$gatewayExact;gateway_exact=($gatewayExact -and $health.gateway_exact);
        owned_process_count=$owned.Count;gateway_process_count=$gateways.Count;loopback_listener_count=$loopback.Count;
        external_proxy_listener_count=$external.Count;healthy=[bool]$health.healthy;model_exact=[bool]$health.model_exact;
        fully_gpu_resident=$null;running_exact=($identityExact -and ($healthExact -or $busyUnobserved))}
}

function Stop-PLOSGlmTarget {
    param($Spec)
    $status=Get-PLOSGlmTargetStatus $Spec
    if(-not $status.task_exact -or -not $status.control_exact -or -not $status.executable_exact){throw 'GLM stop ownership changed.'}
    $parents=@(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'"|Where-Object{
        $_.CommandLine -and $_.CommandLine.Contains([string]$Spec.control_path) -and $_.CommandLine -match '-Service\s+colibri(?:\s|$)'
    })
    if($parents.Count -gt 1){throw 'Ambiguous GLM launcher ownership.'}
    Disable-ScheduledTask -TaskName ([string]$Spec.task_name)|Out-Null
    foreach($parent in $parents){& taskkill.exe /PID ([string]$parent.ProcessId) /T /F 2>$null|Out-Null}
    Stop-ScheduledTask -TaskName ([string]$Spec.task_name) -ErrorAction Stop
    foreach($process in @(Get-PLOSGlmGatewayProcesses $Spec)+@(Get-PLOSGlmOwnedProcesses $Spec)){Stop-Process -Id ([int]$process.ProcessId) -Force -ErrorAction Stop}
    $deadline=[DateTimeOffset]::UtcNow.AddSeconds(15)
    do {
        $remaining=@(Get-PLOSGlmOwnedProcesses $Spec).Count + @(Get-PLOSGlmGatewayProcesses $Spec).Count
        if($remaining -eq 0){return}
        Start-Sleep -Milliseconds 200
    } while([DateTimeOffset]::UtcNow -lt $deadline)
    throw 'Owned GLM processes did not stop.'
}

function Start-PLOSGlmTarget {
    param($Spec)
    $before=Get-PLOSGlmTargetStatus $Spec
    if(-not $before.task_exact -or -not $before.control_exact -or -not $before.executable_exact -or -not $before.runtime_files_exact){throw 'GLM start identity changed.'}
    if($before.running_exact){return}
    if($before.owned_process_count -ne 0 -or $before.gateway_process_count -ne 0 -or $before.loopback_listener_count -ne 0){throw 'GLM endpoint or process already has an unresolved owner.'}
    $clock=[Diagnostics.Stopwatch]::StartNew();$failureCode='startup_error'
    try {
        Enable-ScheduledTask -TaskName ([string]$Spec.task_name)|Out-Null
        Start-ScheduledTask -TaskName ([string]$Spec.task_name)
        $deadline=[DateTimeOffset]::UtcNow.AddSeconds([int]$Spec.startup_timeout_seconds)
        do {
            $status=Get-PLOSGlmTargetStatus $Spec
            if($status.running_exact){return}
            if($status.task_state -notin @('Running','Queued')){$failureCode='task_exited';throw 'GLM launcher exited before readiness.'}
            Start-Sleep -Seconds 2
        } while([DateTimeOffset]::UtcNow -lt $deadline)
        $failureCode='readiness_timeout';throw 'GLM did not become ready within its startup limit.'
    } catch {
        Stop-PLOSGlmTarget $Spec
        $clean=Get-PLOSGlmTargetStatus $Spec
        if(-not $clean.task_exact -or -not $clean.control_exact -or -not $clean.executable_exact -or
            -not $clean.runtime_files_exact -or $clean.task_enabled -or $clean.task_state -cne 'Disabled' -or
            $clean.owned_process_count -ne 0 -or $clean.gateway_process_count -ne 0 -or
            $clean.loopback_listener_count -ne 0){throw 'GLM startup cleanup is unverified.'}
        $error=New-Object InvalidOperationException('GLM startup failed; owned cleanup verified.')
        $error.Data['plos_startup_failure']=[ordered]@{phase='readiness';code=$failureCode;
            elapsed_seconds=$clock.Elapsed.TotalSeconds;cleanup_verified=$true}
        throw $error
    } finally { $clock.Stop() }
}
