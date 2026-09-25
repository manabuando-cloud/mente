#!/bin/sh
set -e
cd /var/www/html

# 永続ボリュームに載る storage 配下のディレクトリを用意
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

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
      su -s /bin/sh www-data -c "php artisan migrate --force"
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
