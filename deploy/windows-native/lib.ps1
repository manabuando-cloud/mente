# 設備トラブルナビ（Windows 直接インストール版）共通の関数・設定
# install.ps1 / update.ps1 / navi.ps1 から読み込む。直接は実行しない。

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'   # Invoke-WebRequest を速くする
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
# PHP が出す日本語（UTF-8）を文字化けさせずに表示する
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }

if (-not $Root) { $Root = 'C:\setsubi-navi' }
$Paths = @{
    Root    = $Root
    App     = Join-Path $Root 'app'        # アプリ本体（更新のたびに入れ替える）
    Data    = Join-Path $Root 'data'       # DB・写真・ログ（消さない）
    Config  = Join-Path $Root 'config'     # .env・Caddyfile・鍵（消さない）
    Backup  = Join-Path $Root 'backup'     # 毎日のバックアップ
    Php     = Join-Path $Root 'php'
    Tools   = Join-Path $Root 'tools'      # caddy / ngrok / WinSW
    Svc     = Join-Path $Root 'services'   # Windows サービスの定義
    Logs    = Join-Path $Root 'logs'
}
$Paths.Storage = Join-Path $Paths.Data 'storage'
$Paths.Env = Join-Path $Paths.Config '.env'
$PhpExe = Join-Path $Paths.Php 'php.exe'
$PhpCgi = Join-Path $Paths.Php 'php-cgi.exe'
$PhpIni = Join-Path $Paths.Php 'php.ini'

# PHP の処理役（php-cgi）の数。AI 相談など時間のかかる処理があってもほかの人が待たされないよう複数置く
$PhpWorkers = 4
$PhpPorts = 1..$PhpWorkers | ForEach-Object { 9000 + $_ }

$Utf8NoBom = New-Object System.Text.UTF8Encoding $false

function Write-Step([string]$text) { Write-Host ""; Write-Host "== $text" -ForegroundColor Cyan }
function Write-Note([string]$text) { Write-Host "   $text" }

function Assert-Admin {
    $id = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    if (-not $id.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'PowerShell を「管理者として実行」してから、もう一度実行してください。'
    }
}

# BOM なし UTF-8 で書く（.env や Caddyfile に BOM が入ると先頭の設定が読めなくなる）
function Write-TextFile([string]$path, [string]$text) {
    [IO.File]::WriteAllText($path, $text, $Utf8NoBom)
}

function Save-Download([string]$url, [string]$dest) {
    Write-Note "ダウンロード: $url"
    Invoke-WebRequest -Uri $url -OutFile $dest -UseBasicParsing
}

# .env の値を読む / 書く
function Get-EnvValue([string]$key) {
    if (-not (Test-Path $Paths.Env)) { return $null }
    foreach ($line in [IO.File]::ReadAllLines($Paths.Env)) {
        if ($line -match "^\s*$([regex]::Escape($key))=(.*)$") { return $Matches[1].Trim().Trim('"') }
    }
    return $null
}
function Set-EnvValue([string]$key, [string]$value) {
    $lines = [Collections.Generic.List[string]]([IO.File]::ReadAllLines($Paths.Env))
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^\s*$([regex]::Escape($key))=") { $lines[$i] = "$key=$value"; $found = $true }
    }
    if (-not $found) { $lines.Add("$key=$value") }
    Write-TextFile $Paths.Env (($lines -join "`r`n") + "`r`n")
}

function Invoke-Artisan {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$ArtisanArgs)
    Push-Location $Paths.App
    try {
        & $PhpExe -c $PhpIni artisan @ArtisanArgs
        if ($LASTEXITCODE -ne 0) { throw "php artisan $($ArtisanArgs -join ' ') が失敗しました（終了コード $LASTEXITCODE）" }
    } finally { Pop-Location }
}

# ---- Windows サービス（WinSW） ----------------------------------------------

