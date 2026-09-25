# Windows PC（kltech04）で動かす ― 費用ゼロの構成

```
 社員のPC・スマホ（社内・社外とも）
        │ https://xxxx.ngrok-free.app   ← 会社の Google アカウントでログイン
        ▼
 ngrok（無料の固定ドメイン）… ルーターのポート開放は不要
        ▼
 kltech04（Windows）
  └ WSL2 の Ubuntu
     └ Docker Engine（無料）
        ├ app        … 画面（Web）
        ├ worker     … Drive連携の「今すぐ実行」
        ├ scheduler  … 毎日 1:30 バックアップ / 2:10・2:40 報告書取込み / 3:10 自動リンク
        └ ngrok      … 公開URL
  DB: SQLite（1ファイル）、写真: Docker ボリューム、バックアップ: C:\setsubi-navi-backup
```

- **費用はかかりません。** Docker Desktop は大きな会社では有料なので使わず、WSL2 の中に無料の Docker Engine を入れます。
- **ログインは会社の Google アカウント（`@g.kurashiki-laser.co.jp`）だけ。** URL が漏れても社員以外は入れません。管理者（取込レビュー・Drive連携・削除など）は `.env` の `NAVI_ADMIN_EMAILS` に書いたアドレスの人です。
- Google ログインは **https の公開URL** でしか使えない（Google の決まり）ため、**社内の人も公開URL（`https://xxxx.ngrok-free.app`）で開きます**。kltech04 本体からは <http://localhost:8080> でも使えます。
- Gemini は無料枠（呼び出し間隔を7秒空け、1日15件ずつ取り込む）。**無料枠では送った内容が Google のサービス改善に使われることがあります。**

作業はリモートデスクトップで kltech04 に入って行います。所要時間は2時間ほど。

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

3回目で起動まで進みます。kltech04 のブラウザで <http://localhost:8080> を開き、ログイン画面に「Googleログインの設定がまだ済んでいません」と出れば、ここまでは成功です（ログインは 3〜4 章のあとで使えるようになります）。

> リポジトリが非公開で `git clone` にパスワードを聞かれた場合は、GitHub の「Settings → Developer settings → Personal access tokens」で読み取り用のトークンを作り、パスワードの代わりに入力します。

## 3. 公開URLを作る（ngrok・無料）

1. <https://ngrok.com/> で無料アカウントを作る（会社の Google アカウントでサインアップできます）
2. ダッシュボードの「Your Authtoken」をコピー
3. 「Domains」→「New Domain」で無料の固定ドメイン（例: `kurashiki-navi.ngrok-free.app`）を1つ作る
4. 設定ファイルを開いて入れる
   ```sh
   nano ~/setsubi-navi/deploy/windows/.env
   ```
   ```dotenv
   APP_URL=https://kurashiki-navi.ngrok-free.app      # 3 で作ったドメイン
   NGROK_AUTHTOKEN=（2 のトークン）
   NGROK_DOMAIN=kurashiki-navi.ngrok-free.app
   ```
   保存は Ctrl+O → Enter → Ctrl+X。

> ngrok の無料プランは、ブラウザで初めて開いたときに確認画面が1回出ます（「Visit Site」を押す）。月間の通信量にも上限がありますが、この用途なら通常は足ります。
> 会社のドメインを Cloudflare で管理している場合は、代わりに Cloudflare Tunnel（`TUNNEL_TOKEN`）も使えます（8章）。

## 4. Google ログインを設定する（会社アカウントだけ）

Google Cloud の **プロジェクトと OAuth クライアントの作成は無料** です（課金の登録は不要）。会社の Google アカウントで操作します。

1. <https://console.cloud.google.com/> で新しいプロジェクトを作成（名前は例: `setsubi-navi`。組織は `kurashiki-laser` を選ぶ）
2. 「APIとサービス → OAuth 同意画面」（「Google Auth Platform」と表示される場合もあります）
   - ユーザーの種類: **内部**（会社のアカウントだけになる。Workspace があるので選べます）
   - アプリ名: 設備トラブルナビ、サポートメール: 自分のアドレス
3. 「認証情報 → 認証情報を作成 → OAuth クライアント ID」
   - アプリケーションの種類: **ウェブ アプリケーション**
   - 承認済みのリダイレクト URI に次の2つを追加
     - `https://kurashiki-navi.ngrok-free.app/auth/google/callback`（3 のドメイン）
     - `http://localhost:8080/auth/google/callback`（kltech04 本体から使う用）
4. 表示された **クライアント ID** と **クライアント シークレット** を `.env` に入れる
   ```dotenv
   GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
   NAVI_ADMIN_EMAILS=manabu.ando@g.kurashiki-laser.co.jp     # 管理者（カンマ区切りで複数可）
   ```

## 5. Gemini / Slack を設定する

