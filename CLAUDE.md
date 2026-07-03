# CLAUDE.md

## Proje amacı
"Product Sync" — 2 mock tedarikçi API'sinden (DummyJSON, FakeStore) yerel
bir MySQL veritabanına, kuyruklu, delta (hash-bazlı) sync job'ları
üzerinden API-tabanlı ürün senkronizasyonu. Provider-agnostic: yeni bir
tedarikçi eklemek `ProviderClientInterface`'i implemente etmekten ve
`ProviderType` enum'una bir case eklemekten ibarettir, sync motorunun geri
kalanı (job'lar, hash, mark-and-sweep, coordinator) provider'a özgü kod
içermez. Sistem, Horizon üzerinden izlenebilen arka plan job'ları,
Reverb üzerinden sıfır-polling gerçek zamanlı bir dashboard ve 7+1
endpoint'lik bir REST API sunar. Bu bir teknik case teslimidir — case
gereksinimlerinin tamamı, API dokümanları, kurulum adımları ve
değerlendirme kriterleri için `README.md`'ye bakın.

## Teknoloji yığını
- Laravel 10.x, PHP 8.2, MySQL 8, Redis 7 (queue + cache + Horizon)
- Queue dashboard/monitoring/manuel retry arayüzü için Laravel Horizon
- Predis (pure-PHP client, phpredis extension DEĞİL) — Docker image'ını
  yalın tutmak için, C-extension build'ine gerek kalmasın diye.
  **`.env`'de `REDIS_CLIENT=predis` set edilmiş olmalı** — bu olmadan
  Laravel varsayılan olarak `phpredis`'e düşer ve her Redis çağrısı
  (cache, queue, health check) `Class "Redis" not found` hatasıyla patlar,
  çünkü C-extension bilerek kurulu değil.
- Dashboard'un gerçek-zamanlı güncellemeleri için Laravel Reverb (WebSocket
  broadcasting) — kendi `reverb` container'ında çalışır, nginx tarafından
  `/app/` altında proxy'lenir (`docker/nginx/conf.d/default.conf`), böylece
  tarayıcı sadece tek bir host/porta bağlanır. Şimdilik SSL yok
  (`REVERB_SCHEME=http`, düz `ws://`).
- Frontend build adımı yok: Blade + vanilla JS + Tailwind CDN.
  `package.json`/`vite.config.js`, Laravel iskeletinden kalma kullanılmayan
  dosyalar.
- Testler, SQLite değil ayrı bir `db_test` MySQL container'ına karşı çalışır
  (`phpunit.xml` → `DB_HOST=db_test`, host port 3307) — üretimle aynı motor,
  böylece motor-spesifik davranışlar test paketinde gözden kaçmaz
- Unit/Feature testlerinin yanında `tests/Browser/` altında Laravel Dusk +
  headless Chrome ile gerçek bir E2E testi var (dashboard'u gerçek
  tarayıcıda açıp sync tetikleyip Reverb üzerinden gelen canlı güncellemeyi
  doğrular) — mock'lanmış bir HTTP/JS testi değil
- Horizon ve scheduler (`schedule:work`), iki ayrı container yerine tek bir
  `worker` container'ında `supervisord` altında çalışır
  (`docker/supervisord/{supervisord.conf,horizon.conf}`)

## Mimari harita — sync motoru (sayfa-başına-job, batch-koordineli)

Her provider'ın senkronizasyonu, sayfa başına bir `FetchProviderPageJob`'a
bölünür, bunlar birlikte bir `Bus::batch()` olarak dispatch edilir ve
`SyncRunCoordinator` tarafından koordine edilir — tek bir job'un tüm
sayfaları kendi içinde döngüyle çekmesi YOK. Bu, tek bir job'un sınırsız
sayıda sayfa çekmeye çalışırken zaman aşımına uğramasını engeller ve
sayfaların paralel çekilmesine izin verir. Tam gerekçe ve yerine geçtiği
tek-job tasarımı için `CHANGELOG.md`'ye bakın.

