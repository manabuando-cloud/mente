# Windows PC（kltech04）に直接インストールする ― 仮想化不要・費用ゼロ

kltech04 は Hyper-V 上の仮想マシンで WSL2（Docker）が使えないため、Docker を使わずに Windows へ直接入れる方式です。

```
 社員のPC・スマホ（社内・社外とも）
        │ https://xxxx.ngrok-free.app   ← 会社の Google アカウントでログイン
        ▼
 kltech04（Windows）の Windows サービス（PCを起動すればログインしなくても動く）
   SetsubiNavi-Ngrok       公開URL（ngrok）
   SetsubiNavi-Web         Web サーバー（Caddy, :8080）
   SetsubiNavi-Php1〜4     PHP の処理役（同時に4人まで待たずに処理）
   SetsubiNavi-Queue       Drive連携の「今すぐ実行」
   SetsubiNavi-Scheduler   毎日 1:30 バックアップ / 2:10・2:40 報告書取込み / 3:10 自動リンク

 C:\setsubi-navi\
   app\      アプリ本体（更新のたびに入れ替わる）
   data\     DB（navi.sqlite）・写真・アプリのログ   ← 消さない
   config\   設定（.env）・Web サーバー設定・Drive の鍵  ← 消さない
   backup\   毎日のバックアップ（DB 30日分・写真）
   php\ tools\ services\ logs\
```

- 必要なもの（PHP・Caddy・ngrok など）は `install.ps1` が自動でダウンロードします。すべて無料です。
- ログインは会社の Google アカウント（`@g.kurashiki-laser.co.jp`）だけ。管理者は `NAVI_ADMIN_EMAILS` の人。
- Google ログインは https の公開URLでしか使えないため、社内の人も公開URL（`https://xxxx.ngrok-free.app`）で開きます。kltech04 本体では <http://localhost:8080> でも使えます。
- Gemini は無料枠（呼び出し間隔を7秒空け、1日15件ずつ取り込む）。無料枠では送った内容が Google のサービス改善に使われることがあります。

作業はリモートデスクトップで kltech04 に入り、**管理者として** 行います。所要時間は1〜2時間。

---

## 1. 配布物をダウンロードしてインストールする

1. GitHub の <https://github.com/manabuando-cloud/mente/releases/tag/windows-latest> を開き、`setsubi-navi-windows.zip` をダウンロード
   （PR がまだマージされていない場合は、PR の「Checks → Windows 配布物 → Artifacts」からダウンロードして、中の ZIP を使う）
2. ダウンロードした ZIP を右クリック →「すべて展開」（展開先は **ダウンロードフォルダのままで良い**。`C:\setsubi-navi` の中には展開しない）
3. スタートメニューで「PowerShell」を右クリック →「**管理者として実行**」し、次を実行
   ```powershell
   cd $env:USERPROFILE\Downloads\setsubi-navi-windows\deploy\windows-native
   Set-ExecutionPolicy -Scope Process Bypass
   .\install.ps1
   ```
4. 10分ほどで「インストール完了」と出ます。kltech04 のブラウザで <http://localhost:8080> を開き、「Googleログインの設定がまだ済んでいません」と出れば、ここまでは成功です

> 以降、設定を変えたら **同じ PowerShell（管理者）で** `.\navi.ps1 restart` を実行すると反映されます。
> 設定ファイルは `C:\setsubi-navi\config\.env` です。メモ帳を **管理者として実行** してから開いてください。

## 2. 公開URLを作る（ngrok・無料）

1. <https://ngrok.com/> で無料アカウントを作る（会社の Google アカウントでサインアップできます）
2. ダッシュボードの「Your Authtoken」をコピー
3. 「Domains」→「New Domain」で無料の固定ドメイン（例: `kurashiki-navi.ngrok-free.app`）を1つ作る
4. `C:\setsubi-navi\config\.env` に入れる
   ```dotenv
   APP_URL=https://kurashiki-navi.ngrok-free.app
   NGROK_AUTHTOKEN=（2 のトークン）
   NGROK_DOMAIN=kurashiki-navi.ngrok-free.app
   ```

> ngrok の無料プランは、ブラウザで初めて開いたときに確認画面が1回出ます（「Visit Site」を押す）。

## 3. Google ログインを設定する（会社アカウントだけ）

Google Cloud の **プロジェクトと OAuth クライアントの作成は無料** です（課金の登録は不要）。

1. <https://console.cloud.google.com/> で新しいプロジェクトを作成（名前は例: `setsubi-navi`、組織は会社のものを選ぶ）
2. 「APIとサービス → OAuth 同意画面」（「Google Auth Platform」と表示される場合もあります）
   - ユーザーの種類: **内部**（会社のアカウントだけになる）
   - アプリ名: 設備トラブルナビ、サポートメール: 自分のアドレス
3. 「認証情報 → 認証情報を作成 → OAuth クライアント ID」
   - アプリケーションの種類: **ウェブ アプリケーション**
   - 承認済みのリダイレクト URI に次の2つを追加
     - `https://kurashiki-navi.ngrok-free.app/auth/google/callback`（2 のドメイン）
     - `http://localhost:8080/auth/google/callback`
4. 表示された値を `C:\setsubi-navi\config\.env` に入れる
   ```dotenv
   GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
   NAVI_ADMIN_EMAILS=manabu.ando@g.kurashiki-laser.co.jp
   ```

## 4. Gemini / Slack を設定する

