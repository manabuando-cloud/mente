# 設備トラブルナビ 本番イメージ（Web / キューワーカー / スケジューラ 共通）
# 役割は環境変数 CONTAINER_ROLE（web | worker | scheduler）で切り替える。

# ---- 画面（React）のビルド ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tsconfig.json ./
COPY resources ./resources
RUN npm run build

# ---- PHPの依存 ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# ---- 実行環境 ----
FROM php:8.4-apache
# 公式イメージに無い拡張は pdo_mysql と pcntl（キューワーカーの停止シグナル用）だけ。どちらも追加ライブラリ不要
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl \
    && a2enmod rewrite headers

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-navi.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/entrypoint.sh /usr/local/bin/navi-entrypoint
RUN chmod +x /usr/local/bin/navi-entrypoint \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV CONTAINER_ROLE=web
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD [ "$CONTAINER_ROLE" != "web" ] || curl -fsS http://localhost/up > /dev/null || exit 1
ENTRYPOINT ["navi-entrypoint"]
