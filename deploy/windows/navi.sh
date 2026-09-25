#!/usr/bin/env bash
# 設備トラブルナビ Windows PC 運用スクリプト（WSL2 の Ubuntu の中で実行する）
#
#   ./deploy/windows/navi.sh setup                … 初回: Docker Engine を入れて .env を作り、起動する
#   ./deploy/windows/navi.sh update               … 最新版に更新（git pull → 作り直して再起動）
#   ./deploy/windows/navi.sh status               … 動いているか確認
#   ./deploy/windows/navi.sh logs                 … ログを見る（Ctrl+C で終了）
#   ./deploy/windows/navi.sh import <Windowsのフォルダ>  … 旧GAS版のCSVを取り込む（例: /mnt/c/Users/ando/Desktop/移行）
#   ./deploy/windows/navi.sh artisan <コマンド...>  … 任意の artisan コマンド（例: navi:test-slack）
#   ./deploy/windows/navi.sh backup               … 今すぐバックアップ（毎日 1:30 にも自動で実行）
set -euo pipefail
cd "$(dirname "$0")"

compose() {
  local profiles=()
  grep -qE '^TUNNEL_TOKEN=.+' .env 2>/dev/null && profiles+=(--profile tunnel)
  grep -qE '^NGROK_AUTHTOKEN=.+' .env 2>/dev/null && profiles+=(--profile ngrok)
  docker compose "${profiles[@]}" "$@"
}

setup() {
  if ! grep -qi microsoft /proc/version 2>/dev/null; then
    echo "※ WSL の外で実行しています（Linux サーバーでもそのまま使えます）"
  fi

  if ! grep -q '^systemd=true' /etc/wsl.conf 2>/dev/null && grep -qi microsoft /proc/version 2>/dev/null; then
    echo "== WSL で systemd を有効にします（Docker を自動起動するため）"
    printf '[boot]\nsystemd=true\n' | sudo tee /etc/wsl.conf >/dev/null
    echo "   Windows の PowerShell で「wsl --shutdown」を実行し、もう一度 Ubuntu を開いてからこのコマンドを再実行してください"
    exit 0
  fi

  if ! command -v docker >/dev/null; then
    echo "== Docker Engine をインストールします（無料・Docker Desktop は使いません）"
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker "$USER"
    sudo systemctl enable --now docker
    echo "   ユーザーを docker グループに追加しました。Ubuntu を開き直してからこのコマンドを再実行してください"
    exit 0
  fi

  if [ ! -f .env ]; then
    echo "== 設定ファイル deploy/windows/.env を作ります"
    cp env.example .env
    sed -i "s#^APP_KEY=.*#APP_KEY=base64:$(head -c 32 /dev/urandom | base64)#" .env
    local passcode
    passcode="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 10)"
    sed -i "s#^NAVI_ADMIN_PASSCODE=.*#NAVI_ADMIN_PASSCODE=${passcode}#" .env
    echo "   管理者パスコード: ${passcode}  （.env の NAVI_ADMIN_PASSCODE でいつでも確認・変更できます）"
    echo "   Gemini のキーなどは .env を編集して入れてください:  nano deploy/windows/.env"
  fi
  mkdir -p secrets
  local backup_dir
  backup_dir="$(grep -E '^BACKUP_DIR=' .env | cut -d= -f2-)"
  mkdir -p "${backup_dir:-/mnt/c/setsubi-navi-backup}"

  echo "== ビルドして起動します（初回は10分ほどかかります）"
  compose up -d --build
  status
  local port
  port="$(grep -E '^APP_PORT=' .env | cut -d= -f2- || true)"
  echo
  echo "このPCのブラウザで http://localhost:${port:-8080} を開いてください"
}

status() {
  compose ps --format 'table {{.Service}}\t{{.Status}}'
}

case "${1:-}" in
  setup) setup ;;
  update) git -C ../.. pull --ff-only && compose up -d --build && status ;;
  status) status ;;
  logs) compose logs -f --tail=100 ;;
  backup) compose exec app php artisan navi:backup ;;
  artisan) shift; compose exec app php artisan "$@" ;;
  import)
    dir="${2:?取り込むCSVのあるフォルダを指定してください（例: /mnt/c/Users/you/Desktop/移行）}"
    run() { compose run --rm -T -v "${dir}:/import:ro" app php artisan "$@"; }
    [ -f "$dir/設備マスタ一覧.csv" ] && run navi:import --soft=/import/設備マスタ一覧.csv
    [ -f "$dir/Cases.csv" ] && run navi:import --cases=/import/Cases.csv
    [ -f "$dir/PendingCases.csv" ] && run navi:import --pending=/import/PendingCases.csv
    [ -f "$dir/AiConsultations.csv" ] && run navi:import --consultations=/import/AiConsultations.csv
    [ -f "$dir/Ratings.csv" ] && run navi:import --ratings=/import/Ratings.csv
    echo "== Drive の機械フォルダから機種マスタを補完"
    compose exec -T app php artisan navi:sync-drive-machines || echo "（Drive が未設定なら後で ./deploy/windows/navi.sh artisan navi:sync-drive-machines）"
    ;;
  *) sed -n '2,11p' "$0"; exit 1 ;;
esac