| `.env` の項目 | 入れるもの |
|---|---|
| `GEMINI_API_KEY` | [Google AI Studio](https://aistudio.google.com/apikey) で「APIキーを作成」（課金登録は不要。3 と同じプロジェクトを選んでよい） |
| `SLACK_WEBHOOK_URL` | Slack の Incoming Webhook URL（通知しないなら空のまま） |

2〜4 を入れたら反映して確認します。

```powershell
.\navi.ps1 restart
.\navi.ps1 status                    # SetsubiNavi-Ngrok を含めて Running になっているか
.\navi.ps1 artisan navi:test-slack   # Slack に届くか
```

別のPC（またはスマホ）で `https://kurashiki-navi.ngrok-free.app` を開き、「Googleアカウントでログイン」で入れれば成功です。

## 5. Google Drive に接続する（報告書PDFの取込み・リンク）

1. 3 のプロジェクトで「APIとサービス → ライブラリ」→ **Google Drive API** を有効にする
2. 「IAMと管理 → サービスアカウント」で作成（名前は例: `navi-drive`、ロールは不要）
3. 作ったサービスアカウント →「鍵」→「鍵を追加 → 新しい鍵を作成 → JSON」→ ダウンロード
4. ダウンロードしたファイルを **`C:\setsubi-navi\config\google-drive.json`** という名前で置く（ダウンロードフォルダの元ファイルは削除）
5. Google Drive で共有ルートフォルダを、サービスアカウントのメールアドレス（`navi-drive@<プロジェクト>.iam.gserviceaccount.com`）に **閲覧者** で共有
6. 確認
   ```powershell
   .\navi.ps1 restart
   .\navi.ps1 artisan navi:sync-drive-machines --dry-run
   ```

> 3 で「鍵の作成が組織のポリシーで無効」と出た場合は、Google Workspace の管理者に「`iam.disableServiceAccountKeyCreation` をこのプロジェクトだけ解除」を依頼してください。

## 6. 旧GAS版のデータを移す

1. スプレッドシート「設備マスタ一覧（SOFTエクスポート）」を「ファイル → ダウンロード → CSV」で保存し、`設備マスタ一覧.csv` という名前にする
2. 「設備トラブルナビ_データ」の各シート（Cases / PendingCases / AiConsultations / Ratings）を、シートを開いた状態で同様に CSV で保存し、`Cases.csv` のようにシート名のファイル名にする
3. すべてを `C:\setsubi-navi-import` フォルダに入れて:
   ```powershell
   .\navi.ps1 import C:\setsubi-navi-import
   .\navi.ps1 artisan navi:ingest-vendor-reports --dry-run   # 業者別フォルダの振り分け確認（AIは使わない）
   ```

何度実行しても重複しません。

## 7. 普段の運用

`deploy\windows-native` フォルダで、管理者の PowerShell から実行します（`Set-ExecutionPolicy -Scope Process Bypass` を先に1回）。

| やりたいこと | コマンド |
|---|---|
| 動いているか確認 | `.\navi.ps1 status` |
| 設定（.env）の変更を反映 | `.\navi.ps1 restart` |
| 新しいバージョンに更新 | 新しい ZIP をダウンロード・展開し、その中の `deploy\windows-native` で `.\update.ps1`（更新前に自動でバックアップ） |
| 今すぐバックアップ | `.\navi.ps1 backup`（毎日 1:30 にも自動） |
| ログを見る | `.\navi.ps1 logs`（エクスプローラーでログのフォルダが開く） |
| 管理者を追加・変更 | `.env` の `NAVI_ADMIN_EMAILS`（カンマ区切り）→ `restart` |

- リモートデスクトップは「サインアウト」「切断」して構いません。**「シャットダウン」はしないでください**（再起動は OK。起動後は自動で動きます）。
- **バックアップ** は `C:\setsubi-navi\backup` に入ります。このフォルダを NAS や Google Drive / OneDrive にもコピーしておくと、PC が壊れても戻せます。

**戻し方**（PC を入れ替えたときなど）: 新しい PC で 1〜5 を行ったあと、管理者の PowerShell で

```powershell
Get-Service SetsubiNavi-* | Stop-Service -Force
Copy-Item C:\どこか\backup\db\navi-YYYYMMDD-HHMMSS.sqlite C:\setsubi-navi\data\navi.sqlite -Force
Remove-Item C:\setsubi-navi\data\navi.sqlite-wal, C:\setsubi-navi\data\navi.sqlite-shm -ErrorAction SilentlyContinue
robocopy C:\どこか\backup\photos C:\setsubi-navi\data\storage\app\public\photos /E
.\navi.ps1 restart
```

## トラブルシューティング

| 症状 | 確認すること |
|---|---|
| `install.ps1` がダウンロードで止まる | 社内のプロキシ・フィルタで windows.php.net / caddyserver.com / github.com / equinox.io がブロックされていないか |
| <http://localhost:8080> が開けない | `.\navi.ps1 status` で SetsubiNavi-Web と Php1〜4 が Running か。`C:\setsubi-navi\logs` の `SetsubiNavi-Web.err.log` |
| 画面に「500 エラー」 | `C:\setsubi-navi\data\storage\logs` の最新の `laravel-*.log` |
| 公開URLが開けない | `SetsubiNavi-Ngrok` が Running か、`.env` の `NGROK_AUTHTOKEN` / `NGROK_DOMAIN`、`logs\SetsubiNavi-Ngrok.out.log` |
| Google ログインで「redirect_uri_mismatch」 | OAuth クライアントのリダイレクト URI が、開いたURL + `/auth/google/callback` と完全に一致しているか |
| 管理メニュー（取込レビュー・Drive連携）が出ない | `.env` の `NAVI_ADMIN_EMAILS` → `restart` → ログインし直す |
| 報告書の取込みが止まる | Gemini 無料枠の上限（429）。翌日に自動で続きから再開します |
