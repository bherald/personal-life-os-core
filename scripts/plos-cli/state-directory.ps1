param([Parameter(Mandatory=$true)][string]$PathBase64,[switch]$Create)
$ErrorActionPreference='Stop';$ProgressPreference='SilentlyContinue'
[Console]::OutputEncoding=[Text.UTF8Encoding]::new($false)
try {
    $path=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($PathBase64))
    if($path -notmatch '^[A-Za-z]:[\\/]' -or $path.IndexOf(':',2) -ge 0){throw 'Local absolute directory required.'}
    $path=[IO.Path]::GetFullPath($path).TrimEnd('\','/')
    if($path.Length -le 3){throw 'A drive root cannot hold CLI state.'}
    $user=[Security.Principal.WindowsIdentity]::GetCurrent().User
    $trusted=@($user.Value,'S-1-5-18','S-1-5-32-544')
    $missing=@();$cursor=$path
    while(-not (Test-Path -LiteralPath $cursor)) {
        $missing+=,$cursor;$cursor=[IO.Path]::GetDirectoryName($cursor)
        if([string]::IsNullOrEmpty($cursor)){throw 'Existing parent required.'}
    }
    $parent=Get-Item -LiteralPath $cursor -Force
    while($null -ne $parent){
        if($parent -isnot [IO.DirectoryInfo] -or ($parent.Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Directory ancestry is not plain local storage.'}
        $parent=$parent.Parent
    }
    if($missing.Count -gt 0 -and -not $Create){throw 'State directory does not exist.'}
    $security=[Security.AccessControl.DirectorySecurity]::new()
    $security.SetOwner($user);$security.SetAccessRuleProtection($true,$false)
    foreach($sid in $trusted|Select-Object -Unique){
        $rule=[Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new($sid),
            [Security.AccessControl.FileSystemRights]::FullControl,
            ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit),
            [Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow)
        $security.AddAccessRule($rule)
    }
    for($index=$missing.Count-1;$index -ge 0;$index--){
        # Only new empty directories receive an ACL. Existing state is checked,
        # never silently repaired or shared with additional principals.
        $directory=[IO.DirectoryInfo]::new($missing[$index])
        $directory.Create($security)
    }
    $item=Get-Item -LiteralPath $path -Force
    if(-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Plain state directory required.'}
    $acl=Get-Acl -LiteralPath $path
    if($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $trusted){throw 'Untrusted state owner.'}
    $allowed=@($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])|Where-Object{$_.AccessControlType -eq 'Allow'})
    if($allowed.Count -eq 0){throw 'State access rules missing.'}
    $inherits=$false
    foreach($rule in $allowed){
        if($rule.IdentityReference.Value -notin $trusted){throw 'State directory is shared.'}
        if($rule.IdentityReference.Value -eq $user.Value -and
            ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) -eq [Security.AccessControl.FileSystemRights]::FullControl -and
            ($rule.InheritanceFlags -band [Security.AccessControl.InheritanceFlags]::ContainerInherit) -and
            ($rule.InheritanceFlags -band [Security.AccessControl.InheritanceFlags]::ObjectInherit) -and
            $rule.PropagationFlags -eq [Security.AccessControl.PropagationFlags]::None){$inherits=$true}
    }
    if(-not $inherits){throw 'Current operator and new state files need inheritable access.'}
    @{schema='plos.cli.state-directory.v1';path=$item.FullName;private=$true;created=($missing.Count -gt 0)}|ConvertTo-Json -Compress
} catch {
    [Console]::Error.WriteLine('CLI state directory must be private local storage with inheritable operator access.')
    exit 1
}
