# Windows PC（kltech04）で動かす ― 費用ゼロの構成

```
 社内のPC・スマホ ──http://kltech04:8080──┐
 社外のスマホ ──https（Cloudflare Tunnel）─┤
                                        ▼
 kltech04（Windows）
  └ WSL2 の Ubuntu
     └ Docker Engine（無料）
        ├ app        … 画面（Web）
        ├ worker     … Drive連携の「今すぐ実行」
        ├ scheduler  … 毎日 1:30 バックアップ / 2:10・2:40 報告書取込み / 3:10 自動リンク
        └ tunnel     … 社外公開（任意）
  DB: SQLite（1ファイル）、写真: Docker ボリューム、バックアップ: C:\setsubi-navi-backup
```

- **費用はかかりません。** Docker Desktop は大きな会社では有料なので使わず、WSL2 の中に無料の Docker Engine を入れます。
- ログインは **「URLを知っている人なら誰でも」**（名前だけ入力）。管理機能は **管理者パスコード** を入れた人だけ。
- Gemini は無料枠（呼び出し間隔を7秒空け、1日15件ずつ取り込む）。**無料枠では送った内容が Google のサービス改善に使われることがあります。**

作業はリモートデスクトップで kltech04 に入って行います。所要時間は1〜2時間ほど。

---

## 1. WSL2 と Ubuntu を入れる

1. スタートメニューで「PowerShell」を右クリック →「管理者として実行」
2. 次を実行して、終わったら PC を再起動
   ```powershell
   wsl --install -d Ubuntu
   ```
3. 再起動後に「Ubuntu」が自動で開くので、Linux 用のユーザー名とパスワードを決める（Windows とは別。忘れないように）

> 「仮想化が無効」と出た場合は BIOS で Intel VT-x / AMD-V を有効にする必要があります（情報システム担当に相談）。

## 2. アプリを取得して起動する

スタートメニューから「Ubuntu」を開き、次を順に実行します。

```sh
sudo apt update && sudo apt install -y git
git clone https://github.com/manabuando-cloud/mente.git ~/setsubi-navi
cd ~/setsubi-navi
./deploy/windows/navi.sh setup
```

`setup` は途中で2回「開き直してから再実行してください」と止まります（WSL の設定変更と Docker のインストールのため）。そのたびに指示どおりにして、同じコマンドをもう一度実行してください。

- 1回目: PowerShell で `wsl --shutdown` → Ubuntu を開き直す → `cd ~/setsubi-navi && ./deploy/windows/navi.sh setup`
- 2回目: Ubuntu を閉じて開き直す → 同じコマンド

3回目で起動まで進み、**管理者パスコード** が表示されます（控えておく）。kltech04 のブラウザで <http://localhost:8080> を開いて、名前を入れて使えれば成功です。

> リポジトリが非公開で `git clone` にパスワードを聞かれた場合は、GitHub の「Settings → Developer settings → Personal access tokens」で読み取り用のトークンを作り、パスワードの代わりに入力します。

## 3. 設定を入れる（Gemini / Slack）

```sh
nano ~/setsubi-navi/deploy/windows/.env
```

