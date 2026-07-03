# CLAUDE.md

## Proje amacı
2 mock tedarikçi API'sinden (DummyJSON, FakeStore) yerel bir MySQL veri
tabanına, kuyruklu, delta (hash-bazlı) sync job'ları üzerinden API-tabanlı
ürün senkronizasyonu. Bu bir teknik case teslimidir — case gereksinimlerinin
tamamı, API dokümanları, kurulum adımları ve değerlendirme kriterleri için
`README.md`'ye bakın.

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
- `app/Services/Alerts/AlertService.php` — 4 eşik kontrolü (ardışık sync
  fail, failed-job backlog, ardışık API fail, queue backlog), alert
  tipi+provider başına 5 dk cache-throttled, `storage/logs/alerts.log`'a
  structured JSON (`single` log kanalı, `daily` değil).
- `app/Http/Traits/ApiResponseTrait.php` — `{success, data, meta, message}` /
  `{success: false, error: {code, message}}` zarfı

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

## Daha fazlası için nereye bakılır
- Tam case gereksinimleri, DB tasarım gerekçesi, hash/idempotency/
  rate-limiting yazıları, API dokümanları, kurulum adımları: `README.md`
- Config yüzeyi (rate limit, alert eşikleri, provider listesi):
  `config/sync.php`
- `.env.example`, gerekli her environment variable'ı dokümante eder
