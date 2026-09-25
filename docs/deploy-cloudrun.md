# Google Cloud（Cloud Run）へのデプロイ

```
 ブラウザ ──https──▶ Cloud Run サービス（Web）──┬─▶ Cloud SQL（MySQL 8.4）
                                                ├─▶ Cloud Storage（写真）
                                                └─▶ Google Drive / Gemini / Slack
 Cloud Scheduler（毎日 2:10）──▶ Cloud Run ジョブ（navi:daily：報告書の取込み・自動リンク）
 Secret Manager: APP_KEY / DBパスワード / 管理者パスコード / Gemini / Slack
```

- 最初は **「URLを知っている人なら誰でも使える」モード**（`AUTH_MODE=open`）。初回に名前だけ入力して使い始める。
  取込レビュー・Drive連携・削除などの管理機能は **管理者パスコード** を入れた人だけ。検索エンジンには載らない（`X-Robots-Tag: noindex`）。
  URL が漏れると誰でも使えるので、社内でだけ共有すること。後から Google ログインに切り替えられる（下記）。
- Drive へは Cloud Run の**実行用サービスアカウント**で接続する（鍵ファイルは作らない）。共有ルートフォルダをそのアカウントに共有するだけ。
- 費用の目安: Cloud SQL（db-f1-micro）が月 1,500〜2,000 円程度。Cloud Run・Storage・Scheduler は社内利用の規模ならほぼ無料枠内。

## 1. 準備（初回だけ）

1. Google Cloud のプロジェクトを用意し、課金を有効にする
2. 手元の PC（またはCloud Shell）に [gcloud CLI](https://cloud.google.com/sdk/docs/install) を入れて `gcloud auth login`
   - **Cloud Shell**（ブラウザの Google Cloud コンソール右上の `>_`）なら gcloud も git も最初から入っている
3. リポジトリを取得して設定ファイルを作る
   ```sh
   git clone https://github.com/manabuando-cloud/mente.git && cd mente
   cp deploy/cloudrun/config.env.example deploy/cloudrun/config.env
   # PROJECT_ID を書き換える。Gemini / Slack のキーがあれば入れる（後からでも良い）
   ```

## 2. インフラを作る（初回だけ・10分ほど）

```sh
./deploy/cloudrun/deploy.sh setup
```

API の有効化、Cloud SQL、写真用・移行用のバケット、Secret Manager、実行用サービスアカウントを作る。
最後に **管理者パスコード** と、Drive を共有すべきサービスアカウント（`navi-runtime@<プロジェクト>.iam.gserviceaccount.com`）が表示される。

→ **Google Drive で共有ルートフォルダ（`1BwmHW_…`）をそのサービスアカウントに「閲覧者」で共有する。**

## 3. デプロイ（コードを更新するたびに）

```sh
./deploy/cloudrun/deploy.sh deploy
```

Cloud Build でイメージを作り、Web（Cloud Run サービス）と毎日の処理（Cloud Run ジョブ + Cloud Scheduler）を更新する。
最後に表示される `https://setsubi-navi-xxxxx.a.run.app` が利用 URL。DB の更新（マイグレーション）は Web の起動時に自動で行われる。

## 4. 旧GAS版からのデータ移行（初回だけ）

スプレッドシートを CSV で書き出し、移行用バケットに置いてからジョブで取り込む（移行用バケットは公開されない）。

```sh
gcloud storage cp 設備マスタ一覧.csv Cases.csv PendingCases.csv AiConsultations.csv gs://<PROJECT_ID>-navi-import/

./deploy/cloudrun/deploy.sh artisan navi:import --soft=/import/設備マスタ一覧.csv
./deploy/cloudrun/deploy.sh artisan navi:import --cases=/import/Cases.csv
./deploy/cloudrun/deploy.sh artisan navi:import --pending=/import/PendingCases.csv --consultations=/import/AiConsultations.csv
./deploy/cloudrun/deploy.sh artisan navi:sync-drive-machines
./deploy/cloudrun/deploy.sh artisan navi:ingest-vendor-reports --dry-run    # 振り分けの確認（AIは使わない）
```

結果は Google Cloud コンソールの「Cloud Run → ジョブ → 実行履歴 → ログ」で見られる。

## 5. 運用

| やりたいこと | 方法 |
|---|---|
| 毎日の自動処理を今すぐ実行 | `./deploy/cloudrun/deploy.sh daily`、または Drive連携画面の「今すぐ実行」 |
| 管理者パスコードを変える | `printf '%s' '新しい値' \| gcloud secrets versions add navi-admin-passcode --data-file=-` → `deploy` し直す |
| Gemini / Slack のキーを入れる・変える | `navi-gemini-api-key` / `navi-slack-webhook-url` に同様に追加 → `deploy` |
| ログを見る | コンソールの Cloud Run → サービス / ジョブ → ログ |
| DB のバックアップ | Cloud SQL の自動バックアップ（setup で毎日有効化済み）。コンソールから復元できる |
| 写真 | `gs://<PROJECT_ID>-navi-photos` に保存されている |

「今すぐ実行」はその場で処理する（Cloud Run にはキューワーカーを置かない）。件数が多いと数分かかるので、画面を閉じずに待つ。

## 6. Google ログインに切り替える（後で）

1. コンソールの「APIとサービス → OAuth 同意画面」を **内部** で作成
2. 「認証情報 → OAuth クライアント ID（ウェブアプリケーション）」を作成し、承認済みのリダイレクト URI に `https://<利用URL>/auth/google/callback`
3. クライアントシークレットを保存
   ```sh
   printf '%s' 'クライアントシークレット' | gcloud secrets create navi-google-client-secret --data-file=-
   gcloud secrets add-iam-policy-binding navi-google-client-secret --member=serviceAccount:navi-runtime@<PROJECT_ID>.iam.gserviceaccount.com --role=roles/secretmanager.secretAccessor
   ```
4. `config.env` に `AUTH_MODE=google`、`GOOGLE_CLIENT_ID=...`、`ADMIN_EMAILS=manabu.ando@g.kurashiki-laser.co.jp` を書いて `deploy`

`@g.kurashiki-laser.co.jp` のアカウントだけがログインでき、管理者は `ADMIN_EMAILS` の人になる。

## トラブルシューティング

| 症状 | 確認すること |
|---|---|
| Drive連携で「default credentials were not found」 | ジョブ／サービスの実行サービスアカウントが `navi-runtime` か（`deploy` で設定される） |
| Drive連携で 404 / 権限エラー | 共有ルートフォルダを `navi-runtime@…` に共有したか |
| 起動しない（Cloud Run のログに DB 接続エラー） | Cloud SQL インスタンス名（`DB_INSTANCE`）・`navi-db-password` の値 |
| 写真が保存できない | 写真バケットに `navi-runtime` の権限があるか（setup で付与） |
| 取込みが途中で止まる | Gemini の 429（クォータ超過）。従量課金にするか `INGEST_BATCH_LIMIT` を下げて `deploy` |
