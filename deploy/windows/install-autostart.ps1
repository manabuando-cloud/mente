# 設備トラブルナビを Windows の起動時に自動で動かす設定（管理者として PowerShell で1回だけ実行）
#   PowerShell を「管理者として実行」→ このファイルのあるフォルダで:
#     Set-ExecutionPolicy -Scope Process Bypass; .\install-autostart.ps1
param(
    [string]$Distro = "Ubuntu"
)

Write-Host "== スリープしないようにします（電源に接続時）"
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0

Write-Host "== WSL の設定（アイドル時に止めない・LANから直接アクセスできるようにする）"
$wslconfig = Join-Path $env:USERPROFILE ".wslconfig"
if (-not (Test-Path $wslconfig)) {
    @"
[wsl2]
vmIdleTimeout=-1
networkingMode=mirrored
"@ | Set-Content -Encoding UTF8 $wslconfig
    Write-Host "   $wslconfig を作成しました"
} else {
    Write-Host "   $wslconfig は既にあります。[wsl2] に vmIdleTimeout=-1 と networkingMode=mirrored を追記してください"
}

Write-Host "== 起動時に WSL（とその中の Docker）を立ち上げるタスクを登録します"
$cred = Get-Credential -UserName "$env:USERDOMAIN\$env:USERNAME" -Message "このPCにログインするときのパスワードを入力してください（ログインしていなくても起動させるため）"
$action = New-ScheduledTaskAction -Execute "wsl.exe" -Argument "-d $Distro --exec sleep infinity"
$trigger = New-ScheduledTaskTrigger -AtStartup
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "SetsubiNavi-WSL" -Action $action -Trigger $trigger -Settings $settings `
    -User $cred.UserName -Password $cred.GetNetworkCredential().Password -RunLevel Highest -Force | Out-Null

Write-Host "== LAN から http://<このPC>:8080 で開けるようにファイアウォールを許可します"
New-NetFirewallRule -DisplayName "SetsubiNavi 8080" -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow -ErrorAction SilentlyContinue | Out-Null

Write-Host ""
Write-Host "完了しました。PC を再起動して、ログインせずに数分待ってから別のPCで http://$env:COMPUTERNAME:8080 を開いて確認してください。"
