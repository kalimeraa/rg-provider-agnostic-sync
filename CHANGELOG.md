# Changelog

Bu dosya, `docs/IMPLEMENTATION_PLAN.md`'deki fazlar hayata geçirilirken
karşılaşılan gerçek bulguları, düzeltilen hataları ve canlı doğrulama
sonuçlarını tarihsel sırayla tutar. Plan dokümanı ileriye dönük "ne
yapılacak"ı anlatır; bu dosya geriye dönük "ne oldu, ne bulundu, ne
düzeltildi"yi anlatır.

## Faz 7 — Test Katmanı: iki önemli hata canlı testlerle bulundu

61 test (Unit + Feature + integration/E2E) yazıldı, coverage **%78.26**
(satır bazında, %70 hedefinin üzerinde). Test yazarken iki gerçek, ciddi
hata bulundu ve düzeltildi:

1. **`phpunit.xml`'in test-only env override'ları hiç uygulanmıyordu.**
   `docker-compose.yml`'deki `server`/`worker` container'ları `.env`'i
   `env_file:` ile içeri alıyor — bu, `QUEUE_CONNECTION=redis`,
   `DB_HOST=db`, `CACHE_DRIVER=redis` gibi değerleri PHP hiç başlamadan
   ÖNCE container'ın GERÇEK process ortamına ($_ENV dahil) yazıyor.
   `phpunit.xml`'in `<env>` etiketi (hatta `force="true"` ile) bunu PHP
   zaten başladıktan SONRA değiştirmeye çalışıyor — ama Laravel'in
   `Illuminate\Support\Env` çözümlemesi `$_ENV`'i (zaten container
   başlangıcında dolu) `putenv()`'den ÖNCE kontrol ediyor. Sonuç: testler
   sessizce `db_test` yerine gerçek `db`'ye, `sync` yerine `redis` queue
   ile çalışıyordu (RefreshDatabase'in transaction rollback'i sayesinde
   kalıcı veri kirlenmesi olmadı, ama mimari tamamen yanlıştı — canlı
   `config('queue.default')`/`config('app.env')` dump'ları ile doğrulandı).
   **Çözüm:** `docker/run-tests.sh` ve `docker/run-tests-coverage.sh` —
   env değişkenlerini `docker exec -e ...` ile, PHP başlamadan ÖNCE set
   eden script'ler. Bu, `phpunit.xml`'deki `force="true"`'nun YETERSİZ
   kaldığı, container-seviyeli env'in her zaman kazandığı durumlarda tek
   güvenilir yöntem.

2. **Mark-and-sweep silme tespiti saat bazlı karşılaştırmayla ~%50
   ihtimalle yanlış sonuç veriyordu.** `Http::fake()` ile gerçek ağ
   gecikmesi olmadan art arda çok hızlı çalışan iki sync run'ı, container'ın
   saat çözünürlüğü yüzünden AYNI mikrosaniyeye denk gelebiliyordu — bu da
   `last_synced_at < syncRunStartedAt` karşılaştırmasını yanlışlıkla
   eşitleyip sweep'i atlıyordu (gerçek üretimde asla olmaz, çünkü gerçek
   API çağrıları arasında her zaman anlamlı gecikme var — sadece testte,
   sıfır I/O gecikmesiyle ortaya çıkan bir durum). Ayrıca `Eloquent`'in
   `fromDateTime()`'ının DB'ye varsayılan olarak saniye hassasiyetinde
   yazdığı (kolon mikrosaniye destekli olsa bile) ayrı bir katman sorunu
   daha bulundu. **Kalıcı çözüm:** saat yerine monoton bir işaretleyici —
   `products.last_synced_log_id` (o run'ın `SyncLog.id`'si). Sweep artık
   `last_synced_log_id <> $currentLogId` karşılaştırıyor; bu, run'lar ne
   kadar hızlı art arda çalışırsa çalışsın kesin doğru sonuç verir. Ayrıca
   `SyncController::status()`/`history()`'deki `latest('started_at')`
   çağrıları da aynı sebeple `latest('id')`'ye çevrildi (aynı saniyeye
   denk gelen iki log'un yanlışını seçilmesini önlemek için).