| `.env` の項目 | 入れるもの |
|---|---|
| `GEMINI_API_KEY` | [Google AI Studio](https://aistudio.google.com/apikey) で「APIキーを作成」（課金登録は不要。4 と同じプロジェクトを選んでよい） |
| `SLACK_WEBHOOK_URL` | Slack の Incoming Webhook URL（通知しないなら空のまま） |

3〜5 を入れたら反映して確認します。

```sh
cd ~/setsubi-navi && ./deploy/windows/navi.sh update
./deploy/windows/navi.sh status                    # ngrok も Up になっているか
./deploy/windows/navi.sh artisan navi:test-slack   # Slack に届くか
```

別のPC（またはスマホ）で `https://kurashiki-navi.ngrok-free.app` を開き、「Googleアカウントでログイン」で入れれば成功です。会社以外のアカウントでは入れないことも確認してください。

## 6. Google Drive に接続する（報告書PDFの取込み・リンク）

Google Cloud の **プロジェクトとサービスアカウントの作成は無料** です（課金の登録は不要）。

1. 4 で作ったプロジェクトを開く
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

> 鍵の作成で「組織のポリシーで無効」と出た場合は、Google Workspace の管理者に「`iam.disableServiceAccountKeyCreation` をこのプロジェクトだけ解除」を依頼してください。

## 7. 旧GAS版のデータを移す

1. スプレッドシート「設備マスタ一覧（SOFTエクスポート）」を「ファイル → ダウンロード → CSV」で保存し、`設備マスタ一覧.csv` という名前にする
2. 「設備トラブルナビ_データ」の各シート（Cases / PendingCases / AiConsultations / Ratings）を、シートを開いた状態で同様に CSV で保存し、`Cases.csv` のようにシート名のファイル名にする
3. すべてを `C:\setsubi-navi-import` フォルダに入れて、Ubuntu で:
   ```sh
   cd ~/setsubi-navi && ./deploy/windows/navi.sh import /mnt/c/setsubi-navi-import
   ./deploy/windows/navi.sh artisan navi:ingest-vendor-reports --dry-run   # 業者別フォルダの振り分け確認（AIは使わない）
   ```

何度実行しても重複しません。

## 8. PC を再起動しても自動で動くようにする

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

終わったら **再起動** し、ログインせずに数分待ってから、別のPCで公開URL（`https://kurashiki-navi.ngrok-free.app`）が開けることを確認します。

> - リモートデスクトップは「サインアウト」「切断」して構いません。**「シャットダウン」はしないでください。**

## 9. （参考）ngrok の代わりに Cloudflare Tunnel を使う

会社のドメインを Cloudflare で管理している（または新しく取る）場合は、ngrok の確認画面や通信量の上限が無い Cloudflare Tunnel（無料）も使えます。

1. Cloudflare ダッシュボード →「Zero Trust → ネットワーク → トンネル → トンネルを作成（Cloudflared）」
2. 表示されるトークンを `.env` の `TUNNEL_TOKEN` に入れ、`NGROK_AUTHTOKEN` は空にする
3. 「パブリックホスト名」で `navi.<ドメイン>` → サービス `http://app:80`
4. `APP_URL` と、Google の OAuth クライアントのリダイレクト URI を `https://navi.<ドメイン>/auth/google/callback` に変えて `update`

## 10. 普段の運用

| やりたいこと | Ubuntu で実行 |
|---|---|
| 動いているか確認 | `cd ~/setsubi-navi && ./deploy/windows/navi.sh status` |
| 新しいバージョンに更新 | `./deploy/windows/navi.sh update` |
| ログを見る | `./deploy/windows/navi.sh logs` |
| 今すぐバックアップ | `./deploy/windows/navi.sh backup`（毎日 1:30 にも自動） |
| 管理者を追加・変更する | `.env` の `NAVI_ADMIN_EMAILS` を書き換えて `update`（カンマ区切り） |

**バックアップ** は `C:\setsubi-navi-backup` に入ります（DB は30日分、写真は全部）。このフォルダを NAS や OneDrive / Google Drive にコピーしておくと、PC が壊れても戻せます。

**戻し方**（PC を入れ替えたときなど）: 新しい PC で 1〜5 を行い、バックアップを `C:\setsubi-navi-backup` に置いてから（`YYYYMMDD-HHMMSS` は戻したい日時のファイル名）、

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
| 公開URLが開けない | kltech04 で <http://localhost:8080> は開けるか → 開けるなら `./deploy/windows/navi.sh status` で ngrok が Up か、`.env` の `NGROK_*` |
| Google ログインで「redirect_uri_mismatch」 | OAuth クライアントのリダイレクト URI が、開いたURL + `/auth/google/callback` と完全に一致しているか（https / 末尾まで） |
| 「@g.kurashiki-laser.co.jp のアカウントでログインしてください」 | 会社以外のアカウントでログインしている。ブラウザで会社アカウントに切り替える |
| 管理メニュー（取込レビュー・Drive連携）が出ない | `.env` の `NAVI_ADMIN_EMAILS` に自分のアドレスがあるか → `update` → ログインし直す |
| 再起動後に動いていない | タスクスケジューラで「SetsubiNavi-WSL」が実行されているか。Ubuntu で `./deploy/windows/navi.sh status` |
| Drive連携が「未設定」 | `deploy/windows/secrets/google-drive.json` があるか、`update` したか |
| 報告書の取込みが止まる | Gemini 無料枠の上限（429）。翌日に自動で続きから再開します |
| 画面が古いまま | `./deploy/windows/navi.sh update` のあと、ブラウザで Ctrl+F5 |