- `app/Contracts/ProviderClientInterface.php` — `fetchPage(int $page):
  ProviderPage` (provider kendi `totalPages`'ini kendisi hesaplar),
  `fetchOne()`
- `app/DTOs/ProviderPage.php` — `{items, totalPages}`,
  `app/DTOs/SyncResult.php` — `{added, updated, deleted}` (tamamlanmış bir
  run'ın nihai özeti)
- `app/Services/Providers/{DummyJson,FakeStore}Provider.php` — implementasyonlar
- `app/Services/Providers/ProviderFactory.php` — bir `ProviderType`'ı
  client'ına çözer, provider-key'li `ThrottledHttpClient`'ı kendisi inşa
  eder (container binding'i değil — aşağıya bakın)
- `app/Services/Sync/HashService.php` — `{name, price, stock, description}`
  üzerinden sha256 kanonik hash
- `app/Services/Sync/ThrottledHttpClient.php` — 5rps pacing + 429 backoff +
  5-ardışık-hatada circuit breaker. **Redis-tabanlı ve provider başına
  paylaşımlı** (`$providerKey`), instance-local DEĞİL: aynı provider için
  birden çok `FetchProviderPageJob`, farklı worker'larda eşzamanlı
  çalışabilir ve hepsi TEK bir rate limit / TEK bir hata sayacına uymak
  zorundadır.
- `app/Services/Sync/DeltaSyncService.php` — `upsertPage()`: sadece tek bir
  sayfanın item'larını ekler/günceller. Silme tespiti BURADA YOK (aşağıya
  bakın).
- `app/Services/Sync/SyncRunCoordinator.php` — bir provider'ın run'ını
  orkestre eder: bir `Cache::lock()` alır (provider bazlı, TÜM batch ömrü
  boyunca tutulur — sadece tek bir job'u kapsayan `ShouldBeUnique` DEĞİL),
  sayfa-job batch'ini dispatch eder, `then()`/`catch()`'te: silmeleri
  süpürür (mark-and-sweep — her upsert `last_synced_at`'i bu run'a özel
  SABİT bir başlangıç zaman damgasıyla işaretler; tüm sayfalar başarıyla
  bitince bundan eski olan her şey soft-delete edilir), Redis
  sayaçlarından added/updated'i bir `SyncResult`'ta toplar, `SyncLog`'u
  günceller, kilidi serbest bırakır.
- `app/Jobs/FetchProviderPageJob.php` — tek bir sayfayı çeker+upsert eder;
  `tries=3`, `backoff=[1,2,4]`; batch'i zaten iptal edildiyse atlar.
- `app/Jobs/SyncProviderJob.php` — scheduler'ın/API'nin dispatch ettiği ince
  wrapper; sadece `SyncRunCoordinator::start()`'ı çağırır. Tek,
  provider-agnostic `product-sync` kuyruğuna dispatch edilir (bkz.
  `config/horizon.php`'nin `product-sync-supervisor`'ı).
- `app/Console/Kernel.php` — scheduler; her `ProviderType` için bağımsız bir
  cron girdisi (`*/{SYNC_INTERVAL_MINUTES} * * * *`) `SyncProviderJob`
  kuyruklar. Bilinçli olarak `withoutOverlapping()` YOK — tekilleştirme
  zaten `SyncRunCoordinator`'ın kilidinde, scheduler seviyesinde ikinci bir
  kilit yanlış katmanda çözüm olurdu.
- `app/Services/Alerts/AlertService.php` — 4 eşik kontrolü (ardışık sync
  fail, failed-job backlog, ardışık API fail, queue backlog), alert
  tipi+provider başına 5 dk cache-throttled, `storage/logs/alerts.log`'a
  structured JSON (`single` log kanalı, `daily` değil).
- `app/Enums/ProviderType.php` — `DummyJson`/`FakeStore` backed enum; string
  değeri (`dummyjson`/`fakestore`) DB kolonlarında, job uniqueness
  kilidinde ve `config/sync.php` array key'lerinde kalıcı kimlik olarak
  kullanılır.
- `app/Exceptions/Sync/` — `ProviderRequestException` (temel; bir HTTP
  isteği kalıcı olarak başarısız oldu), `CircuitBreakerOpenException`
  (ardışık hata sayısını taşır, `AlertService` string parse etmeden okur),
  `PaginationLimitExceededException` (provider'ın `totalPages`'i
  `SyncRunCoordinator::MAX_PAGES`'i aşarsa — bozuk/anormal bir `total`
  değerinin binlerce sayfa job'u kuyruklamasını engeller).

### Gerçek zamanlı dashboard (Reverb broadcasting)
`sync-status` kanalı (herkese açık, auth yok — dashboard internal bir araç)
üzerinden 3 event yayınlanır, hepsi `ShouldBroadcastNow` (kuyruklanmaz,
anında broadcast edilir):
- `app/Events/SyncStatusUpdated.php` — bir provider'ın run'ı sonuçlanınca
  (`SyncRunCoordinator` tarafından)
- `app/Events/FailedJobRecorded.php` — Laravel'in native `JobFailed`
  event'ini dinleyen `app/Listeners/BroadcastFailedJob.php` tarafından; hangi
  job class'ı başarısız olursa olsun tek noktadan yakalanır
- `app/Events/SyncHistoryCleared.php` — `SyncController::clearHistory()`
  çağrıldığında, dashboard'u açık tutan herkesin tablosu anında boşalsın diye

### HTTP katmanı
- `app/Http/Requests/TriggerSyncRequest.php` — `POST /api/sync/trigger`
  gövde validasyonu (`provider`, `ProviderType` enum değerlerinden biri)
- `app/Http/Resources/{ProductResource,SyncLogResource}.php` — dış dünyaya
  açılan response şekilleri; `data_hash` gibi dahili kolonlar sızdırılmaz
- `app/Http/Traits/ApiResponseTrait.php` — `{success, data, meta, message}` /
  `{success: false, error: {code, message}}` zarfı
- `app/Http/Controllers/Api/{SyncController,ProductController,HealthController}.php`
  — ince controller'lar, iş mantığı yok; 8 endpoint için routes/api.php'ye
  bakın (`POST /sync/trigger`, `GET /sync/status`, `GET|DELETE
  /sync/history`, `GET /sync/failed-jobs`, `POST /sync/retry/{uuid}`,
  `GET /products`, `GET /health`)

## Temel konvansiyonlar
- Her API controller `ApiResponseTrait` kullanır — asla ham
  `response()->json()` döndürülmez.
- Idempotency = `unique(provider_type, external_id)` constraint + item
  başına transaction + hash no-op atlama.
- Job/run uniqueness anahtarı sadece provider değeridir
  (`"dummyjson"`/`"fakestore"`), job class + parametreler değil — provider
  başına bir kilit, TÜM batch boyunca tutulur (bkz. `SyncRunCoordinator`),
  job invocation başına değil.
- Hash, `external_id`/`provider_type`'ı (içerik değil kimlik) ve
  provider'a özgü volatile alanları (rating, resim) hariç tutar.
- `failed_jobs`, Laravel'in native tablosudur (+ eklenen tek bir
  `retry_count` kolonu) — paralel bir DLQ tablosu oluşturulmaz; retry'lar
  `queue:retry` üzerinden yürür. `job_batches` de Laravel'in native
  tablosudur (`queue:batches-table`), `Bus::batch()` için zorunlu.
- Ürünler, provider'ın son sync'inde artık bulunmadıklarında hard-delete
  değil soft-delete (`deleted_at`) edilir — geçmişi korur ve ürün tekrar
  ortaya çıkarsa restore edilebilmesini sağlar. Tek geçişli bir diff değil,
  mark-and-sweep ile tespit edilir (bkz. `SyncRunCoordinator`).

## Kod kalitesi ve test stratejisi
- Kod, SOLID ve DRY prensiplerine uyar: her class tek bir sorumluluk taşır
  (provider client'lar `ProviderClientInterface`'e bağlıdır, controller'lar
  ince tutulur, iş mantığı `Services/` katmanında toplanır), provider-özel
  davranış interface arkasına soyutlanır (Open/Closed — yeni provider
  eklemek mevcut sync motoru kodunu değiştirmeyi gerektirmez), tekrarlayan
  mantık (response zarfı, hash hesaplama, rate limiting) ortak
  service/trait'lere çıkarılır.
- Test paketi üç katmanlıdır: `tests/Unit/` (tekil class'lar — services,
  jobs, enums, exceptions), `tests/Feature/` (API endpoint'leri ve
  idempotency/sweep gibi uçtan-uca iş akışları, gerçek `db_test` MySQL'e
  karşı), `tests/Browser/` (Laravel Dusk + headless Chrome ile gerçek
  tarayıcıda E2E — dashboard'u açıp sync tetikleyip Reverb üzerinden gelen
  canlı güncellemeyi doğrular).

## Daha fazlası için nereye bakılır
- Tam case gereksinimleri, DB tasarım gerekçesi, hash/idempotency/
  rate-limiting yazıları, API dokümanları, kurulum adımları: `README.md`
- Config yüzeyi (rate limit, alert eşikleri, provider listesi):
  `config/sync.php`
- `.env.example`, gerekli her environment variable'ı dokümante eder
