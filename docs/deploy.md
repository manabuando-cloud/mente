# 本番デプロイ手順

社内サーバー（Linux + Docker）に `compose.yaml` で載せる前提の手順。
1つのイメージを **Web（app）/ キューワーカー（worker）/ スケジューラ（scheduler）** の3役で使い、DB は MySQL 8.4。

```
               ┌──────── app（Apache + PHP） ← ブラウザ（社内 / VPN）
 MySQL（db） ──┼──────── worker（Drive連携の「今すぐ実行」を処理）
               └──────── scheduler（毎日 2:10 / 2:40 / 3:10 の自動取込み・リンク）
 storage ボリューム: 写真・ログ（app / worker / scheduler で共有）
```

## 1. 事前に用意するもの

| もの | 作り方 |
|---|---|
| Google OAuth クライアント | Google Cloud Console →「APIとサービス」→「認証情報」→ OAuth クライアント ID（ウェブアプリケーション）。承認済みのリダイレクト URI に `https://<ホスト名>/auth/google/callback`。OAuth 同意画面は「内部」にする |
| Drive 用サービスアカウント | 同じプロジェクトで「サービスアカウント」を作成 → JSON キーを発行 → Google Drive API を有効化。共有ルートフォルダ（`1BwmHW_…`）をサービスアカウントのメールアドレスに **閲覧者** で共有 |
| Gemini API キー | Google AI Studio で発行。報告書PDFの取込み件数が多いので従量課金を推奨 |
| Slack Incoming Webhook | 通知先チャンネルの Incoming Webhook URL |

## 2. サーバーでの初回セットアップ

```sh
git clone https://github.com/manabuando-cloud/mente.git setsubi-navi && cd setsubi-navi
cp .env.example .env
mkdir -p secrets && cp /path/to/service-account.json secrets/google-drive.json
chmod 600 .env secrets/google-drive.json
```

`.env` を本番用に書き換える（最低限）:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://navi.example.co.jp       # 実際のURL
APP_KEY=                                  # 下のコマンドで生成して貼る

DB_CONNECTION=mysql
DB_DATABASE=navi
DB_USERNAME=navi
DB_PASSWORD=（長いランダム文字列）
DB_ROOT_PASSWORD=（別の長いランダム文字列）

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true                # https で運用する場合
QUEUE_CONNECTION=database
CACHE_STORE=database

GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
NAVI_ALLOWED_DOMAIN=g.kurashiki-laser.co.jp
NAVI_ADMIN_EMAILS=manabu.ando@g.kurashiki-laser.co.jp
NAVI_DEV_LOGIN=false

GEMINI_API_KEY=...
SLACK_WEBHOOK_URL=...
NAVI_INGEST_BATCH_LIMIT=15                # 従量課金にしたら増やしてよい
```

`APP_KEY` の生成（イメージのビルド後）:

```sh
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show
# 出力された base64:... を .env の APP_KEY に貼る
```

起動:

```sh
docker compose up -d
docker compose ps          # app が healthy になるまで待つ（初回はマイグレーションが走る）
```

`http://<サーバー>:8080`（`APP_PORT` で変更可）で開ける。社外に出さない場合でも、社内のリバースプロキシ（nginx 等）で https 化して `APP_URL` をその URL にすること（Google ログインのリダイレクト URI と一致させる必要がある）。

## 3. 旧GAS版からのデータ移行（初回だけ）

スプレッドシートを CSV で書き出して `secrets/import/` などに置き、コンテナにマウントして取り込む。

```sh
docker compose run --rm -v "$PWD/secrets/import:/import:ro" app php artisan navi:import --soft=/import/設備マスタ一覧.csv
docker compose run --rm -v "$PWD/secrets/import:/import:ro" app php artisan navi:import --cases=/import/Cases.csv
docker compose run --rm -v "$PWD/secrets/import:/import:ro" app php artisan navi:import --pending=/import/PendingCases.csv --consultations=/import/AiConsultations.csv
docker compose exec app php artisan navi:sync-drive-machines
docker compose exec app php artisan navi:ingest-vendor-reports --dry-run   # 振り分けの確認（AIは使わない）
```

移行後、Google ログインして「Drive連携」画面の業者別フォルダの対応表を確認する。以降は scheduler が毎日自動で取り込む。

## 4. 動作確認

```sh
docker compose exec app php artisan navi:test-slack     # Slack に届くか
docker compose exec app php artisan about               # 設定の確認
docker compose logs -f worker scheduler                 # ジョブ・スケジュールのログ
```

## 5. 更新（新しいバージョンを反映）

```sh
git pull
docker compose up -d --build     # app 起動時にマイグレーションが自動で走る（RUN_MIGRATIONS=false で無効化）
```

## 6. バックアップ

- DB: `docker compose exec db mysqldump -u root -p"$DB_ROOT_PASSWORD" --single-transaction navi > backup/navi-$(date +%F).sql` を cron で毎日
- 写真: `storage` ボリューム（`docker run --rm -v setsubi-navi_storage:/s -v "$PWD/backup:/b" alpine tar czf /b/storage-$(date +%F).tgz -C /s app/public`）
- `.env` と `secrets/` は別途安全な場所に保管

## トラブルシューティング

| 症状 | 確認すること |
|---|---|
| Google ログイン後にエラー | リダイレクト URI と `APP_URL` の一致、OAuth 同意画面が「内部」か、`NAVI_ALLOWED_DOMAIN` |
| Drive連携が「未設定」 | `secrets/google-drive.json` がマウントされているか、ルートフォルダをサービスアカウントに共有したか |
| 「今すぐ実行」が進まない | `docker compose logs worker`（worker が動いていないとキューに溜まったまま） |
| 取込みが途中で止まる | Gemini の 429（クォータ超過）。従量課金にするか `NAVI_INGEST_BATCH_LIMIT` を下げる |
| 写真が表示されない | `APP_URL` が実際の URL と一致しているか（写真の URL は `APP_URL` から作られる） |
