<#
  設備トラブルナビ（Windows 直接インストール版）を新しいバージョンに更新する
  使い方（管理者の PowerShell）:
    新しい setsubi-navi-windows.zip を「すべて展開」したフォルダの deploy\windows-native で .\update.ps1
  DB・写真・設定（C:\setsubi-navi\data と config）はそのまま残る。更新前に自動でバックアップする。
#>
param(
    [string]$Bundle = (Join-Path $PSScriptRoot '..\..'),
    [string]$Root = 'C:\setsubi-navi'
)
. (Join-Path $PSScriptRoot 'lib.ps1')
Assert-Admin
$Bundle = (Resolve-Path $Bundle).Path

Write-Step '更新前のバックアップ'
try { Invoke-Artisan navi:backup } catch { Write-Note "バックアップに失敗しました（続行します）: $_" }

Write-Step 'サービスを停止'
Stop-NaviServices

Write-Step 'アプリ本体を入れ替え'
Deploy-Bundle $Bundle
Update-AppConfig

Write-Step 'サービスを登録し直して起動'
Install-NaviServices
Start-NaviServices
Write-Step '更新完了'
Write-Note "問題があれば、1つ前の版は $Root\app-old に残っています"
