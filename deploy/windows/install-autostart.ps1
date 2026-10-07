# 設備トラブルナビを Windows の起動時に自動で動かす設定（管理者として PowerShell で1回だけ実行）
#   PowerShell を「管理者として実行」→ このファイルのあるフォルダで:
#     Set-ExecutionPolicy -Scope Process Bypass; .\install-autostart.ps1
param(
    [string]$Distro = "Ubuntu"
)

Write-Host "== スリープしないようにします（電源に接続時）"
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0

Write-Host "== WSL の設定（アイドル時に止めない）"
# networkingMode=mirrored は社内の DNS で名前解決できなくなることがあるので使わない（社員は公開URLで開く）
$wslconfig = Join-Path $env:USERPROFILE ".wslconfig"
if (-not (Test-Path $wslconfig)) {
    @"
[wsl2]
vmIdleTimeout=-1
"@ | Set-Content -Encoding UTF8 $wslconfig
    Write-Host "   $wslconfig を作成しました"
} else {
    $lines = Get-Content $wslconfig | Where-Object { $_ -notmatch '^\s*networkingMode\s*=\s*mirrored' }
    $lines | Set-Content -Encoding UTF8 $wslconfig
    if (-not ($lines -match '^\s*vmIdleTimeout')) {
        Write-Host "   $wslconfig の [wsl2] に vmIdleTimeout=-1 を追記してください"
    }
}

Write-Host "== 起動時に WSL（とその中の Docker）を立ち上げるタスクを登録します"
$cred = Get-Credential -UserName "$env:USERDOMAIN\$env:USERNAME" -Message "このPCにログインするときのパスワードを入力してください（ログインしていなくても起動させるため）"
$action = New-ScheduledTaskAction -Execute "wsl.exe" -Argument "-d $Distro --exec sleep infinity"
# 起動直後はネットワークやドメインへのログオンが整っておらず失敗することがあるので2分待つ。
# さらに5分ごとに起動を試みる（動いている間は IgnoreNew で何もしない）ので、WSL が止まっても戻る
$trigger = New-ScheduledTaskTrigger -AtStartup
$trigger.Delay = "PT2M"
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)).Repetition
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName "SetsubiNavi-WSL" -Action $action -Trigger $trigger -Settings $settings `
    -User $cred.UserName -Password $cred.GetNetworkCredential().Password -RunLevel Highest -Force | Out-Null

Write-Host ""
Write-Host "完了しました。PC を再起動して、ログインせずに5分ほど待ってから、スマホなどで公開URL（.env の APP_URL）を開いて確認してください。"
