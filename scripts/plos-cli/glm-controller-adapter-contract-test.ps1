param([string]$AdapterPath = (Join-Path $PSScriptRoot 'glm-controller-adapter.ps1'))
$ErrorActionPreference='Stop'
. $AdapterPath
$spec=[pscustomobject]@{task_arguments='-File "C:\PLOS\Colibri\scripts\fixture.ps1"';task_user='fixture-principal';task_logon_type='S4U'}
function New-FixtureTask {
    param([string]$Logon='S4U')
    [pscustomobject]@{Actions=@([pscustomobject]@{Execute='C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe';Arguments=$spec.task_arguments;WorkingDirectory='C:\PLOS\Colibri'});Principal=[pscustomobject]@{UserId=$spec.task_user;LogonType=$Logon;RunLevel='Highest'};Triggers=@()}
}
$checks=0
function Assert-Contract {
    param([bool]$Actual,[bool]$Expected,[string]$Name)
    if($Actual -ne $Expected){throw "Contract failed: $Name"};$script:checks++
}
Assert-Contract (Test-PLOSGlmTaskIdentity (New-FixtureTask) $spec) $true 'declared S4U exact'
Assert-Contract (Test-PLOSGlmTaskIdentity (New-FixtureTask 'Interactive') $spec) $false 'S4U rejects Interactive'
$legacy=[pscustomobject]@{task_arguments=$spec.task_arguments;task_user=$spec.task_user}
Assert-Contract (Test-PLOSGlmTaskIdentity (New-FixtureTask 'Interactive') $legacy) $true 'legacy Interactive exact'
Assert-Contract (Test-PLOSGlmTaskIdentity (New-FixtureTask) $legacy) $false 'legacy refuses undeclared S4U'
foreach($type in @('ServiceAccount','Password','s4u','')){$bad=$spec.PSObject.Copy();$bad.task_logon_type=$type;Assert-Contract (Test-PLOSGlmTaskIdentity (New-FixtureTask) $bad) $false 'invalid declaration'}
foreach($field in @('UserId','RunLevel')){$bad=New-FixtureTask;$bad.Principal.$field='changed';Assert-Contract (Test-PLOSGlmTaskIdentity $bad $spec) $false $field}
foreach($field in @('Execute','Arguments','WorkingDirectory')){$bad=New-FixtureTask;$bad.Actions[0].$field='changed';Assert-Contract (Test-PLOSGlmTaskIdentity $bad $spec) $false $field}
$bad=New-FixtureTask;$bad.Triggers=@([pscustomobject]@{Enabled=$true});Assert-Contract (Test-PLOSGlmTaskIdentity $bad $spec) $false 'unexpected trigger'
Assert-Contract (Test-PLOSGlmTaskIdentity $null $spec) $false 'missing task'
[ordered]@{passed=$checks;model_calls=0;native_writes=0}|ConvertTo-Json -Compress
