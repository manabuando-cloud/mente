#!/bin/sh
set -e
cd /var/www/html

# Cloud Run などは待ち受けポートを PORT で渡してくる（既定は 80）
if [ -n "${PORT:-}" ] && [ "$PORT" != "80" ]; then
  sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
  sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# 永続ボリュームに載る storage 配下のディレクトリを用意
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
# Cloud Storage をマウントしたディレクトリは chown できないことがあるので失敗しても続ける
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# SQLite で運用する場合（Windows PC 1台構成など）はDBファイルが無ければ作る
if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ ! -f "$DB_DATABASE" ]; then
  mkdir -p "$(dirname "$DB_DATABASE")"
  touch "$DB_DATABASE"
  chown www-data:www-data "$(dirname "$DB_DATABASE")" "$DB_DATABASE"
fi

# 設定・ルート・画面のキャッシュ（.env の値を反映するので起動時に作る）
su -s /bin/sh www-data -c "php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache" 

# `docker compose run --rm app php artisan ...` のようにコマンドが渡されたらそれを実行する
# （storage のファイルの持ち主が root にならないよう www-data で実行）
as_www() { HOME=/tmp exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

if [ "$#" -gt 0 ]; then
  as_www "$@"
fi

case "${CONTAINER_ROLE:-web}" in
  web)
    if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
      # 複数インスタンスが同時に起動しても1回だけ実行されるよう --isolated（DBのロックを使う）。
      # ただしDBが空の初回はロック用テーブル（cache_locks）がまだ無いので、普通に実行して作る
      if su -s /bin/sh www-data -c "php artisan migrate:status" > /dev/null 2>&1; then
        su -s /bin/sh www-data -c "php artisan migrate --force --isolated"
      else
        su -s /bin/sh www-data -c "php artisan migrate --force"
      fi
    fi
    [ -L public/storage ] || php artisan storage:link
    exec apache2-foreground
    ;;
  worker)
    # Drive連携の「今すぐ実行」などのジョブ。1件あたり最大30分
    as_www php artisan queue:work --sleep=3 --tries=1 --timeout=1800 --max-time=3600
    ;;
  scheduler)
    # 毎日の自動取込み（routes/console.php）
    as_www php artisan schedule:work
    ;;
  *)
    echo "CONTAINER_ROLE は web / worker / scheduler のいずれかを指定してください" >&2
    exit 1
    ;;
esac
