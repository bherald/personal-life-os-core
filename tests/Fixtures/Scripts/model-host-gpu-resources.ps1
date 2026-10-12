param([Parameter(Mandatory = $true)][string]$SourcePath)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$tokens = $null; $errors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($SourcePath, [ref]$tokens, [ref]$errors)
if ($errors.Count -ne 0) { throw 'Source syntax invalid' }
foreach ($name in @('ConvertTo-PLOSObservedTemperature', 'Get-PLOSResources')) {
    $functions = @($ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name }, $true))
    if ($functions.Count -ne 1) { throw 'Exact source function missing' }
    . ([scriptblock]::Create($functions[0].Extent.Text))
}
# Every OS/driver boundary is fake; no script entrypoint or host action is executed.
function Get-CimInstance {
    param($ClassName, $Namespace, $Filter, $ErrorAction)
    switch ($ClassName) {
        'Win32_OperatingSystem' { [pscustomobject]@{ FreeVirtualMemory = 33554432; FreePhysicalMemory = 16777216 } }
        'Win32_PageFileUsage' { [pscustomobject]@{ CurrentUsage = 0; AllocatedBaseSize = 0 } }
        'Win32_LogicalDisk' { @([pscustomobject]@{ DeviceID = 'C:'; FreeSpace = 200GB }, [pscustomobject]@{ DeviceID = 'D:'; FreeSpace = 200GB }) }
        'MSAcpi_ThermalZoneTemperature' { @() }
        default { throw 'Unexpected OS probe' }
    }
}
function Get-PhysicalDisk { param($ErrorAction) @() }
function nvidia-smi.exe { $global:LASTEXITCODE = $script:exitCode; $script:query = $args; $script:rows }
function powercfg.exe { '381b4222-f694-41f0-9685-ff5bb260df2e' }
$manifest = @{ resource_guards = @{ minimum_commit_headroom_bytes = 8GB; drive_c_free_bytes = 1GB; drive_d_free_bytes = 1GB; maximum_pagefile_current_mib = 1024; balanced_power_required = $true } }
$cases = @(
    @{ name = 'reported_differs'; rows = @('42, 1142, 8188, 6807'); exit = 0 },
    @{ name = 'optional_temperature_missing'; rows = @('[N/A], 1142, 8188, 6807'); exit = 0 },
    @{ name = 'zero_free'; rows = @('42, 8188, 8188, 0'); exit = 0 },
    @{ name = 'missing_free'; rows = @('42, 1142, 8188, N/A'); exit = 0 },
    @{ name = 'nan_free'; rows = @('42, 1142, 8188, NaN'); exit = 0 },
    @{ name = 'infinite_free'; rows = @('42, 1142, 8188, Infinity'); exit = 0 },
    @{ name = 'negative_free'; rows = @('42, 1142, 8188, -1'); exit = 0 },
    @{ name = 'free_exceeds_total'; rows = @('42, 1142, 8188, 8189'); exit = 0 },
    @{ name = 'inconsistent_sum'; rows = @('42, 1142, 8188, 7500'); exit = 0 },
    @{ name = 'used_exceeds_total'; rows = @('42, 8189, 8188, 6807'); exit = 0 },
    @{ name = 'used_negative'; rows = @('42, -1, 8188, 6807'); exit = 0 },
    @{ name = 'missing_total'; rows = @('42, 1142, N/A, 6807'); exit = 0 },
    @{ name = 'fractional_free'; rows = @('42, 1142, 8188, 6807.5'); exit = 0 },
    @{ name = 'byte_overflow'; rows = @('42, 1142, 9223372036854775807, 6807'); exit = 0 },
    @{ name = 'missing_column'; rows = @('42, 1142, 8188'); exit = 0 },
    @{ name = 'multiple_gpus'; rows = @('42, 1142, 8188, 6807', '43, 100, 8188, 8088'); exit = 0 },
    @{ name = 'driver_failure'; rows = @('42, 1142, 8188, 6807'); exit = 1 }
)
$output = foreach ($case in $cases) {
    $script:rows = $case.rows; $script:exitCode = $case.exit; $script:query = @()
    $resources = Get-PLOSResources -Manifest $manifest
    [ordered]@{ name = $case.name; query = $script:query; resources = $resources }
}
ConvertTo-Json -InputObject @($output) -Depth 6 -Compress
