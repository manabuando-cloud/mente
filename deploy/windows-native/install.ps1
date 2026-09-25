<#
  設備トラブルナビ（Windows 直接インストール版）の初回インストール
  Docker・WSL・仮想化は不要。PHP（Windows版）と Caddy（Web サーバー）を Windows サービスとして動かす。

  使い方（PowerShell を「管理者として実行」）:
    setsubi-navi-windows.zip を「すべて展開」したフォルダの deploy\windows-native で
      Set-ExecutionPolicy -Scope Process Bypass
      .\install.ps1

  何度実行しても大丈夫（入っているものはそのまま使う）。
#>
param(
    # 省略時は、このスクリプトが入っている配布物（2つ上のフォルダ）を使う
    [string]$Bundle = (Join-Path $PSScriptRoot '..\..'),
    [string]$Root = 'C:\setsubi-navi'
)
. (Join-Path $PSScriptRoot 'lib.ps1')
Assert-Admin
$Bundle = (Resolve-Path $Bundle).Path

Write-Step "フォルダを作成（$Root）"
foreach ($p in $Paths.Root, $Paths.Data, $Paths.Config, $Paths.Backup, $Paths.Tools, $Paths.Svc, $Paths.Logs) {
    New-Item -ItemType Directory -Force -Path $p | Out-Null
}
$tmp = Join-Path $env:TEMP 'setsubi-navi-install'
New-Item -ItemType Directory -Force -Path $tmp | Out-Null

Write-Step 'Visual C++ ランタイム（PHP に必要）'
$vcKey = 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64'
if ((Get-ItemProperty $vcKey -ErrorAction SilentlyContinue).Installed -eq 1) {
    Write-Note 'インストール済み'
} else {
    $vc = Join-Path $tmp 'vc_redist.x64.exe'
    Save-Download 'https://aka.ms/vs/17/release/vc_redist.x64.exe' $vc
    Start-Process $vc -ArgumentList '/install', '/quiet', '/norestart' -Wait
}

Write-Step 'PHP 8.4（Windows 版）'
if (Test-Path $PhpExe) {
    Write-Note "インストール済み: $(& $PhpExe -r 'echo PHP_VERSION;')"
} else {
    $releases = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
    $rel = $releases.'8.4'
    $build = $rel.PSObject.Properties | Where-Object { $_.Name -match '^nts-.*-x64$' } | Select-Object -First 1
    if (-not $build) { throw 'PHP 8.4 の Windows 版が見つかりません（https://windows.php.net/download/ を確認してください）' }
    $zip = Join-Path $tmp 'php.zip'
    Save-Download "https://windows.php.net/downloads/releases/$($build.Value.zip.path)" $zip
    Expand-Archive -Path $zip -DestinationPath $Paths.Php -Force
    Write-Note "PHP $($rel.version) を展開しました"
}
$cacert = Join-Path $Paths.Php 'cacert.pem'
if (-not (Test-Path $cacert)) { Save-Download 'https://curl.se/ca/cacert.pem' $cacert }

Write-Note 'php.ini を作成'
$ini = [IO.File]::ReadAllText((Join-Path $Paths.Php 'php.ini-production'))
$ini += @"

; ---- 設備トラブルナビ用（install.ps1 が追加）----
extension_dir = "$($Paths.Php)\ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
zend_extension=opcache
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
memory_limit=512M
max_execution_time=300
upload_max_filesize=12M
post_max_size=128M
date.timezone=Asia/Tokyo
curl.cainfo="$cacert"
openssl.cafile="$cacert"
variables_order="EGPCS"
expose_php=Off
"@
Write-TextFile $PhpIni $ini

Write-Step 'Caddy（Web サーバー）・ngrok・WinSW（サービス登録用）'
$caddy = Join-Path $Paths.Tools 'caddy.exe'
if (-not (Test-Path $caddy)) { Save-Download 'https://caddyserver.com/api/download?os=windows&arch=amd64' $caddy }
$ngrok = Join-Path $Paths.Tools 'ngrok.exe'
if (-not (Test-Path $ngrok)) {
    $nz = Join-Path $tmp 'ngrok.zip'
    Save-Download 'https://bin.equinox.io/c/bNyj1mQVY4c/ngrok-v3-stable-windows-amd64.zip' $nz
    Expand-Archive -Path $nz -DestinationPath $Paths.Tools -Force
}
$winsw = Join-Path $Paths.Tools 'WinSW-x64.exe'
if (-not (Test-Path $winsw)) { Save-Download 'https://github.com/winsw/winsw/releases/download/v2.12.0/WinSW-x64.exe' $winsw }

Write-Step '設定ファイル（config\.env）'
if (Test-Path $Paths.Env) {
    Write-Note '既にあるのでそのまま使います'
} else {
    $envText = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'env.example'))
    $bytes = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $envText = $envText -replace '(?m)^APP_KEY=.*$', "APP_KEY=base64:$([Convert]::ToBase64String($bytes))"
    $envText = $envText.Replace('C:/setsubi-navi', ($Root -replace '\\', '/'))
    Write-TextFile $Paths.Env $envText
    Write-Note "作成しました: $($Paths.Env)"
}
$caddyText = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Caddyfile.template'))
$caddyText = $caddyText.Replace('{{ROOT}}', ($Root -replace '\\', '/')).Replace('{{PHP_UPSTREAMS}}', (($PhpPorts | ForEach-Object { "127.0.0.1:$_" }) -join ' '))
Write-TextFile (Join-Path $Paths.Config 'Caddyfile') $caddyText
& $caddy validate --config (Join-Path $Paths.Config 'Caddyfile') --adapter caddyfile 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Caddyfile の設定に誤りがあります' }

Write-Step 'アプリ本体を配置'
Stop-NaviServices
Deploy-Bundle $Bundle
Update-AppConfig

Write-Step 'Windows サービスを登録して起動'
Install-NaviServices
Start-NaviServices

Write-Step 'ファイアウォールで 8080 番を許可（社内LANから http://<このPC>:8080 で開けるように）'
if (-not (Get-NetFirewallRule -DisplayName 'SetsubiNavi 8080' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName 'SetsubiNavi 8080' -Direction Inbound -Protocol TCP -LocalPort 8080 -Action Allow | Out-Null
}
Write-Note '電源接続時にスリープしないようにします'
powercfg /change standby-timeout-ac 0 | Out-Null
powercfg /change hibernate-timeout-ac 0 | Out-Null

Start-Sleep -Seconds 3
try {
    $r = Invoke-WebRequest -Uri 'http://localhost:8080/up' -UseBasicParsing -TimeoutSec 20
    Write-Step "インストール完了（/up: $($r.StatusCode)）"
} catch {
    Write-Step 'インストールは終わりましたが、画面の応答を確認できませんでした'
    Write-Note ".\navi.ps1 status と $($Paths.Logs) のログを確認してください"
}
Write-Host ''
Write-Host "  このPCのブラウザで http://localhost:8080 を開いてください。"
Write-Host "  Google ログインや ngrok の設定は $($Paths.Env) をメモ帳で編集し、.\navi.ps1 restart で反映します。"
