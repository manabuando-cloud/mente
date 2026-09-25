#!/usr/bin/env bash
# 設備トラブルナビ Google Cloud（Cloud Run）デプロイスクリプト
#
#   ./deploy/cloudrun/deploy.sh setup    … 初回だけ: API有効化・DB・バケット・秘密情報・サービスアカウント
#   ./deploy/cloudrun/deploy.sh deploy   … イメージをビルドして Web とジョブを更新（コード更新のたびに）
#   ./deploy/cloudrun/deploy.sh daily    … 毎日の自動処理を今すぐ1回実行
#   ./deploy/cloudrun/deploy.sh artisan navi:import --soft=/import/設備マスタ一覧.csv
#                                        … 任意の artisan コマンドをジョブとして実行（移行など）
#
# 事前に: gcloud CLI をインストールして `gcloud auth login`。設定は deploy/cloudrun/config.env に書く。
set -euo pipefail

cd "$(dirname "$0")/../.."
CONFIG="deploy/cloudrun/config.env"
[ -f "$CONFIG" ] || { echo "$CONFIG がありません。config.env.example をコピーして編集してください" >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"

: "${PROJECT_ID:?config.env に PROJECT_ID を設定してください}"
REGION="${REGION:-asia-northeast1}"
SERVICE="${SERVICE:-setsubi-navi}"
JOB="${SERVICE}-job"
DB_INSTANCE="${DB_INSTANCE:-navi-db}"
DB_TIER="${DB_TIER:-db-f1-micro}"
DB_NAME="${DB_NAME:-navi}"
DB_USER="${DB_USER:-navi}"
REPO="${REPO:-navi}"
RUNTIME_SA_NAME="${RUNTIME_SA_NAME:-navi-runtime}"
PHOTOS_BUCKET="${PHOTOS_BUCKET:-${PROJECT_ID}-navi-photos}"
IMPORT_BUCKET="${IMPORT_BUCKET:-${PROJECT_ID}-navi-import}"
DAILY_SCHEDULE="${DAILY_SCHEDULE:-10 2 * * *}"
AUTH_MODE="${AUTH_MODE:-open}"

RUNTIME_SA="${RUNTIME_SA_NAME}@${PROJECT_ID}.iam.gserviceaccount.com"
IMAGE_BASE="${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO}/app"
DB_CONN="${PROJECT_ID}:${REGION}:${DB_INSTANCE}"

gc() { gcloud --project "$PROJECT_ID" "$@"; }
secret_exists() { gc secrets describe "$1" >/dev/null 2>&1; }
put_secret() { # put_secret 名前 値（既にあれば新しいバージョンを追加）
  if secret_exists "$1"; then printf '%s' "$2" | gc secrets versions add "$1" --data-file=- >/dev/null
  else printf '%s' "$2" | gc secrets create "$1" --replication-policy=automatic --data-file=- >/dev/null; fi
}
rand() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }

