#!/usr/bin/env bash
# Testleri her zaman `db_test` (MySQL) container'ına, sync queue ile, log
# broadcast driver'ıyla çalıştırır.
#
# NEDEN BU SCRIPT GEREKLİ (php artisan test / phpunit yerine doğrudan):
# docker-compose.yml'deki `server` container'ı `.env`'i `env_file:` ile
# içeri alıyor — bu, QUEUE_CONNECTION/DB_HOST/CACHE_DRIVER gibi değişkenleri
# container'ın GERÇEK process ortamına (ve dolayısıyla PHP'nin $_ENV'ine)
# PHP hiç başlamadan ÖNCE yazıyor. phpunit.xml'in <env force="true"> etiketi
# bunu container İÇİNDEN, PHP zaten başladıktan SONRA değiştirmeye çalışıyor
# — ama Laravel'in env() çözümlemesi $_ENV'i (zaten dolu) putenv()'den
# ÖNCE kontrol ediyor, yani force="true" container içinde tek başına
# yeterli değil (canlı doğrulandı, bkz. CHANGELOG.md). Bu yüzden test
# env değişkenlerini `docker exec -e` ile, PHP başlamadan ÖNCE set etmek
# gerekiyor — bu script tam olarak bunu yapıyor.
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
  server ./vendor/bin/phpunit "$@"
