param([Parameter(Mandatory)][string]$SourcePath,[Parameter(Mandatory)][string]$ServicePath)
$ErrorActionPreference='Stop'
function Import-ExactFunction($Path,$Names) {
    $tokens=$null;$errors=$null
    $ast=[System.Management.Automation.Language.Parser]::ParseFile($Path,[ref]$tokens,[ref]$errors)
    if ($errors.Count -ne 0) { throw 'Source syntax invalid' }
    foreach ($name in $Names) {
        $found=@($ast.FindAll({param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name},$true))
        if ($found.Count -ne 1) { throw 'Exact source function missing' }
        . ([scriptblock]::Create($found[0].Extent.Text))
    }
}
# Dot-source the importer so extracted definitions survive in the fixture scope.
. Import-ExactFunction $SourcePath @('Get-PLOSReadiness','Test-PLOSFixedTimeBytes','Assert-PLOSTokenFile','Test-PLOSToken')
. Import-ExactFunction $ServicePath @('Get-PLOSAuthenticatedStatus','Get-PLOSStatus')
$expectedLifecycleScriptSha256='a'*64;$expectedLifecycleManifestSha256='b'*64
$expectedMhc08ScriptSha256='c'*64;$expectedMhc08ManifestSha256='d'*64
$manifest=@{revision='fixture-v1';controller=@{install_root='/fixture'}}
$pinPaths=@('scripts/plos-model-host-controller-lifecycle.ps1','config/model-host-controller-lifecycle.87.json','scripts/plos-model-host-controller-mhc08-k3.ps1','config/model-host-controller-mhc08-k3.87.json')
$pinValues=@($expectedLifecycleScriptSha256,$expectedLifecycleManifestSha256,$expectedMhc08ScriptSha256,$expectedMhc08ManifestSha256)
$script:badPin=-1;$script:missingPin=-1;$script:hashCalls=0;$script:forbiddenCalls=0
function Get-FileHash {param($LiteralPath,$Algorithm,$ErrorAction)
 $script:hashCalls++;$path=([string]$LiteralPath).Replace('\','/');$i=-1
 for($j=0;$j -lt 4;$j++){if($path.EndsWith($pinPaths[$j])){$i=$j}}
 if($i -lt 0){throw 'Unexpected hash path'};if($i -eq $script:missingPin){throw 'Missing control file'}
 [pscustomobject]@{Hash=if($i -eq $script:badPin){'e'*64}else{$pinValues[$i]}}
}
function Get-CimInstance {$script:forbiddenCalls++;throw 'Forbidden CIM'}
function Start-Process {$script:forbiddenCalls++;throw 'Forbidden child'}
function Invoke-PLOSLifecycle {$script:forbiddenCalls++;throw 'Forbidden lifecycle'}
function Invoke-PLOSMhc08 {$script:forbiddenCalls++;throw 'Forbidden model'}
function Get-PLOSTarget {$script:forbiddenCalls++;throw 'Forbidden catalog'}
function Assert-PLOSK3LeaseFree {$script:forbiddenCalls++;throw 'Forbidden lease'}
$tokenPath='/fixture/token';$manifestPath='/fixture/manifest';$taskName='fixture-task';$Action='Start'
$script:tokenMissing=$false;$script:tokenReparse=$false;$script:unsafeAcl=$false
function Test-Path {param($LiteralPath,$PathType) -not $script:tokenMissing}
function Get-Item {param($LiteralPath,[switch]$Force) [pscustomobject]@{Attributes=if($script:tokenReparse){[IO.FileAttributes]::ReparsePoint}else{[IO.FileAttributes]::Normal}}}
function Get-Acl {param($LiteralPath) [pscustomobject]@{AreAccessRulesProtected=(-not $script:unsafeAcl);Owner='S-1-5-18';Access=@([pscustomobject]@{AccessControlType='Allow';IdentityReference='S-1-5-18'})}}
function Get-Content {param($LiteralPath,[switch]$Raw) if($LiteralPath -eq $manifestPath){'{"revision":"fixture-v1"}'}else{'x'*32}}
function Get-PLOSTokenAclStatus { @{present=$true;exact=(-not $script:unsafeAcl);reparse=$false} }
function Get-ScheduledTask {param($TaskName,$ErrorAction) [pscustomobject]@{State='Running';Settings=@{Enabled=$true}}}
function Test-PLOSTaskExact {param($Task) $true}
function Get-PLOSControllerProcesses { @([pscustomobject]@{ProcessId=42}) }
function Get-NetTCPConnection {param($State,$LocalPort,$ErrorAction) @([pscustomobject]@{LocalAddress='127.0.0.1'})}
$script:httpCalls=@();$script:httpPayload=$null
function Invoke-RestMethod {param($Uri,$Headers,$Method,$TimeoutSec)
 if($Headers.Authorization -cne ('Bearer '+('x'*32))){throw 'Bad authenticated probe'}
 $script:httpCalls+=@{uri=$Uri;seconds=$TimeoutSec};$script:httpPayload
}
$output=@();$healthy=Get-PLOSReadiness -Manifest $manifest
$output+=@{name='healthy_handler';passed=($healthy.ready -and -not $healthy.mutation_available -and -not $healthy.inference_readiness_verified -and $script:hashCalls -eq 4)}
for($i=0;$i -lt 4;$i++){$script:badPin=$i;$held=$false;try{Get-PLOSReadiness -Manifest $manifest|Out-Null}catch{$held=$true};$output+=@{name="pin_mismatch_$i";passed=$held}};$script:badPin=-1
$script:missingPin=0;$held=$false;try{Get-PLOSReadiness -Manifest $manifest|Out-Null}catch{$held=$true};$script:missingPin=-1;$output+=@{name='missing_control';passed=$held}
$cases=@(@{name='healthy_service'},@{name='missing_response';none=$true},@{name='wrong_host';field='host_id';value='.86'},@{name='wrong_revision';field='revision';value='old'},@{name='wrong_schema';field='schema';value='other'},@{name='wrong_result';field='result';value='guarded_lifecycle'})
foreach($field in @('ready','mutation_available','inference_readiness_verified','contains_prompt_or_response_content')){$cases+=@{name="missing_$field";field=$field;remove=$true};$cases+=@{name="malformed_$field";field=$field;value='false'}}
foreach($case in $cases){$payload=@{};foreach($key in $healthy.Keys){$payload[$key]=$healthy[$key]};if($case.ContainsKey('none')){$payload=$null}elseif($case.ContainsKey('remove')){$payload.Remove($case.field)}elseif($case.ContainsKey('field')){$payload[$case.field]=$case.value};$script:httpPayload=$payload;$status=Get-PLOSStatus -ReadinessOnly;$output+=@{name=$case.name;passed=($status.authenticated_readiness -eq ($case.name -eq 'healthy_service'))}}
$script:httpPayload=@{result='guarded_lifecycle'};$status=Get-PLOSStatus;$output+=@{name='ordinary_status';passed=$status.authenticated_status}
$output+=@{name='bounded_probe';passed=(@($script:httpCalls|Where-Object{$_.uri.EndsWith('/readiness') -and $_.seconds -ne 3}).Count -eq 0 -and @($script:httpCalls|Where-Object{$_.uri.EndsWith('/status') -and $_.seconds -eq 90}).Count -eq 1)}
foreach($case in @(@{name='valid_token';auth=('Bearer '+('x'*32));expected=$true},@{name='wrong_token';auth=('Bearer '+('y'*32));expected=$false},@{name='missing_bearer';auth=('x'*32);expected=$false},@{name='short_token';auth='Bearer short';expected=$false})){$output+=@{name=$case.name;passed=((Test-PLOSToken -Authorization $case.auth) -eq $case.expected)}}
foreach($kind in @('tokenMissing','tokenReparse','unsafeAcl')){Set-Variable -Name $kind -Scope Script -Value $true;$output+=@{name="deny_$kind";passed=(-not(Test-PLOSToken -Authorization ('Bearer '+('x'*32))))};Set-Variable -Name $kind -Scope Script -Value $false}
$output+=@{name='no_model_catalog_child_lease_calls';passed=($script:forbiddenCalls -eq 0)}
$output|ConvertTo-Json -Depth 8 -Compress
if(@($output|Where-Object{-not $_.passed}).Count -gt 0){exit 1}