setup() {
  echo "== API を有効化"
  gc services enable run.googleapis.com sqladmin.googleapis.com artifactregistry.googleapis.com \
    cloudbuild.googleapis.com secretmanager.googleapis.com cloudscheduler.googleapis.com \
    drive.googleapis.com storage.googleapis.com

  echo "== Artifact Registry"
  gc artifacts repositories describe "$REPO" --location "$REGION" >/dev/null 2>&1 \
    || gc artifacts repositories create "$REPO" --repository-format=docker --location "$REGION"

  echo "== 実行用サービスアカウント（Drive の共有先にもなる）"
  gc iam service-accounts describe "$RUNTIME_SA" >/dev/null 2>&1 \
    || gc iam service-accounts create "$RUNTIME_SA_NAME" --display-name="設備トラブルナビ 実行用"
  for role in roles/cloudsql.client roles/secretmanager.secretAccessor roles/run.invoker; do
    gc projects add-iam-policy-binding "$PROJECT_ID" --member="serviceAccount:${RUNTIME_SA}" --role="$role" --condition=None >/dev/null
  done

  echo "== Cloud SQL（MySQL 8.4 / ${DB_TIER}）※作成に数分かかります"
  gc sql instances describe "$DB_INSTANCE" >/dev/null 2>&1 \
    || gc sql instances create "$DB_INSTANCE" --database-version=MYSQL_8_4 --edition=ENTERPRISE \
         --tier="$DB_TIER" --region="$REGION" --storage-size=10 --storage-auto-increase \
         --backup-start-time=18:00 --database-flags=character_set_server=utf8mb4,default_time_zone=+09:00
  gc sql databases describe "$DB_NAME" --instance "$DB_INSTANCE" >/dev/null 2>&1 \
    || gc sql databases create "$DB_NAME" --instance "$DB_INSTANCE" --charset=utf8mb4 --collation=utf8mb4_0900_ai_ci
  if ! secret_exists navi-db-password; then
    DB_PASSWORD="$(rand 32)"
    gc sql users create "$DB_USER" --instance "$DB_INSTANCE" --password "$DB_PASSWORD" --host=%
    put_secret navi-db-password "$DB_PASSWORD"
  fi

  echo "== Cloud Storage（写真 / 移行用CSV）"
  gc storage buckets describe "gs://${PHOTOS_BUCKET}" >/dev/null 2>&1 \
    || gc storage buckets create "gs://${PHOTOS_BUCKET}" --location "$REGION" --uniform-bucket-level-access
  gc storage buckets describe "gs://${IMPORT_BUCKET}" >/dev/null 2>&1 \
    || gc storage buckets create "gs://${IMPORT_BUCKET}" --location "$REGION" --uniform-bucket-level-access
  for b in "$PHOTOS_BUCKET" "$IMPORT_BUCKET"; do
    gc storage buckets add-iam-policy-binding "gs://${b}" --member="serviceAccount:${RUNTIME_SA}" --role=roles/storage.objectAdmin >/dev/null
  done

  echo "== 秘密情報（Secret Manager）"
  secret_exists navi-app-key || put_secret navi-app-key "base64:$(head -c 32 /dev/urandom | base64)"
  if ! secret_exists navi-admin-passcode; then
    PASSCODE="${ADMIN_PASSCODE:-$(rand 12)}"
    put_secret navi-admin-passcode "$PASSCODE"
    echo "   管理者パスコード: ${PASSCODE}  ← 管理者に伝えてください（Secret Manager の navi-admin-passcode でも確認できます）"
  fi
  secret_exists navi-gemini-api-key || put_secret navi-gemini-api-key "${GEMINI_API_KEY:-}"
  secret_exists navi-slack-webhook-url || put_secret navi-slack-webhook-url "${SLACK_WEBHOOK_URL:-}"

  cat <<MSG

== setup 完了
次の2つを行ってから ./deploy/cloudrun/deploy.sh deploy を実行してください:
  1. Google Drive の共有ルートフォルダを ${RUNTIME_SA} に「閲覧者」で共有
  2. Gemini / Slack のキーをまだ入れていなければ:
       printf '%s' 'キー' | gcloud secrets versions add navi-gemini-api-key --data-file=- --project ${PROJECT_ID}
       printf '%s' 'URL'  | gcloud secrets versions add navi-slack-webhook-url --data-file=- --project ${PROJECT_ID}
MSG
}

common_env() {
  local url="$1"
  printf '%s' "APP_NAME=設備トラブルナビ,APP_ENV=production,APP_DEBUG=false,APP_URL=${url},APP_LOCALE=ja,APP_TIMEZONE=Asia/Tokyo,"
  printf '%s' "LOG_CHANNEL=stderr,DB_CONNECTION=mysql,DB_SOCKET=/cloudsql/${DB_CONN},DB_DATABASE=${DB_NAME},DB_USERNAME=${DB_USER},"
  printf '%s' "SESSION_DRIVER=database,SESSION_COOKIE=navi_session,SESSION_SECURE_COOKIE=true,CACHE_STORE=database,CACHE_PREFIX=navi-cache-,"
  printf '%s' "QUEUE_CONNECTION=sync,TRUSTED_PROXIES=*,NAVI_AUTH_MODE=${AUTH_MODE},NAVI_DRIVE_USE_ADC=true,NAVI_PHOTOS_DISK=public,"
  printf '%s' "NAVI_ALLOWED_DOMAIN=${ALLOWED_DOMAIN:-g.kurashiki-laser.co.jp},NAVI_ADMIN_EMAILS=${ADMIN_EMAILS:-},NAVI_INGEST_BATCH_LIMIT=${INGEST_BATCH_LIMIT:-15},"
  printf '%s' "GEMINI_MODEL=${GEMINI_MODEL:-gemini-2.5-flash}"
  [ -n "${GOOGLE_CLIENT_ID:-}" ] && printf '%s' ",GOOGLE_CLIENT_ID=${GOOGLE_CLIENT_ID},GOOGLE_REDIRECT_URI=${url}/auth/google/callback"
  return 0
}
SECRETS="APP_KEY=navi-app-key:latest,DB_PASSWORD=navi-db-password:latest,NAVI_ADMIN_PASSCODE=navi-admin-passcode:latest,GEMINI_API_KEY=navi-gemini-api-key:latest,SLACK_WEBHOOK_URL=navi-slack-webhook-url:latest"

