# Implementation Plan — Product Sync Case

Bu doküman, `README.md`'deki case gereksinimlerini `CLAUDE.md`'de tanımlanmış
mimariye (Contracts/Services/Jobs ayrımı, `ApiResponseTrait` zarfı, soft-delete
konvansiyonu, provider Strategy/Factory deseni) uyacak şekilde fazlara böler.

Faz sıralaması, değerlendirme kriterlerindeki ağırlığa göre yapıldı:
Fonksiyonellik %40 + Code Quality %30 → önce Faz 1-4 (çekirdek sync motoru ve
API); Testing %15 ve Documentation %10 → Faz 7-8; bonus puanlı Alerting/
Frontend → Faz 5-6.

**Kod standardı:** her class/interface/enum ve non-trivial method'a Türkçe
PHPDoc yazılır (ne işe yaradığını açıklayan).

> Bu fazların hayata geçirilirken karşılaşılan gerçek bulgular, düzeltilen
> hatalar ve canlı doğrulama sonuçları için bkz. `CHANGELOG.md`.

---

## Faz 0 — Proje İskeleti & Altyapı Kurulumu

**Goal:** `composer install` sonrası proje `docker-compose up` ile ayağa
kalkar; `config/sync.php` ve enum'lar mevcuttur; testler prod ile aynı
motorda (MySQL, ayrı bir `db_test` container'ında) çalışır — SQLite
projede hiçbir yerde kullanılmaz.

**Files:**
- `composer.json` — `laravel/horizon`, `laravel/reverb`
- `config/sync.php` — `.env.example`'daki `SYNC_*` ve `ALERT_*`
  değişkenlerini map'ler (base URL'ler, rate limit, interval, threshold'lar)
- `app/Enums/ProviderType.php` — `DummyJson`, `FakeStore` (backed enum,
  string value `dummyjson`/`fakestore` — job uniqueness key ve
  `provider_type` kolonu bu değerleri kullanır)
- `docker-compose.yml` — servisler: `webserver` (nginx), `server` (php-fpm),
  `worker` (Horizon + scheduler, `supervisord` altında tek container —
  `docker/supervisord/{supervisord.conf,horizon.conf}`), `reverb`
  (WebSocket, nginx `/app/` proxy'si üzerinden), `db` (MySQL, prod), `db_test`
  (MySQL, sadece testler için — `db`'den izole), `redis`
- `docker/server.Dockerfile` — `supervisor` paketi kurulumu + conf
  dosyalarının `/etc/supervisor/`'a kopyalanması
- `docker/nginx/conf.d/default.conf` — `/app/` altında Reverb'e WebSocket
  proxy'si (upgrade header'larıyla)
- `phpunit.xml` — `DB_CONNECTION=mysql`, `DB_HOST=db_test`,
  `DB_DATABASE=product_sync_test`
- `.env` / `.env.example` — `REDIS_CLIENT=predis` (zorunlu — yoksa Laravel
  `phpredis`'e düşer ve C-extension kurulu olmadığı için patlar),
  `REVERB_*` grubu, `DB_TEST_DATABASE`

**Pattern(s):** Yok — bu faz saf kurulum, henüz bir tasarım deseni gerektirmiyor.

**SOLID:** Uygulanamaz (henüz sınıf yok).

**Depends on:** —

**Verification:**
```
composer install
php artisan key:generate
docker-compose up -d
php artisan config:show sync
./vendor/bin/phpunit   # db_test (MySQL) container'ina baglanmali
docker exec worker supervisorctl status   # horizon + scheduler RUNNING
curl http://localhost:9000/api/health
```

---

## Faz 1 — Veri Katmanı (Migrations, Models)

**Goal:** `products`, `sync_logs` tabloları migrate olur; `failed_jobs`'a
`retry_count` eklenir; Eloquent modelleri (soft-delete dahil) ilişkileri
kurar.

**Files:**
- `database/migrations/2026_07_03_130000_create_products_table.php` —
  `id, provider_type, external_id, name, price(decimal 10,2), stock(int),
  description(text), data_hash(char 64), last_synced_at, deleted_at,
  timestamps`; **unique(`provider_type`,`external_id`)**; index üzerinde
  `data_hash` (delta karşılaştırma) ve `deleted_at` (soft-delete filtreleme)
- `database/migrations/2026_07_03_130100_create_sync_logs_table.php` —
  `id, provider_type, started_at, completed_at, status(enum/string),
  products_added, products_updated, products_deleted, error_message(text
  nullable), timestamps`; index `(provider_type, started_at)` (history
  pagination sorgusu bunu kullanacak)
- `database/migrations/2026_07_03_130200_add_retry_count_to_failed_jobs_table.php`
  — native `failed_jobs`'a tek kolon ekler (CLAUDE.md: paralel bir DLQ tablosu
  yaratma)
- `app/Models/Product.php` — `SoftDeletes`, `$casts` (`price` → decimal,
  `provider_type` → `ProviderType::class` enum cast), `external_id` +
  `provider_type` üzerinde composite unique'i model seviyesinde de belgele
- `app/Models/SyncLog.php` — `$casts` (`status` enum, tarihler datetime)
- `database/factories/ProductFactory.php`, `SyncLogFactory.php` — test/seed
  için

**Pattern(s) applied:** Yok (Repository pattern **bilinçli olarak atlandı** —
bkz. §Yapılmayanlar). Düz Eloquent + Service katmanı yeterli çünkü tek veri
kaynağı (MySQL) var, sorgu kompozisyonu karmaşık değil.

**SOLID:**
- **SRP:** `Product` modeli sadece kendi persistence/cast/ilişki
  sorumluluğunu taşır, hash hesaplama veya sync mantığı barındırmaz (o
  `HashService`/`DeltaSyncService`'te — Faz 2).

**Depends on:** Faz 0 (enum, config)

**Verification:**
```
php artisan migrate:fresh
php artisan tinker --execute="App\Models\Product::factory()->count(3)->create()"
php artisan migrate:status
```

### Provider API şeması netleştirmesi

Gerçek API'lere `curl` ile bakılarak iki provider'ın normalize edilecek alan
eşlemesi netleştirildi:

| Normalize alan | DummyJSON kaynak alan | FakeStore kaynak alan |
|---|---|---|
| `external_id` | `id` (int → string) | `id` (int → string) |
| `name` | `title` | `title` |
| `price` | `price` (float) | `price` (float) |
| `stock` | `stock` (int, mevcut) | **yok** — API'de stok/envanter alanı hiç yok |
| `description` | `description` | `description` |

Hash'e/DB'ye dahil edilmeyen ama kaynakta bulunan volatile alanlar:
`rating`/`reviews`, `discountPercentage`, `images`/`thumbnail`, `tags`,
`brand`, `sku`, `dimensions`, `category` (DummyJSON); `rating`, `image`,
`category` (FakeStore).

**Karar:** FakeStore normalize edilirken `stock` **sabit `0`** olarak set
edilir (provider'ın hiç envanter kavramı yok — `null` yerine `0` seçildi ki
`products.stock` kolonu `unsignedInteger` NOT NULL kalabilsin ve
`ProductResource`/dashboard tarafında ekstra null-check gerekmesin). Bu
README'nin "teknik kararlar" bölümünde açıkça belirtilecek.

DummyJSON gerçek toplam ürün sayısı **194** (case metni "100 adet" diyor ama
canlı API artık daha fazla veri döndürüyor — `limit`/`skip` ile
sayfalanarak tamamı çekilecek); FakeStore `GET /products` sayfalama
parametresi olmadan düz array olarak **20** ürün döndürüyor.

---

## Faz 2 — Domain / Servis Katmanı

**Goal:** Herhangi bir provider'dan tek bir sayfa veri çekip hash'leyip
DB'ye upsert eden, kendi kendini (paylaşımlı/Redis-tabanlı olarak) throttle
eden servis katmanı — henüz queue/job yok, çıplak servisler unit test
edilebilir durumda.

> Bu faz iki kez yazıldı: ilk sürümde `fetchAll()` tek bir job içinde tüm
> sayfaları döngüyle çekiyordu. Sonradan sayfa-başına-job mimarisine
> geçildi (bkz. Faz 3 ve CHANGELOG.md) — aşağıdaki açıklama GÜNCEL/son
> hâlidir.

**Files:**
- `app/Contracts/ProviderClientInterface.php` — `fetchPage(int $page):
  ProviderPage`, `fetchOne(string $externalId): ?array` (ISP: caller'ın
  ihtiyacı bundan fazlası değil)
- `app/DTOs/ProviderPage.php` — `{items, totalPages}`: provider kendi
  sayfa boyutunu bilir, `totalPages`'i kendisi hesaplar; çağıran taraf
  (sayfa sayısını öğrenmek dışında) provider'ın sayfalama detaylarını hiç
  bilmez
- `app/Services/Providers/DummyJsonProvider.php`,
  `app/Services/Providers/FakeStoreProvider.php` — interface implementasyonu;
  her biri kendi API şeklini (`title` vs `name`, `rating` gibi volatile
  alanlar) normalize edip ortak bir array şekline çevirir. FakeStore
  sayfalama yapmadığı için `totalPages` her zaman 1'dir.
- `app/Services/Providers/ProviderFactory.php` — `ProviderType` enum → client
  instance resolver; `ThrottledHttpClient`'ı provider-key'i bilerek ELLE
  inşa eder (container binding'i DEĞİL — client'ın pacing/circuit-breaker
  durumu provider'a göre değişir, autowiring bunu taşıyamaz)
- `app/Services/Sync/HashService.php` — `hash(array $normalizedProduct):
  string` → `{name, price, stock, description}` üzerinden `sha256`
  (`external_id`/`provider_type` kimlik alanı olduğu, `ratings`/`images` gibi
  volatile alanlar da içerik değişikliği sayılmadığı için hash'e dahil değil)
- `app/Services/Sync/ThrottledHttpClient.php` — 5 rps pacing + 429
  exponential backoff (1s/2s/4s) + 5 ardışık hatada circuit breaker.
  **Redis-tabanlı, `$providerKey`'e göre paylaşımlı** (instance-local
  DEĞİL): aynı provider için paralel çalışan birden çok sayfa job'u tek
  bir rate limit bütçesini ve tek bir hata sayacını paylaşır.
- `app/Services/Sync/DeltaSyncService.php` — `upsertPage(ProviderType,
  array $items, CarbonInterface $syncRunStartedAt): array{added,updated}`:
  SADECE ekleme/güncelleme. Silme mantığı burada YOK — hiçbir tek çağrı
  artık uzak listenin TAMAMINI görmüyor (bkz. Faz 3, SyncRunCoordinator).
  Her item kendi `DB::transaction()`'ı + `lockForUpdate()` ile işlenir.
- `app/DTOs/SyncResult.php` — `{added, updated, deleted}` — artık
  `DeltaSyncService`'in değil, `SyncRunCoordinator`'ın (tüm sayfalar +
  sweep bitince) ürettiği nihai özet.

**Pattern(s) applied:**
- **Strategy** (`ProviderClientInterface` + iki implementasyon) — DummyJSON ve
  FakeStore'un veri şekli farklı ama sayfa-çekme akışı aynı; yeni bir
  tedarikçi eklemek sadece yeni bir implementasyon demek.
- **Factory** (`ProviderFactory`) — enum'dan implementasyon seçimi VE
  provider-key'li `ThrottledHttpClient` inşası tek yerde toplanır.
- **DTO** (`ProviderPage`, `SyncResult`) — servis/job sınırlarında tip
  güvenliği; provider'ın sayfalama detayları çağıran tarafa sızmaz.
- **Decorator** düşünüldü (ThrottledHttpClient'ı ayrı bir sarmalayıcı
  olarak) ama zaten `ProviderClientInterface`'in kendisi değil, onun
  implementasyonlarının bir bağımlılığı olarak tasarlandı — ayrı bir
  Decorator katmanına gerek kalmadı.

**SOLID:**
- **SRP:** `HashService` sadece hash'ler, `DeltaSyncService` sadece tek
  sayfalık upsert kararını verir (silme YOK), `ThrottledHttpClient` sadece
  paylaşımlı pacing/circuit-breaker yapar.
- **OCP:** Yeni tedarikçi = yeni `ProviderClientInterface` implementasyonu +
  `ProviderFactory`'ye bir `match` kolu; `DeltaSyncService` değişmez.
- **LSP:** `DummyJsonProvider` ve `FakeStoreProvider` aynı `ProviderPage`
  şeklini döndürür — çağıran taraf hangi implementasyonla çalıştığını
  bilmeden aynı davranışı bekleyebilir.
- **ISP:** `ProviderClientInterface` sadece 2 metod.
- **DIP:** `DeltaSyncService`/sayfa job'ları `ProviderClientInterface`'e
  bağımlı; somut client `ProviderFactory` üzerinden enjekte edilir.

**Depends on:** Faz 1 (Product modeli, migration'lar)

**Verification:**
```
php artisan tinker --execute="app(\App\Services\Providers\ProviderFactory::class)->make(\App\Enums\ProviderType::DummyJson)->fetchPage(0)"
php artisan test --filter=HashServiceTest
```

429/circuit-breaker senaryoları gerçek API'de tetiklenemez (mock API'ler
rate-limit uygulamıyor) — Faz 7'de `Http::fake()` ile unit test edilecek.

---

## Faz 3 — Sayfa-Başına-Job Batch Mimarisi (Queue, Coordinator & Scheduler)

**Goal:** Bir provider'ın senkronizasyonu, her biri kendi retry/backoff'una
sahip, paralel çalışabilen sayfa job'larına bölünür; TEK bir job'un tüm
sayfaları çekmeye çalışırken zaman aşımına uğrama riski olmaz; scheduler her
5-10 dakikada bir tetikler; Horizon dashboard'u ayakta.

> **Neden bu şekilde:** İlk tasarımda (`SyncProviderJob` + `ShouldBeUnique`)
> tek bir job `DeltaSyncService::sync()` içinde TÜM sayfaları döngüyle
> çekiyordu. DummyJSON'ın sayfalaması (`total`/`skip`) provider'ın kendi
> beyanına güveniyordu; `total` bozuk/anormal büyük dönerse ya da her
> sayfada 429 backoff'a takılırsa, tek job Horizon'un job timeout'unu
> (60s) aşıp zorla öldürülebilirdi. Bu fazın tasarımı bu riski ortadan
> kaldırır (bkz. CHANGELOG.md'deki tam gerekçe ve canlı doğrulama).

**Files:**
- `app/Jobs/FetchProviderPageJob.php` — TEK bir sayfayı çeker (`fetchPage`)
  ve upsert eder (`DeltaSyncService::upsertPage`); `tries=3`,
  `backoff=[1,2,4]`; `Batchable` — `$this->batch()?->cancelled()` ise atlar;
  `CircuitBreakerOpenException` özel olarak yakalanıp retry edilmeden
  `fail()` ile kalıcı başarısız işaretlenir (circuit breaker zaten "bu
  provider'a artık istek atma" demek, tekrar denemek anlamsız)
- `app/Services/Sync/SyncRunCoordinator.php` — bir provider'ın run'ını
  yönetir:
  1. `Cache::lock()` alır (provider bazlı, TÜM batch ömrü boyunca —
     `ShouldBeUnique` DEĞİL, çünkü o sadece TEK bir job'u kapsar, artık
     "iş" bir batch'in tamamı)
  2. İlk sayfayı çekip `totalPages`'i öğrenir; 50 sayfa güvenlik sınırını
     aşarsa `PaginationLimitExceededException` (provider'ın `total`'ı
     güvenilmezse binlerce job kuyruklamak yerine hemen durur)
  3. Her sayfa için bir `FetchProviderPageJob` içeren `Bus::batch()`
     dispatch eder
  4. `then()` (TÜM sayfalar başarılı): mark-and-sweep silme (bu run'a özel
     sabit başlangıç zaman damgasından eski `last_synced_at`'e sahip
     ürünleri soft-delete eder), Redis sayaçlarından `SyncResult` üretir,
     `SyncLog`'u "completed" yapar, kilidi (owner token'ı
     `Cache::restoreLock()` ile taşıyarak, FARKLI bir process'ten) serbest
     bırakır
  5. `catch()` (bir sayfa kalıcı başarısız oldu, batch iptal edildi):
     sweep YAPILMAZ (eksik veriyle yanlış silme olmasın), `SyncLog`'u
     "failed" yapar, `AlertService`'e bildirir, kilidi serbest bırakır
- `app/Jobs/SyncProviderJob.php` — scheduler/API'nin dispatch ettiği ince
  wrapper; `handle()` sadece `SyncRunCoordinator::start()`'ı çağırır.
  `tries=3`/`backoff=[1,2,4]`/`failed()` sadece "ilk sayfayı öğrenme
  adımı" başarısız olursa devreye girer — bir sayfa job'unun batch
  içindeki başarısızlığı ayrı yoldan (`SyncRunCoordinator::finishWithFailure`)
  ele alınır, burada tekrar işlenmez.
- `app/Console/Kernel.php` — `$schedule->job(new SyncProviderJob($provider))`
  her iki provider için (`withoutOverlapping` DEĞİL — uniqueness zaten
  coordinator seviyesinde)
- `config/horizon.php` — tek, provider-agnostic `product-sync` kuyruğu
  (`product-sync-supervisor`)
- `database/migrations/..._create_job_batches_table.php` —
  `php artisan queue:batches-table` ile üretilir; `Bus::batch()` bu tablo
  olmadan çalışmaz
- Horizon'un hangi container'da, hangi mekanizmayla (supervisord) çalıştığı
  Faz 0'da (`worker` container'ı) çözüldü — burada tekrar edilmiyor (DRY)

**Pattern(s) applied:**
- **Mediator/Coordinator** (`SyncRunCoordinator`) — kilit yönetimi, batch
  dispatch ve finalize mantığını tek bir yerde toplar; `FetchProviderPageJob`
  ve `SyncProviderJob` bu koordinasyonun detaylarını bilmez.
- **Mark-and-sweep** — dağıtık/parçalı bir işlemde "tümü bitti mi, ne
  eksik kaldı" sorusunu tek bir merkezi diff yerine zaman damgası +
  sonradan süpürme ile çözen, dağıtık sistemlerde yaygın bir desen.
- **Template Method** düşünüldü (ortak bir job base class) ama **atlandı**
  — `FetchProviderPageJob` zaten tek bir sınıf, provider'a özgü davranış
  `ProviderClientInterface` implementasyonunda; ikinci bir soyutlamaya
  gerek yok.

**SOLID:**
- **SRP:** `FetchProviderPageJob` sadece "bir sayfayı çek+upsert et";
  `SyncRunCoordinator` sadece "bir run'ın yaşam döngüsünü yönet" (kilit,
  batch, finalize); `SyncProviderJob` sadece "coordinator'ı tetikle" —
  üçü de ayrı sebeplerle değişir.
- **DIP:** `SyncRunCoordinator`, `ProviderFactory`/`AlertService`
  soyutlamalarına bağımlı; somut implementasyonlar container üzerinden
  enjekte edilir.

**Depends on:** Faz 2 (`DeltaSyncService::upsertPage`, `ProviderPage`)

**Verification:**
```
php artisan horizon:install && php artisan queue:batches-table && php artisan migrate
docker exec worker supervisorctl status   # horizon + scheduler RUNNING
php artisan tinker --execute="App\Jobs\SyncProviderJob::dispatch(\App\Enums\ProviderType::DummyJson)"
# job_batches tablosunda total_jobs=totalPages, pending_jobs=0, failed_jobs=0 olduğunu doğrula
# aynı provider için ikinci bir start() çağrısı, batch bitene kadar sessizce hiçbir şey yapmamalı
php artisan schedule:list   # her iki provider'ın 5 dk'da bir göründüğünü doğrula
```

---

## Faz 4 — API Katmanı

**Goal:** README'deki 7 endpoint, standart `{success, data, meta, message}` /
`{success:false, error:{code,message}}` zarfıyla çalışır.

**Files:**
- `app/Http/Traits/ApiResponseTrait.php` — `success($data, $message, $meta =
  null)` ve `error($code, $message, $status)` helper'ları
- `app/Http/Controllers/Api/SyncController.php` —
  `trigger()` (POST, ilgili `SyncProviderJob::dispatch`),
  `status()` (GET, son `SyncLog` + kilit durumu — cache'teki unique lock key
  var mı diye bakarak "running" tespiti),
  `history()` (GET, `SyncLog::paginate()`),
  `failedJobs()` (GET, `failed_jobs` tablosu paginate),
  `retry(string $jobId)` (POST, `Artisan::call('queue:retry', [...])` +
  `retry_count` increment)
- `app/Http/Controllers/Api/ProductController.php` — `index()` paginate
- `app/Http/Controllers/Api/HealthController.php` — DB/Redis/queue
  bağlantısını ping'ler
- `app/Http/Requests/TriggerSyncRequest.php` — `provider` alanı
  `Rule::enum(ProviderType::class)` ile valide edilir
- `app/Http/Resources/ProductResource.php`, `SyncLogResource.php` —
  response şeklini controller'dan ayırır
- `routes/api.php` — 7 route, hepsi `ApiResponseTrait` kullanan controller'a

**Pattern(s) applied:** Yok yeni — bu faz Faz 0-3'te kurulan servisleri HTTP
yüzeyine bağlıyor, controller'lar ince kalıyor (iş mantığı zaten serviste).

**SOLID:**
- **SRP:** Controller'lar sadece request→service→response çevirisi yapar,
  `TriggerSyncRequest` validasyonu, `ApiResponseTrait` zarflamayı taşır.
- **ISP:** `ApiResponseTrait` sadece response şekillendirme metodları sunar
  — controller'a auth/validation gibi ilgisiz davranış sızdırmaz.

**Depends on:** Faz 1 (Product, SyncLog), Faz 3 (job dispatch, failed_jobs)

**Verification:**
```
php artisan route:list --path=api
curl -X POST localhost:8080/api/sync/trigger -d '{"provider":"dummyjson"}' -H 'Content-Type: application/json'
curl localhost:8080/api/sync/status
curl localhost:8080/api/products?page=1
curl localhost:8080/api/health
```

**Not:** `app/Exceptions/Handler.php` da bu fazda güncellenir — `api/*`
altındaki HER hata (validasyon, 404, 500 dahil) case'in standart
`{success:false, error:{code,message}}` zarfına çevrilir; bu olmadan
Laravel'in varsayılan JSON hata formatı sızar.

**Önemli:** `retry()`'daki `{jobId}` = `failed_jobs.uuid`'dir, numeric
`id` DEĞİL — bu projede `config/queue.php`'deki
`QUEUE_FAILED_DRIVER=database-uuids` varsayılanı yüzünden Laravel'in
`DatabaseUuidFailedJobProvider`'ı (ve dolayısıyla `queue:retry` komutu)
job'u `uuid` koluna göre arar. Route `whereUuid('jobId')` kullanmalı.

---

## Faz 5 — Alerting Sistemi (Bonus, +%5)

**Goal:** 4 eşik ihlalinde (3 ardışık sync fail, 10+ failed job, 5 ardışık API
fail, 100+ queue backlog) 5 dakikada 1'i geçmeyecek şekilde structured JSON
log + opsiyonel Slack webhook.

**Files:**
- `app/Services/Alerts/AlertService.php` — 4 ayrı public metod (tek bir
  `checkAndAlert()` yerine, her biri kendi çağrı noktasından tetiklenir):
  - `recordSyncSuccess(ProviderType)` — ardışık-fail sayacını sıfırlar
    (`SyncRunCoordinator::finishSuccessfully()`'den çağrılır)
  - `recordSyncFailure(ProviderType)` — sayacı artırır, eşik
    (`ALERT_CONSECUTIVE_SYNC_FAILURES`, varsayılan 3) aşıldıysa alert
    (`SyncRunCoordinator::finishWithFailure()` ve `SyncProviderJob::failed()`'den)
  - `recordCircuitBreakerTripped(ProviderType, int $consecutiveFailures)` —
    `CircuitBreakerOpenException` yakalanınca (`ALERT_CONSECUTIVE_API_FAILURES`)
  - `checkFailedJobBacklog()` / `checkQueueBacklog()` — sırasıyla
    `ALERT_FAILED_JOB_THRESHOLD` / `ALERT_QUEUE_BACKLOG_THRESHOLD`
  - Ortak private `alert()`: throttle (`Cache`, tip+provider bazlı,
    `ALERT_THROTTLE_MINUTES`), `storage/logs/alerts.log`'a düz (nested
    değil) `{"level":"ALERT","type":...,"severity":...,"timestamp":...,
    "provider":...,...}` JSON, `ALERT_SLACK_WEBHOOK_URL` set'liyse
    `Http::post()`
- `config/logging.php` — `alerts` kanalı, bilerek `single` driver
  (`daily` DEĞİL — case sabit bir dosya adı istiyor, tarih eklenmiş
  dönen dosyalar değil)
- `app/Exceptions/Sync/CircuitBreakerOpenException.php` —
  `$consecutiveFailures` yapılandırılmış alanı (Faz 2'de eklendi,
  burada tüketilir)

**Pattern(s) applied:**
- **Null Object** düşünüldü (`NullAlertChannel` Slack yoksa) ama
  **atlandı** — tek bir `if (! empty($webhookUrl))` çağrı noktasında
  yeterince açık, ayrı bir sınıf hiyerarşisi over-engineering olurdu.
- **Observer** düşünüldü (model/job event listener'ları ile alert tetikleme)
  ama **atlandı** — alert kontrolü sync akışının kendi doğruluğunun bir
  parçası (kaç tane ardışık hata oldu), yan etki değil; `SyncRunCoordinator`
  içinde doğrudan çağrı daha okunur.
- **Notification class** (plan ilk yazımında `SlackAlertNotification`
  öngörmüştü) **atlandı** — tek, sabit bir webhook URL'ine düz bir
  `Http::post()` atmak, Laravel'in notifiable-routing makinesini
  (queue, kanal seçimi vb.) gerektirmeyecek kadar basit bir senaryo;
  `Http::fake()` ile aynı şekilde test edilebilir.

**SOLID:**
- **SRP:** `AlertService` sadece eşik kontrolü + throttle + log/notify
  yapar; sync mantığına karışmaz — her metod tek bir eşiğe bakar.

**Depends on:** Faz 3 (`SyncRunCoordinator`'ın başarı/başarısızlık/circuit-breaker
çağrı noktaları), Faz 2 (`CircuitBreakerOpenException.$consecutiveFailures`)

**Verification:**
```
php artisan tinker --execute="
\$a = app(App\Services\Alerts\AlertService::class);
\$a->recordSyncFailure(App\Enums\ProviderType::DummyJson);
\$a->recordSyncFailure(App\Enums\ProviderType::DummyJson);
\$a->recordSyncFailure(App\Enums\ProviderType::DummyJson); // 3. tekrar -> alert
"
cat storage/logs/alerts.log
```

---

## Faz 6 — Frontend Dashboard

**Goal:** Tek sayfalık Blade dashboard; manuel trigger, son sync durumu,
history tablosu, failed jobs + retry butonu, auto-refresh.

**Files:**
- `resources/views/dashboard.blade.php` — Tailwind CDN + vanilla JS
  `fetch()` ile Faz 4'teki endpoint'lere bağlanır; `setInterval` ile
  auto-refresh
- `routes/web.php` — `/` → dashboard view
- `public/js/dashboard.js` (opsiyonel ayrı dosya, inline de olur — proje
  build step'i yok)

**Pattern(s) applied:** Yok — `package.json`/`vite.config.js` CLAUDE.md'de
zaten "kullanılmayan leftover" olarak işaretli; React/Vue gibi bir framework
**bilinçli olarak atlandı**, çünkü case'in istediği "basit, fonksiyonel, süslü
olmayan" tek sayfa için build tooling'in getirdiği karmaşıklık YAGNI.

**SOLID:** Uygulanamaz (view katmanı, framework'süz vanilla JS).

**Depends on:** Faz 4 (tüm API endpoint'leri)

**Verification:** Tarayıcıda `http://localhost:8080/` aç, trigger butonuna
bas, history/failed-jobs tablolarının auto-refresh ile güncellendiğini
gözle doğrula (mobil genişlikte de responsive olduğunu kontrol et).

---

## Faz 7 — Test Katmanı (%15)

**Goal:** %70+ coverage, README'nin listelediği kritik senaryoların hepsi
kapsanıyor.

**Files:**
- `tests/Unit/HashServiceTest.php` — aynı input → aynı hash; alan değişince
  hash değişir; volatile alan (rating) değişince hash **değişmez**
- `tests/Unit/DeltaSyncServiceTest.php` — yeni ürün ekleniyor, değişen ürün
  güncelleniyor, değişmeyen ürün dokunulmuyor, provider'da artık olmayan ürün
  soft-delete ediliyor, tekrar görünürse restore ediliyor
- `tests/Unit/ThrottledHttpClientTest.php` — 429 sonrası backoff sırası
  (1s/2s/4s), 5 ardışık hata sonrası circuit breaker exception fırlatıyor
- `tests/Feature/SyncProviderJobTest.php` — **idempotency**: aynı job 2 kez
  çalıştırılınca duplicate kayıt oluşmuyor (unique constraint + upsert);
  aynı provider için eşzamanlı dispatch'te ikinci job kilitleniyor
- `tests/Feature/Api/SyncControllerTest.php`, `ProductControllerTest.php` —
  7 endpoint, response zarfı şekli (`success/data/meta/message`), pagination
  meta'sı, validation hataları
- `tests/Unit/AlertServiceTest.php` — eşik altı/üstü, throttle penceresi

**Pattern(s) applied:** Yok — test katmanı, önceki fazlardaki DIP sayesinde
zaten mock'lanabilir (`ProviderClientInterface` sahte implementasyonla
`bind()` edilebilir, gerçek HTTP çağrısı yapılmaz).

**SOLID:** Uygulanamaz (test kodu).

**Depends on:** Faz 1-5 (test edilecek her şey)

**Verification:**
```
php artisan test --coverage --min=70
```

---

## Faz 8 — Dokümantasyon & Teslim Hazırlığı (%10)

**Goal:** README.md, case'in "Teslim Formatı" bölümünün istediği her şeyi
içerir; proje 5 dakikada ayağa kalkar.

**Files:**
- `README.md` — case brief'in üzerine: kurulum adımları (docker-compose +
  manuel), `.env` açıklaması, migrate/seed komutları, `horizon`/
  `schedule:work` nasıl başlatılır, test komutu, **teknik kararlar**
  bölümü (hash stratejisi, job uniqueness, idempotency, rate limiting —
  hepsi zaten `CLAUDE.md`'de var, oraya referans verip özetle), API
  dokümantasyonu (endpoint tablosu + örnek response)
- `postman/product-sync.postman_collection.json` (opsiyonel, +puan)
- `CLAUDE.md` — varsa faz boyunca ortaya çıkan sapmaları güncelle (örn.
  gerçek dosya adları plan'dakinden farklılaştıysa)

**Depends on:** Faz 0-7 (hepsi bitince doğru dokümante edilebilir)

**Verification:** Temiz bir clone'da README'deki adımları harfiyen izleyip
5 dakikada ayağa kaldığını doğrula.

---

## Nelerin Yapılmadığı ve Neden

- **Repository pattern** — atlandı; tek veri kaynağı (MySQL/Eloquent), swap
  edilecek ikinci bir persistence mekanizması yok, Eloquent + Service katmanı
  yeterli.
- **Event/Listener sistemi** (sync tamamlanunca event fire etmek) — atlandı;
  şu an tek dinleyici `AlertService` ve o zaten sync akışının doğruluğunun
  parçası, ayrı event/listener çifti gereksiz dolaylama olurdu.
- **Paralel bir DLQ tablosu** — atlandı; case'in kendi şeması native
  `failed_jobs`'u zaten öneriyor, `retry_count` kolonu eklemek yeterli.
- **Null Object (alert kanalı için)** — atlandı; tek bir config kontrolü,
  ayrı sınıf hiyerarşisinden daha okunur.
- **Template Method (provider job'ları için ortak base class)** — atlandı;
  iki provider'ın da job akışı birebir aynı, farklılık zaten
  `ProviderClientInterface` implementasyonunda; ikinci bir soyutlama
  katmanına gerek yok.
- **API versioning (/api/v1)** — atlandı; case tek versiyon istiyor, YAGNI.
- **Vue/React frontend** — atlandı; case "basit & minimal" istiyor, build
  step eklemek `package.json`'ı "unused leftover" olmaktan çıkarıp gereksiz
  karmaşıklık getirirdi.
- **withoutOverlapping() scheduler seviyesinde** — atlandı; uniqueness zaten
  `SyncRunCoordinator` seviyesinde (`Cache::lock()`, tüm batch ömrü boyunca)
  çözülüyor, iki katmanda aynı kilidi tutmak kafa karıştırıcı olurdu.
- **Sayfa job'ları için de `ShouldBeUnique`** — atlandı; uniqueness zaten
  tek bir provider-bazlı kilit olarak `SyncRunCoordinator`'da tutuluyor,
  her sayfa job'una ayrıca kilit eklemek gereksiz ve batch'in kendi
  `cancelled()` mekanizmasıyla çakışırdı.

---

## Suggested Build Order

0 → 1 → 2 → 3 → 4 → 7 (Faz 1-4'ü kapsayan testler her fazın hemen ardından da
yazılabilir, ayrı bir faz olarak sona bırakılması zorunlu değil) → 5 → 6 → 8

**Kritik yol (fonksiyonellik %40 + code quality %30 için):** Faz 0-4.
**Bonus/puan artırıcı:** Faz 5 (alerting +%5), Faz 6 zaten minimum gereksinim
ama görsel olarak son yapılabilir, Docker (+%10) zaten Faz 0'da iskelet
hazır — sadece `docker-compose up` ile doğrulanıyor, ayrı bir faz gerektirmiyor.

## Overall Verification

```
docker-compose up -d
docker-compose exec server php artisan migrate --seed
docker-compose exec server php artisan test --coverage --min=70
curl localhost:8080/api/health
curl -X POST localhost:8080/api/sync/trigger -d '{"provider":"dummyjson"}' -H 'Content-Type: application/json'
open localhost:8080/horizon
open localhost:8080/   # dashboard
```
