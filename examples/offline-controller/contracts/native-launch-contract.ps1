# MIT License; Copyright (c) 2026 PLOS Contributors.
# Reviewed native function excerpts. No top-level execution or host binding.
# See NOTICE.md and README.md for scope and Windows qualification.

function Read-PLOSNativeLaunch {
    param([string]$Path, [string]$Sha256)
    if ($Sha256 -cnotmatch '^[a-f0-9]{64}$' -or
        (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $Sha256) {
        throw 'Native launch projection identity mismatch.'
    }
    $launch = Get-Content -Raw -LiteralPath $Path | ConvertFrom-Json
    if ($launch.schema -cne 'plos.native_llama_launch.v1') { throw 'Unsupported native launch projection.' }
    foreach ($name in @('executable_path','model_path','working_directory','stdout_path','stderr_path')) {
        $value = $launch.$name
        if ($value -isnot [string] -or $value -cnotmatch '^[A-Za-z]:\\' -or $value -match '[\x00-\x1f"]' -or
            @($value -split '\\' | Where-Object { $_ -eq '..' }).Count -gt 0) { throw 'Invalid native launch path.' }
    }
    if ($launch.stdout_path -ceq $launch.stderr_path) { throw 'Native output paths must differ.' }
    foreach ($name in @('executable','model')) {
        $expected = $launch.($name+'_sha256')
        if ($expected -isnot [string] -or $expected -cnotmatch '^[a-f0-9]{64}$' -or
            (Get-FileHash -LiteralPath $launch.($name+'_path') -Algorithm SHA256).Hash.ToLowerInvariant() -cne $expected) {
            throw 'Native executable or model identity mismatch.'
        }
    }
    # Optional table-owned runtime inventory, pinned by the launch projection hash.
    # Preserve library verification when migrating an existing model-specific launcher.
    if ($null -ne $launch.PSObject.Properties['runtime_files']) {
        if ($launch.runtime_files -isnot [pscustomobject] -or
            @($launch.runtime_files.PSObject.Properties).Count -eq 0) {
            throw 'Native runtime file inventory must be a non-empty exact path/hash map.'
        }
        foreach ($entry in @($launch.runtime_files.PSObject.Properties)) {
            $path = [string]$entry.Name
            $expected = $entry.Value
            if ($path -cnotmatch '^[A-Za-z]:\\' -or $path -match '[\x00-\x1f"]' -or
                @($path -split '\\' | Where-Object { $_ -eq '..' }).Count -gt 0 -or
                $expected -isnot [string] -or $expected -cnotmatch '^[a-f0-9]{64}$' -or
                (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $expected) {
                throw 'Native runtime file identity mismatch.'
            }
        }
    }
    if ($launch.arguments -isnot [array] -or $launch.arguments.Count -eq 0) { throw 'Native arguments must be explicit.' }
    foreach ($arg in $launch.arguments) {
        if ($arg -isnot [string] -or $arg.Length -eq 0 -or $arg -match '[\x00-\x1f"]') { throw 'Invalid native argument.' }
    }
    $modelArgs = @($launch.arguments | Where-Object { $_ -ceq '--model' })
    $modelIndex = [Array]::IndexOf($launch.arguments, '--model')
    if ($modelArgs.Count -ne 1 -or $modelIndex -ge ($launch.arguments.Count - 1) -or
        $launch.arguments[$modelIndex + 1] -cne $launch.model_path) { throw 'Native model argument differs from its pinned artifact.' }
    $launch
}

function ConvertTo-PLOSNativeArguments {
    param([string[]]$Arguments)
    @($Arguments | ForEach-Object { '"' + ($_ -replace '(\\+)$','$1$1') + '"' }) -join ' '
}