deploy() {
  local tag image url
  tag="$(git rev-parse --short HEAD)$(git diff --quiet || echo -dirty)"
  image="${IMAGE_BASE}:${tag}"

  echo "== イメージをビルド（Cloud Build）: ${image}"
  gc builds submit --tag "$image" .

  local secrets="$SECRETS"
  if [ -n "${GOOGLE_CLIENT_ID:-}" ] && secret_exists navi-google-client-secret; then
    secrets="${secrets},GOOGLE_CLIENT_SECRET=navi-google-client-secret:latest"
  fi

  url="$(gc run services describe "$SERVICE" --region "$REGION" --format='value(status.url)' 2>/dev/null || true)"

  echo "== Web（Cloud Run サービス）"
  gc run deploy "$SERVICE" --image "$image" --region "$REGION" --service-account "$RUNTIME_SA" \
    --execution-environment gen2 --allow-unauthenticated --port 8080 \
    --cpu 1 --memory 1Gi --timeout 900 --concurrency 40 --min-instances 0 --max-instances 3 \
    --add-cloudsql-instances "$DB_CONN" \
    --set-env-vars "CONTAINER_ROLE=web,$(common_env "${url:-https://example.invalid}")" \
    --set-secrets "$secrets" \
    --add-volume "name=photos,type=cloud-storage,bucket=${PHOTOS_BUCKET},mount-options=uid=33;gid=33" \
    --add-volume-mount "volume=photos,mount-path=/var/www/html/storage/app/public"

  # 初回は URL がデプロイ後に決まるので、APP_URL を入れ直す
  local new_url
  new_url="$(gc run services describe "$SERVICE" --region "$REGION" --format='value(status.url)')"
  if [ "$new_url" != "$url" ]; then
    gc run services update "$SERVICE" --region "$REGION" --update-env-vars "APP_URL=${new_url}" >/dev/null
  fi

  echo "== 毎日の自動処理（Cloud Run ジョブ）"
  gc run jobs deploy "$JOB" --image "$image" --region "$REGION" --service-account "$RUNTIME_SA" \
    --execution-environment gen2 --cpu 1 --memory 1Gi --task-timeout 3600 --max-retries 0 \
    --set-cloudsql-instances "$DB_CONN" \
    --set-env-vars "CONTAINER_ROLE=job,$(common_env "$new_url")" \
    --set-secrets "$secrets" \
    --add-volume "name=photos,type=cloud-storage,bucket=${PHOTOS_BUCKET},mount-options=uid=33;gid=33" \
    --add-volume-mount "volume=photos,mount-path=/var/www/html/storage/app/public" \
    --add-volume "name=import,type=cloud-storage,bucket=${IMPORT_BUCKET},readonly=true" \
    --add-volume-mount "volume=import,mount-path=/import" \
    --args "php,artisan,navi:daily"

  echo "== Cloud Scheduler（毎日 ${DAILY_SCHEDULE} 日本時間）"
  local uri="https://run.googleapis.com/v2/projects/${PROJECT_ID}/locations/${REGION}/jobs/${JOB}:run"
  if gc scheduler jobs describe "${JOB}-daily" --location "$REGION" >/dev/null 2>&1; then
    gc scheduler jobs update http "${JOB}-daily" --location "$REGION" --schedule "$DAILY_SCHEDULE" --time-zone Asia/Tokyo \
      --uri "$uri" --http-method POST --oauth-service-account-email "$RUNTIME_SA" >/dev/null
  else
    gc scheduler jobs create http "${JOB}-daily" --location "$REGION" --schedule "$DAILY_SCHEDULE" --time-zone Asia/Tokyo \
      --uri "$uri" --http-method POST --oauth-service-account-email "$RUNTIME_SA" >/dev/null
  fi

  echo
  echo "== デプロイ完了: ${new_url}"
}

artisan() { # 任意の artisan コマンドをジョブで実行して終わるまで待つ
  local args="php,artisan"
  for a in "$@"; do args="${args},${a//,/\\,}"; done
  gc run jobs execute "$JOB" --region "$REGION" --args "$args" --wait
}

case "${1:-}" in
  setup) setup ;;
  deploy) deploy ;;
  daily) gc run jobs execute "$JOB" --region "$REGION" --wait ;;
  artisan) shift; artisan "$@" ;;
  *) sed -n '2,11p' "$0"; exit 1 ;;
esac