function Get-ServiceDefs {
    $defs = @()
    foreach ($port in $PhpPorts) {
        $defs += @{
            Id = "SetsubiNavi-Php$($port - 9000)"; Name = "設備トラブルナビ PHP $($port - 9000)"
            Exe = $PhpCgi; Args = "-b 127.0.0.1:$port -c `"$PhpIni`""; Dir = $Paths.App
            Env = @{ PHP_FCGI_MAX_REQUESTS = '0' }   # 既定の500件で終了しないように
        }
    }
    $defs += @{
        Id = 'SetsubiNavi-Web'; Name = '設備トラブルナビ Web（Caddy）'
        Exe = (Join-Path $Paths.Tools 'caddy.exe'); Args = "run --config `"$(Join-Path $Paths.Config 'Caddyfile')`" --adapter caddyfile"; Dir = $Paths.Config
    }
    $defs += @{
        Id = 'SetsubiNavi-Queue'; Name = '設備トラブルナビ キュー'
        Exe = $PhpExe; Args = "-c `"$PhpIni`" artisan queue:work --sleep=3 --tries=1 --memory=256"; Dir = $Paths.App
    }
    $defs += @{
        Id = 'SetsubiNavi-Scheduler'; Name = '設備トラブルナビ スケジューラ（毎日の自動取込み・バックアップ）'
        Exe = $PhpExe; Args = "-c `"$PhpIni`" artisan schedule:work"; Dir = $Paths.App
    }
    $token = Get-EnvValue 'NGROK_AUTHTOKEN'; $domain = Get-EnvValue 'NGROK_DOMAIN'
    if ($token -and $domain) {
        $defs += @{
            Id = 'SetsubiNavi-Ngrok'; Name = '設備トラブルナビ 公開URL（ngrok）'
            Exe = (Join-Path $Paths.Tools 'ngrok.exe'); Args = "http 8080 --url=$domain --authtoken=$token --log=stdout"; Dir = $Paths.Tools
        }
    }
    return $defs
}

function Install-NaviServices {
    New-Item -ItemType Directory -Force -Path $Paths.Svc, $Paths.Logs | Out-Null
    $winsw = Join-Path $Paths.Tools 'WinSW-x64.exe'
    foreach ($d in Get-ServiceDefs) {
        $exe = Join-Path $Paths.Svc "$($d.Id).exe"
        $xml = Join-Path $Paths.Svc "$($d.Id).xml"
        if (Get-Service -Name $d.Id -ErrorAction SilentlyContinue) {
            & $exe stop 2>$null | Out-Null
            & $exe uninstall | Out-Null
        }
        Copy-Item $winsw $exe -Force
        $envXml = ''
        if ($d.Env) { foreach ($k in $d.Env.Keys) { $envXml += "  <env name=`"$k`" value=`"$($d.Env[$k])`"/>`r`n" } }
        $content = @"
<?xml version="1.0" encoding="UTF-8"?>
<service>
  <id>$($d.Id)</id>
  <name>$($d.Name)</name>
  <description>設備トラブルナビ（$Root）</description>
  <executable>$([Security.SecurityElement]::Escape($d.Exe))</executable>
  <arguments>$([Security.SecurityElement]::Escape($d.Args))</arguments>
  <workingdirectory>$($d.Dir)</workingdirectory>
$envXml  <startmode>Automatic</startmode>
  <onfailure action="restart" delay="10 sec"/>
  <resetfailure>1 hour</resetfailure>
  <logpath>$($Paths.Logs)</logpath>
  <log mode="roll-by-size"><sizeThreshold>10240</sizeThreshold><keepFiles>5</keepFiles></log>
</service>
"@
        [IO.File]::WriteAllText($xml, $content, (New-Object System.Text.UTF8Encoding $true))
        & $exe install | Out-Null
        Write-Note "サービス登録: $($d.Id)"
    }
}

function Get-NaviServiceIds { Get-Service -Name 'SetsubiNavi-*' -ErrorAction SilentlyContinue | ForEach-Object Name }

function Stop-NaviServices {
    foreach ($id in Get-NaviServiceIds) { Stop-Service -Name $id -Force -ErrorAction SilentlyContinue }
}
function Start-NaviServices {
    # PHP の処理役を先に起動してから Web・キュー・スケジューラ・ngrok
    Get-NaviServiceIds | Sort-Object { if ($_ -like 'SetsubiNavi-Php*') { 0 } else { 1 } } | ForEach-Object { Start-Service -Name $_ }
}

# ---- アプリ本体の配置 ----------------------------------------------------------

# 配布物（GitHub Actions が作る setsubi-navi-windows.zip、またはそれを展開したフォルダ）を app に置き、
# storage を data に向ける
function Deploy-Bundle([string]$bundle) {
    if (-not (Test-Path $bundle)) { throw "配布物が見つかりません: $bundle" }
    if ((Resolve-Path $bundle).Path.TrimEnd('\').StartsWith($Root.TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase)) {
        throw "配布物は $Root の外（ダウンロードフォルダなど）に展開してから実行してください"
    }
    $staging = Join-Path $Root "app-new"
    if (Test-Path $staging) { cmd /c rmdir /s /q "$staging" | Out-Null }
    if ((Get-Item $bundle).PSIsContainer) {
        Write-Note "コピー: $bundle"
        robocopy $bundle $staging /E /NFL /NDL /NJH /NJS /NP /XD .git node_modules | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "コピーに失敗しました（robocopy $LASTEXITCODE）" }
    } else {
        Write-Note "展開: $bundle"
        Expand-Archive -Path $bundle -DestinationPath $staging -Force
    }
    if (-not (Test-Path (Join-Path $staging 'artisan'))) { throw "配布物の中身が違います（artisan がありません）: $bundle" }

    # 初回は storage の雛形を data にコピー（2回目以降は data の中身を使う）
    New-Item -ItemType Directory -Force -Path $Paths.Data | Out-Null
    if (-not (Test-Path $Paths.Storage)) { Copy-Item (Join-Path $staging 'storage') $Paths.Storage -Recurse }
    foreach ($sub in 'app\public', 'app\private', 'framework\cache\data', 'framework\sessions', 'framework\views', 'logs') {
        New-Item -ItemType Directory -Force -Path (Join-Path $Paths.Storage $sub) | Out-Null
    }
    Remove-Item (Join-Path $staging 'storage') -Recurse -Force
    cmd /c mklink /J "$(Join-Path $staging 'storage')" "$($Paths.Storage)" | Out-Null
    # 写真の公開用リンク（public\storage → data\storage\app\public）
    $publicLink = Join-Path $staging 'public\storage'
    # 注意: ジャンクションを Remove-Item -Recurse で消すとリンク先（写真）まで消える。必ず rmdir を使う
    if (Test-Path $publicLink) { cmd /c rmdir "$publicLink" | Out-Null }
    cmd /c mklink /J "$publicLink" "$(Join-Path $Paths.Storage 'app\public')" | Out-Null
    Copy-Item $Paths.Env (Join-Path $staging '.env') -Force

    # 入れ替え（旧版は1つだけ残す）
    # rmdir /s はジャンクションの先（data）をたどらずリンクだけ消すので安全
    $old = Join-Path $Root 'app-old'
    if (Test-Path $old) { cmd /c rmdir /s /q "$old" | Out-Null }
    if (Test-Path $Paths.App) { Rename-Item -Path $Paths.App -NewName 'app-old' }
    Rename-Item -Path $staging -NewName 'app'
}

# .env の変更を反映してキャッシュを作り直し、DB を更新する
function Update-AppConfig {
    Copy-Item $Paths.Env (Join-Path $Paths.App '.env') -Force
    $db = Get-EnvValue 'DB_DATABASE'
    if ($db -and -not (Test-Path $db)) { New-Item -ItemType File -Force -Path $db | Out-Null }
    Invoke-Artisan migrate --force
    Invoke-Artisan config:cache
    Invoke-Artisan route:cache
    Invoke-Artisan view:cache
}
