<#
  設備トラブルナビ（Windows 直接インストール版）の普段の操作（管理者の PowerShell で実行）

    .\navi.ps1 status                 動いているか確認
    .\navi.ps1 restart                config\.env の変更を反映して再起動
    .\navi.ps1 logs                   ログの場所を開く
    .\navi.ps1 backup                 今すぐバックアップ（毎日 1:30 にも自動）
    .\navi.ps1 import C:\setsubi-navi-import   旧GAS版のCSVを取り込む
    .\navi.ps1 artisan navi:test-slack         任意の artisan コマンド
    .\navi.ps1 uninstall              サービスを削除（データは残る）
#>
param(
    [Parameter(Position = 0)][string]$Command = 'status',
    [Parameter(Position = 1, ValueFromRemainingArguments = $true)][string[]]$Rest = @(),
    [string]$Root = 'C:\setsubi-navi'
)
. (Join-Path $PSScriptRoot 'lib.ps1')

switch ($Command) {
    'status' {
        Get-Service -Name 'SetsubiNavi-*' | Sort-Object Name | Format-Table Name, Status, DisplayName -AutoSize
        try {
            $r = Invoke-WebRequest -Uri 'http://localhost:8080/up' -UseBasicParsing -TimeoutSec 10
            Write-Host "画面: http://localhost:8080 は応答しています（$($r.StatusCode)）" -ForegroundColor Green
        } catch { Write-Host '画面: http://localhost:8080 が応答しません' -ForegroundColor Red }
        $url = Get-EnvValue 'APP_URL'
        if ($url) { Write-Host "公開URL: $url" }
    }
    'restart' {
        Assert-Admin
        Stop-NaviServices
        Update-AppConfig
        Install-NaviServices      # ngrok の設定の追加・変更も反映する
        Start-NaviServices
        Write-Step '再起動しました'
    }
    'logs' { Start-Process explorer.exe $Paths.Logs; Start-Process explorer.exe (Join-Path $Paths.Storage 'logs') }
    'backup' { Invoke-Artisan navi:backup }
    'artisan' { Invoke-Artisan @Rest }
    'import' {
        $dir = if ($Rest.Count -gt 0) { $Rest[0] } else { 'C:\setsubi-navi-import' }
        $files = [ordered]@{
            '設備マスタ一覧.csv' = '--soft'; 'Cases.csv' = '--cases'; 'PendingCases.csv' = '--pending'
            'AiConsultations.csv' = '--consultations'; 'Ratings.csv' = '--ratings'
        }
        foreach ($name in $files.Keys) {
            $path = Join-Path $dir $name
            if (Test-Path $path) { Write-Step $name; Invoke-Artisan navi:import "$($files[$name])=$path" }
        }
        Write-Step 'Drive の機械フォルダから機種マスタを補完'
        try { Invoke-Artisan navi:sync-drive-machines } catch { Write-Note "Drive が未設定ならあとで .\navi.ps1 artisan navi:sync-drive-machines を実行してください" }
    }
    'uninstall' {
        Assert-Admin
        foreach ($id in Get-NaviServiceIds) {
            $exe = Join-Path $Paths.Svc "$id.exe"
            & $exe stop | Out-Null; & $exe uninstall | Out-Null
            Write-Note "削除: $id"
        }
        Write-Note "データ（$($Paths.Data)）と設定（$($Paths.Config)）は残しています"
    }
    default { Get-Help $PSCommandPath }
}