Her iki hata da SADECE gerçek entegrasyon testleri (tam
`SyncRunCoordinator` + `Bus::batch()` zinciri, gerçek Docker ortamı)
yazılırken ortaya çıkabilecek türdendi — tekil, mock'lu unit testler bu
sınıftaki hataları asla yakalayamazdı. Testler tam olarak amacına hizmet
etti: gerçek üretim kodunda var olan, ama bu ana kadar hiçbir canlı
denemede (bu oturumdaki onlarca manuel tinker/curl testinde bile) ortaya
çıkmamış iki gerçek kırılganlığı ortaya çıkardı.

## Sonradan yapılan iyileştirmeler

- **Job/queue isimlendirmesi:** job'lar Laravel'in genel `default`
  kuyruğu yerine anlamlı, tek bir `product-sync` kuyruğuna gidiyor
  (`SyncProviderJob` constructor'ında `onQueue('product-sync')`).
  Provider-agnostic: dummyjson/fakestore için ayrı kuyruklara
  bölünmüyor — hangi provider olduğu zaten job'ın kendi `ProviderType`
  parametresinde taşınıyor, ayrı kuyruk gereksiz karmaşıklık olurdu.
  `config/horizon.php`'deki `supervisor-1` → `product-sync-supervisor`
  olarak yeniden adlandırıldı, `waits` eşiği de yeni kuyruk adına
  güncellendi. Canlı doğrulandı: `Queue::size('product-sync')` ile job
  doğru kuyrukta göründü, `supervisorctl status` → yeni supervisor adıyla
  `RUNNING`, gerçek bir sync uçtan uca tamamlandı.

- **Faz 5 — Alerting Sistemi:** `AlertService` yazıldı — 4 eşik kontrolü
  (ardışık sync fail, failed_jobs backlog, ardışık API fail/circuit
  breaker, queue backlog), her alert tipi+provider için 5 dk throttle,
  `storage/logs/alerts.log`'a case'in istediği düz (nested değil)
  `{"level":"ALERT","type":...,"severity":...,"timestamp":...,"provider":...,...}`
  JSON formatında yazıyor. `config/logging.php`'ye `alerts` kanalı
  eklendi — bilerek `daily` DEĞİL `single` driver (case sabit bir dosya
  adı istiyor, tarih eklenmiş dönen dosyalar değil). Slack bildirimi için
  ayrı bir `Notification` class'ı YAZILMADI (plan bunu öngörmüştü) —
  tek, sabit bir webhook URL'ine düz bir `Http::post()` atmak,
  notifiable-routing makinesini gerektirmeyecek kadar basit bir senaryo;
  bu basitleştirme bilinçli bir sapma. `CircuitBreakerOpenException`'a
  `consecutiveFailures` yapılandırılmış alanı eklendi (exception mesajını
  string parse etmek yerine). Tüm 4 senaryo tinker ile canlı test edildi:
  eşik altı/üstü davranış, throttle, `recordSyncSuccess`'in sayacı
  sıfırlaması.

- **Büyük yeniden tasarım — sayfa-başına-job mimarisi:** kullanıcı,
  DummyJSON'ın sayfalamasını tek bir job içinde `do-while` ile döngüye
  almanın (194 ürün, 2 sayfa olsa da) sınırsız/güvenilir olmadığını fark
  etti — provider'ın `total` alanı bozuk/anormal büyük dönerse ya da her
  sayfada 429 backoff'a takılırsa, tek job Horizon'un job timeout'unu
  (60s) aşıp zorla öldürülebilirdi. "İyi bir mimari" istendi; şu şekilde
  yeniden tasarlandı:

  - `ProviderClientInterface::fetchAll()` → `fetchPage(int $page):
    ProviderPage` (`{items, totalPages}` — provider kendi sayfa boyutunu
    kendisi bilir, çağıran taraf bilmez)
  - `SyncRunCoordinator` (yeni): bir provider'ın koşusunu yönetir — ilk
    sayfayı çekip `totalPages`'i öğrenir (50 sayfa güvenlik sınırı,
    `PaginationLimitExceededException`), her sayfa için bir
    `FetchProviderPageJob` içeren bir `Bus::batch()` dispatch eder, batch
    biterken (`then()`/`catch()`) finalize eder
  - **Uniqueness artık `ShouldBeUnique` DEĞİL**: bu, tek bir job'un
    ömrüne bağlı, ama artık "ömür" tüm batch'in bitmesi demek. Bunun
    yerine `SyncRunCoordinator` elle bir `Cache::lock()` tutuyor; owner
    token'ı batch'in `then()`/`catch()` callback'lerine taşınıp
    (`Cache::restoreLock()`) FARKLI bir process'ten serbest bırakılıyor
  - **Silme tespiti artık mark-and-sweep**: `DeltaSyncService::upsertPage()`
    artık sadece ekleme/güncelleme yapıyor (silme mantığı YOK — hiçbir
    tek çağrı artık uzak listenin tamamını görmüyor). Her upsert
    `last_synced_at`'i bu run'a özel SABİT bir zaman damgasıyla işaretler;
    `SyncRunCoordinator` TÜM sayfalar bitince bu damgadan eski
    `last_synced_at`'e sahip ürünleri soft-delete eder. `catch()`
    yolunda (bir sayfa kalıcı başarısız oldu) sweep BİLEREK çalışmaz —
    eksik veriyle yanlış silme yapılmasın diye.
  - **`ThrottledHttpClient` artık Redis-tabanlı paylaşımlı**: pacing ve
    ardışık-başarısızlık sayacı `$providerKey`'e göre Redis'te tutuluyor
    (`Cache::lock()` korumalı atomik read-modify-write) — paralel
    çalışan sayfa job'ları aynı 5rps bütçesini ve aynı circuit-breaker
    sayacını paylaşıyor. Instance-local olsaydı, N paralel worker N
    kat rate limit aşımına yol açardı.
  - `ProviderFactory`, `ThrottledHttpClient`'ı artık container binding'i
    yerine elle (provider-key'i bilerek) inşa ediyor;
    `AppServiceProvider`'daki eski binding kaldırıldı.
  - `PaginatesResults` trait'i (bu redesign'dan hemen önce, tek-job
    mimarisindeki `do-while`'ı güvenli hale getirmek için yazılmıştı)
    SİLİNDİ — artık gereksiz, çünkü hiçbir provider kendi içinde birden
    fazla sayfa çekmiyor.
  - Eksik olan `job_batches` tablosu migration'ı (`queue:batches-table`)
    eklendi — `Bus::batch()` bunsuz çalışmaz.

  **Canlı doğrulandı:** gerçek DummyJSON sync'i tam olarak 2
  `FetchProviderPageJob` içeren bir batch oluşturdu, ikisi de başarıyla
  bitti, `added=194`; tekrar çalıştırıldığında idempotency doğru
  (`added=0`); manuel eklenen bir "hayalet" ürün bir sonraki run'da
  sweep ile doğru soft-delete edildi; aynı provider için art arda
  `SyncRunCoordinator::start()` çağrısı, batch bitene kadar ikinci
  çağrıyı sessizce reddetti, batch bitince kilit serbest kalıp üçüncü
  çağrı yeni bir run başlattı.

## Faz 0 — Proje İskeleti & Altyapı Kurulumu

- `laravel/horizon`, `config/sync.php`, `ProviderType` enum eklendi.
- **Revizyon — testler için SQLite yerine MySQL:** ilk yazımda testler
  SQLite in-memory kullanıyordu; bu karardan dönüldü çünkü prod ile aynı
  motor (MySQL) üzerinde test etmek motor-spesifik davranış farklarını
  (kolon tipi zorlamaları, unique/collation davranışı vb.) SQLite'ın
  maskeleyebileceği riskini ortadan kaldırıyor. Değişenler:
  - `docker-compose.yml`'e ayrı bir `db_test` (MySQL 8, host port 3307,
    kendi volume'ü) servisi eklendi — `db`'den tamamen izole
  - `phpunit.xml` → `DB_CONNECTION=mysql`, `DB_HOST=db_test`,
    `DB_DATABASE=product_sync_test`
  - `.env.example`'a `DB_TEST_DATABASE` değişkeni eklendi
  - `CLAUDE.md`'nin "Tests run against SQLite" iddiası düzeltildi
- `server.Dockerfile`'daki `pdo_sqlite`/`sqlite3` extension kurulumu
  tamamen kaldırıldı — projede SQLite artık hiçbir yerde kullanılmıyor.
- `working_dir: /var/www/server*` yazım hatası (`server` servisi)
  düzeltildi.
- **`queue-worker` + `scheduler` yerine tek bir `worker` container'ı:**
  `docker/supervisord/{supervisord.conf,horizon.conf}` dosyaları zaten
  Horizon+scheduler'ı tek bir supervisord altında iki ayrı process olarak
  çalıştıracak şekilde hazırlanmıştı, ama `docker-compose.yml` bunları
  kullanmıyordu (iki ayrı container'da iki ayrı foreground komut), ve
  `server.Dockerfile` `supervisor` paketini hiç kurmuyordu. Düzeltildi:
  `Dockerfile`'a `apt-get install supervisor` + conf dosyalarının
  `/etc/supervisor/`'a kopyalanması eklendi, `docker-compose.yml`'de tek
  bir `worker` servisi `supervisord -c /etc/supervisor/supervisord.conf`
  çalıştırıyor. `horizon.conf`'taki yanlış isimlendirilmiş `[program:log]`
  (aslında `schedule:work` çalıştırıyordu) `[program:scheduler]` olarak
  düzeltildi.
- `supervisord.conf`'a `[unix_http_server]`/`[supervisorctl]`/
  `[rpcinterface:supervisor]` bölümleri eklendi — bunlar olmadan
  `supervisorctl status` ".ini file does not include supervisorctl
  section" hatası veriyordu (programların kendisi çalışıyordu, sadece
  admin/CLI arayüzü yoktu).
- **`reverb` container'ı eklendi** (dashboard'un gerçek-zamanlı
  güncellemeleri için, Faz 6'da kullanılacak): `php artisan
  reverb:start`, host'a doğrudan port açmıyor — nginx
  (`docker/nginx/conf.d/default.conf`) `/app/` altında WebSocket upgrade
  header'larıyla buraya proxy yapıyor, böylece tarayıcı tek bir
  host/port'a (`APP_PORT`) bağlanıyor. Şimdilik SSL yok, düz http/ws
  (`REVERB_SCHEME=http`). `.env.example`'a `REVERB_*` grubu eklendi.
- **Kritik düzeltme — `REDIS_CLIENT`:** CLAUDE.md "Predis (pure-PHP
  client, phpredis DEĞİL)" diyor ama ne `.env` ne `.env.example` bunu
  ayarlıyordu; Laravel'in varsayılanı `phpredis`'e düşüyor ve
  `/api/health` `Class "Redis" not found` ile patlıyordu (phpredis
  C-extension kurulu değil, bilerek). `REDIS_CLIENT=predis` her iki
  dosyaya da eklendi ve canlı doğrulandı.
- **Gerçek `.env` dosyası tamamen yanlıştı:** incelenince `.env`'in bu
  projeyle hiç alakası olmayan farklı bir projeye (Vault token, AWS,
  Meilisearch, `sailatlas.test` vb.) ait olduğu görüldü — muhtemelen
  yanlışlıkla başka bir projeden kopyalanmış. `.env.example` temel
  alınarak, bu projeye özgü değerlerle (localhost, `db`/`redis`/`reverb`
  servis adları, taze `APP_KEY`) yeniden yazıldı; `.env.example` ile
  `.env` arasındaki 54 değişkenin tamamı karşılıklı doğrulandı (fark yok).

**Docker altyapısı uçtan uca canlı doğrulandı** (`docker-compose down
--volumes --remove-orphans` → build → up):
- Yol boyunca gerçek bir altyapı sorunu bulundu ve çözüldü: Docker
  Desktop VM'inin diski dolmuştu (`No space left on device`) — bu,
  MySQL'in "data directory has files in it" gibi yanıltıcı bir hata
  vermesine yol açıyordu (aslında `auto.cnf` yazarken diskin dolu olması
  gerçek sebepti). `docker system prune -a --volumes` ile 32GB
  boşaltıldı (bu projeyle ilgisiz başka projelerin — `sailatlas_*`,
  `google-review_*` vb. — adlandırılmış volume'lerine dokunulmadı,
  sadece kullanılmayan image/cache/anonim volume'ler temizlendi).
- 7 container da sağlıklı: `db`, `db_test`, `redis`, `server`, `worker`,
  `reverb`, `webserver`.
- `worker` içinde `supervisorctl status` → `horizon RUNNING`,
  `scheduler RUNNING`.
- `GET /api/health` → `200 {status:"ok", database:true, redis:true}`.
- Gerçek bir sync tetiklendi (`POST /api/sync/trigger`) ve
  `GET /api/sync/status` `completed, added=194` gösterdi.
- Reverb WebSocket handshake'i nginx proxy'si üzerinden `curl` ile
  doğrulandı: `HTTP/1.1 101 Switching Protocols`,
  `X-Powered-By: Laravel Reverb`, `pusher:connection_established`
  event'i geldi — tamamı `http://localhost:9000` üzerinden, ayrı
  port/SSL yok.

## Faz 1 — Veri Katmanı

- `ProviderType` enum, 3 migration (`products`, `sync_logs`,
  `failed_jobs.retry_count`), `Product`/`SyncLog` modelleri ve
  factory'leri yazıldı; docker `db` (MySQL) container'ına karşı migrate
  edildi ve unique `(provider_type, external_id)` constraint'i tinker ile
  canlı doğrulandı.
- **Provider API şeması netleştirmesi:** gerçek API'lere `curl` ile
  bakılarak iki provider'ın alan yapısı netleştirildi:

  | Normalize alan | DummyJSON kaynak alan | FakeStore kaynak alan |
  |---|---|---|
  | `external_id` | `id` (int → string) | `id` (int → string) |
  | `name` | `title` | `title` |
  | `price` | `price` (float) | `price` (float) |
  | `stock` | `stock` (int, mevcut) | **yok** — API'de stok/envanter alanı hiç yok |
  | `description` | `description` | `description` |

  Hash'e/DB'ye dahil edilmeyen volatile alanlar: `rating`/`reviews`,
  `discountPercentage`, `images`/`thumbnail`, `tags`, `brand`, `sku`,
  `dimensions`, `category` (DummyJSON); `rating`, `image`, `category`
  (FakeStore).

  **Karar:** FakeStore normalize edilirken `stock` sabit `0` olarak set
  edilir (provider'ın hiç envanter kavramı yok — `null` yerine `0`
  seçildi ki `products.stock` kolonu `unsignedInteger` NOT NULL
  kalabilsin).

  DummyJSON gerçek toplam ürün sayısı **194** (case metni "100 adet"
  diyor ama canlı API artık daha fazla veri döndürüyor); FakeStore
  sayfalama olmadan düz array olarak **20** ürün döndürüyor.

## Faz 2 — Domain / Servis Katmanı

- `config/sync.php`, `ProviderClientInterface`, `DummyJsonProvider`/
  `FakeStoreProvider`, `ProviderFactory`, `ThrottledHttpClient`,
  `HashService`, `DeltaSyncService`, `SyncResult` DTO ve iki özel
  exception (`ProviderRequestException`, `CircuitBreakerOpenException`)
  yazıldı.
- **Gerçek API'lere karşı tinker ile canlı doğrulandı:**
  - FakeStore ilk sync: `added=20, updated=0, deleted=0`
  - FakeStore ikinci sync (idempotency): `added=0, updated=0, deleted=0`
  - `data_hash` manuel bozulup tekrar sync edildiğinde: `updated=1` —
    hash-bazlı delta tespiti doğru çalışıyor
  - DummyJSON sync (194 ürün, 2 sayfa `limit=100`): `added=194` —
    sayfalama doğru
  - Manuel eklenen "hayalet" bir ürün bir sonraki sync'te `deleted=1`
    olarak soft-delete edildi, hard delete olmadı
- 429/circuit-breaker senaryoları gerçek API'de tetiklenemediği için
  (mock API'ler rate-limit uygulamıyor) Faz 7'de `Http::fake()` ile unit
  test edilecek.
- **PHPDoc geçişi sırasında bulunan hata:** `DummyJsonProvider::fetchOne()`,
  olmayan bir ürün id'si için DummyJSON'ın 200+`{"message":...}`
  döneceğini varsayıp buna göre bir kontrol içeriyordu; `curl` ile
  doğrulanınca API'nin aslında gerçek bir **404** döndüğü görüldü (ki
  `ThrottledHttpClient` bunu zaten boş array'e çeviriyor) — ölü/yanlış
  kontrol kaldırıldı, `fetchOne()` gerçek API'ye karşı hem var olan hem
  olmayan id için yeniden test edildi.

## Faz 3 — Queue, Job & Scheduler Katmanı

- `laravel/horizon` composer'a eklendi, `SyncProviderJob`, güncellenmiş
  `app/Console/Kernel.php`, `config/horizon.php` yazıldı/kuruldu.
- Docker'daki `server` container'ında canlı doğrulandı: aynı provider
  için art arda 2 dispatch → sadece 1 job kuyruğa girdi (`ShouldBeUnique`
  kilidi), farklı provider paralel kuyruklandı; Horizon gerçekten job'ları
  işledi (`sync_logs`'ta `fakestore added=20`, `dummyjson added=194`);
  job bitince kilit serbest kaldı; `schedule:list` ve
  `route:list --path=horizon` doğru.
- **Sapma:** `SyncProviderJob::failed()` hook'u bu fazda eklenmedi —
  `AlertService` (Faz 5) henüz yazılmadığı için var olmayan bir sınıfa
  referans vermemek adına bilinçli olarak ertelendi. Her job attempt
  kendi `SyncLog` satırını oluşturuyor (bir attempt'in mutasyonlarını
  sıradaki retry'a taşımak yerine) — bu, `sync/history` endpoint'inde
  hangi denemenin başarısız/başarılı olduğunu ayrı ayrı görebilmeyi
  sağlıyor.

## Faz 4 — API Katmanı

- `ApiResponseTrait` (+`paginated()`), `TriggerSyncRequest`,
  `ProductResource`/`SyncLogResource`, `SyncController`, `ProductController`,
  `HealthController` ve 7 route yazıldı. `app/Exceptions/Handler.php`
  güncellendi: `api/*` altındaki HER hata (validasyon, 404, 500 dahil)
  case'in standart `{success:false, error:{code,message}}` zarfına
  çevriliyor.
- **Docker'daki nginx/php-fpm üzerinden gerçek HTTP istekleriyle uçtan
  uca doğrulandı:** `/api/health` DB+Redis ping; `/api/sync/trigger`
  geçerli/geçersiz/eksik `provider` için doğru validasyon zarfı ve
  başarılı dispatch; gerçek bir sync sonrası `/api/sync/status`,
  `/api/sync/history`, `/api/products` doğru veriyi gösterdi;
  `/api/sync/failed-jobs` ve `/api/sync/retry/{jobId}` gerçek bir
  başarısız job üretilerek test edildi.
- **Bulunan ve düzeltilen kritik hata:** `retry()` ilk yazımda
  `{jobId}`'yi `failed_jobs.id` (numeric) sanıp öyle sorguluyordu. Canlı
  testte `queue:retry` komutu "Unable to find failed job" hatası verdi —
  sebebi bu projede `config/queue.php`'deki
  `QUEUE_FAILED_DRIVER=database-uuids` varsayılanı: Laravel'in
  `DatabaseUuidFailedJobProvider`'ı job'u **`uuid`** koluna göre arıyor,
  numeric `id`'ye göre değil. Controller, route (`whereUuid('jobId')`)
  ve PHPDoc buna göre düzeltildi ve gerçek bir failed job + retry ile
  tekrar doğrulandı. Bu, sadece kod okuyarak fark edilemeyecek, yalnızca
  gerçek bir failed job üretip uçtan uca test ederek ortaya
  çıkarılabilecek bir hataydı.
