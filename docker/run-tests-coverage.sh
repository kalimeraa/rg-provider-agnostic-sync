#!/usr/bin/env bash
# `docker/run-tests.sh` ile aynı env override mantığı (bkz. o dosyanın
# PHPDoc'u), farkı: Xdebug'ı SADECE bu çalıştırma için, komut satırından
# açıkça yükleyip coverage modunda çalıştırır. Xdebug normalde HİÇ
# yüklenmez (docker/server.Dockerfile'daki not: coverage dışında her zaman
# performansı düşürür ve OPcache JIT'i kapatır).
set -euo pipefail

docker exec \
  -e APP_ENV=testing \
  -e CACHE_DRIVER=array \
  -e QUEUE_CONNECTION=sync \
  -e BROADCAST_DRIVER=log \
  -e DB_CONNECTION=mysql \
  -e DB_HOST=db_test \
  -e DB_PORT=3306 \
  -e DB_DATABASE=product_sync_test \
  -e DB_USERNAME=product_sync \
  -e DB_PASSWORD=secret \
  server php -dzend_extension=xdebug.so -dxdebug.mode=coverage ./vendor/bin/phpunit --coverage-text "$@"