| 項目 | 入れるもの |
|---|---|
| `GEMINI_API_KEY` | [Google AI Studio](https://aistudio.google.com/apikey) で「APIキーを作成」（課金登録は不要） |
| `SLACK_WEBHOOK_URL` | Slack の Incoming Webhook URL（通知しないなら空のまま） |

保存（Ctrl+O → Enter → Ctrl+X）したら反映:

```sh
cd ~/setsubi-navi && ./deploy/windows/navi.sh update
./deploy/windows/navi.sh artisan navi:test-slack   # Slack に届くか確認
```

## 4. Google Drive に接続する（報告書PDFの取込み・リンク）

Google Cloud の **プロジェクトとサービスアカウントの作成は無料** です（課金の登録は不要）。

1. <https://console.cloud.google.com/> で新しいプロジェクトを作成（名前は例: `setsubi-navi`）
2. 「APIとサービス → ライブラリ」で **Google Drive API** を有効にする
3. 「IAMと管理 → サービスアカウント」で作成（名前は例: `navi-drive`、ロールは不要）
4. 作ったサービスアカウント →「鍵」→「鍵を追加 → 新しい鍵を作成 → JSON」→ ダウンロード
5. ダウンロードしたファイルの名前を `google-drive.json` に変えて `C:\setsubi-navi-import` フォルダに置き、Ubuntu でコピー
   ```sh
   cp /mnt/c/setsubi-navi-import/google-drive.json ~/setsubi-navi/deploy/windows/secrets/google-drive.json
   ```
   コピーしたら `C:\setsubi-navi-import\google-drive.json` は削除してください（鍵なので残さない）
6. Google Drive で共有ルートフォルダを、サービスアカウントのメールアドレス（`navi-drive@<プロジェクト>.iam.gserviceaccount.com`）に **閲覧者** で共有
7. 反映して確認
   ```sh
   cd ~/setsubi-navi && ./deploy/windows/navi.sh update
   ./deploy/windows/navi.sh artisan navi:sync-drive-machines --dry-run
   ```

> 4 で「鍵の作成が組織のポリシーで無効」と出た場合は、Google Workspace の管理者に「`iam.disableServiceAccountKeyCreation` をこのプロジェクトだけ解除」を依頼してください。

## 5. 旧GAS版のデータを移す

1. スプレッドシート「設備マスタ一覧（SOFTエクスポート）」を「ファイル → ダウンロード → CSV」で保存し、`設備マスタ一覧.csv` という名前にする
2. 「設備トラブルナビ_データ」の各シート（Cases / PendingCases / AiConsultations / Ratings）を、シートを開いた状態で同様に CSV で保存し、`Cases.csv` のようにシート名のファイル名にする
3. すべてを `C:\setsubi-navi-import` フォルダに入れて、Ubuntu で:
   ```sh
   cd ~/setsubi-navi && ./deploy/windows/navi.sh import /mnt/c/setsubi-navi-import
   ./deploy/windows/navi.sh artisan navi:ingest-vendor-reports --dry-run   # 業者別フォルダの振り分け確認（AIは使わない）
   ```

何度実行しても重複しません。

## 6. PC を再起動しても自動で動くようにする

エクスプローラーで `\\wsl$\Ubuntu\home\<Linuxのユーザー名>\setsubi-navi\deploy\windows` を開き、`install-autostart.ps1` を右クリック →「PowerShell で実行」（または管理者の PowerShell で下記）。

```powershell
cd \\wsl$\Ubuntu\home\<Linuxのユーザー名>\setsubi-navi\deploy\windows
Set-ExecutionPolicy -Scope Process Bypass
.\install-autostart.ps1
```

行うこと:
- 電源接続時にスリープしない設定
- WSL がアイドルで止まらない設定、社内LANから直接開けるネットワーク設定（`.wslconfig`）
- Windows 起動時に WSL（と Docker）を立ち上げるタスク（ログインしなくても動く。Windows のパスワードを聞かれます）
- ファイアウォールで 8080 番を許可

終わったら **再起動** し、ログインせずに数分待ってから、別のPCで <http://kltech04:8080> が開けることを確認します。

> - リモートデスクトップは「サインアウト」「切断」して構いません。**「シャットダウン」はしないでください。**
> - Windows 10 などで社内の別PCから開けない場合は、管理者の PowerShell で次を実行:
>   `netsh interface portproxy add v4tov4 listenport=8080 listenaddress=0.0.0.0 connectport=8080 connectaddress=127.0.0.1`

## 7. 社外から使えるようにする（任意）

ルーターのポートを開けずに、kltech04 から外向きにつなぐ方式です。どちらか一方を選びます。

### A. Cloudflare Tunnel（おすすめ・無料）
- 必要なもの: Cloudflare の無料アカウントと、**Cloudflare で管理しているドメイン**（会社のドメインが別の業者で管理されている場合は、新しくドメインを取る必要があり、これだけ年1,500円程度かかります）
- 手順: Cloudflare ダッシュボード →「Zero Trust → ネットワーク → トンネル → トンネルを作成（Cloudflared）」→ 表示されるトークンを `.env` の `TUNNEL_TOKEN` に貼る → 「パブリックホスト名」で `navi.<ドメイン>` → サービス `http://app:80`
- **強くおすすめ:** 「Zero Trust → Access → アプリケーション」で同じホスト名を登録し、ポリシーを「メールアドレスの末尾が `@g.kurashiki-laser.co.jp`」にする（50人まで無料）。これで社外公開しても、社員のメールにワンタイムコードが届く人しか開けなくなります

### B. ngrok（ドメインが無い場合）
- <https://ngrok.com/> の無料アカウントで「Domains」から無料の固定ドメイン（`xxxx.ngrok-free.app`）を1つ取得
- `.env` の `NGROK_AUTHTOKEN` と `NGROK_DOMAIN` を設定
- 無料プランは、初めて開くときに ngrok の確認画面が1回出ます。月間の通信量にも上限があります

どちらの場合も `.env` を次のように直してから反映します。

```dotenv
APP_URL=https://navi.<ドメイン>          # または https://xxxx.ngrok-free.app
SESSION_SECURE_COOKIE=true
```

```sh
cd ~/setsubi-navi && ./deploy/windows/navi.sh update
```

> 社外公開すると、URL を知っている人なら誰でも開けます。A の Access を設定するか、URL を社外に漏らさないようにしてください。

## 8. 普段の運用

| やりたいこと | Ubuntu で実行 |
|---|---|
| 動いているか確認 | `cd ~/setsubi-navi && ./deploy/windows/navi.sh status` |
| 新しいバージョンに更新 | `./deploy/windows/navi.sh update` |
| ログを見る | `./deploy/windows/navi.sh logs` |
| 今すぐバックアップ | `./deploy/windows/navi.sh backup`（毎日 1:30 にも自動） |
| 管理者パスコードを変える | `.env` の `NAVI_ADMIN_PASSCODE` を書き換えて `update` |

**バックアップ** は `C:\setsubi-navi-backup` に入ります（DB は30日分、写真は全部）。このフォルダを NAS や OneDrive / Google Drive にコピーしておくと、PC が壊れても戻せます。

**戻し方**（PC を入れ替えたときなど）: 新しい PC で 1〜3 を行い、バックアップを `C:\setsubi-navi-backup` に置いてから（`YYYYMMDD-HHMMSS` は戻したい日時のファイル名）、

```sh
cd ~/setsubi-navi/deploy/windows
docker compose stop app worker scheduler
docker compose run --rm --no-deps --entrypoint sh app -c '
  cp /backup/db/navi-YYYYMMDD-HHMMSS.sqlite storage/app/db/navi.sqlite &&
  rm -f storage/app/db/navi.sqlite-wal storage/app/db/navi.sqlite-shm &&
  mkdir -p storage/app/public && cp -rn /backup/photos storage/app/public/ &&
  chown -R www-data:www-data storage/app/db storage/app/public'
docker compose up -d
```

## トラブルシューティング

| 症状 | 確認すること |
|---|---|
| `http://kltech04:8080` が開けない | kltech04 で <http://localhost:8080> は開けるか → 開けるなら 6 のネットワーク設定・ファイアウォール |
| 再起動後に動いていない | タスクスケジューラで「SetsubiNavi-WSL」が実行されているか。Ubuntu で `./deploy/windows/navi.sh status` |
| Drive連携が「未設定」 | `deploy/windows/secrets/google-drive.json` があるか、`update` したか |
| 報告書の取込みが止まる | Gemini 無料枠の上限（429）。翌日に自動で続きから再開します |
| 画面が古いまま | `./deploy/windows/navi.sh update` のあと、ブラウザで Ctrl+F5 |
