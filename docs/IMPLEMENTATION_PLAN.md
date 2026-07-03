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

**Goal:** Herhangi bir provider'dan veri çekip hash'leyip DB'ye delta olarak
yazan, kendi kendini throttle eden servis katmanı — henüz queue/job yok, çıplak
servisler unit test edilebilir durumda.

**Files:**
- `app/Contracts/ProviderClientInterface.php` — `fetchAll(): Collection`,
  `fetchOne(string $externalId): ?array` (sadece bu iki metod — ISP: caller'ın
  ihtiyacı bundan fazlası değil)
- `app/Services/Providers/DummyJsonProvider.php`,
  `app/Services/Providers/FakeStoreProvider.php` — interface implementasyonu;
  her biri kendi API şeklini (`title` vs `name`, `rating` gibi volatile
  alanlar) normalize edip ortak bir array şekline çevirir
- `app/Services/Providers/ProviderFactory.php` — `ProviderType` enum → client
  instance resolver
- `app/Providers/AppServiceProvider.php` — `ProviderClientInterface`'i
  `ProviderFactory` üzerinden `bind()` et (singleton **değil** — stateless,
  her çağrıda taze instance testte mock'lanabilsin)
- `app/Services/Sync/HashService.php` — `hash(array $normalizedProduct):
  string` → `{name, price, stock, description}` üzerinden `sha256`
  (`external_id`/`provider_type` kimlik alanı olduğu, `ratings`/`images` gibi
  volatile alanlar da içerik değişikliği sayılmadığı için hash'e dahil değil)
- `app/Services/Sync/ThrottledHttpClient.php` — 5 rps pacing (`usleep`
  tabanlı basit token pacing), 429 → exponential backoff (1s/2s/4s), 5
  ardışık hatada circuit breaker (exception fırlatıp job'u durdurur)
- `app/Services/Sync/DeltaSyncService.php` — `sync(ProviderType $provider):
  SyncResult`: provider'dan tüm listeyi çek → mevcut DB kayıtlarıyla
  `external_id` bazında eşleştir → yeni/hash-farklı/eksik olanları ayır →
  tamamı **tek `DB::transaction()`** içinde upsert + soft-delete
- `app/DTOs/SyncResult.php` — `added:int, updated:int, deleted:int,
  errorMessage:?string` (controller/job'a çıplak array yerine tip geçmek için)

**Pattern(s) applied:**
- **Strategy** (`ProviderClientInterface` + iki implementasyon) — DummyJSON ve
  FakeStore'un veri şekli farklı ama sync akışı aynı; yeni bir tedarikçi
  eklemek sadece yeni bir implementasyon demek, `DeltaSyncService`'e
  dokunulmaz.
- **Factory** (`ProviderFactory`) — enum'dan implementasyon seçimi tek yerde
  toplanır, `AppServiceProvider` binding'i bu resolver'a delege eder.
- **Decorator** (`ThrottledHttpClient`, provider client'ların HTTP çağrısını
  sarar) — rate-limit/backoff davranışı provider implementasyonlarının
  kendisine karışmaz, ayrı bir katman olarak eklenir/çıkarılabilir.
- **DTO** (`SyncResult`) — servis → job/controller sınırında tip güvenliği.

**SOLID:**
- **SRP:** `HashService` sadece hash'ler, `DeltaSyncService` sadece
  add/update/delete kararını verir, `ThrottledHttpClient` sadece pacing/
  backoff yapar — üçü de ayrı sebeplerle değişir.
- **OCP:** Yeni tedarikçi = yeni `ProviderClientInterface` implementasyonu +
  `ProviderFactory`'ye bir `match` kolu; `DeltaSyncService` değişmez.
- **LSP:** `DummyJsonProvider` ve `FakeStoreProvider` aynı normalize edilmiş
  array şeklini döndürür — `DeltaSyncService` hangi implementasyonla
  çalıştığını bilmeden aynı davranışı bekleyebilir.
- **ISP:** `ProviderClientInterface` sadece 2 metod — bir mock'un tüm
  yüzeyi implemente etmesi kolay.
- **DIP:** `DeltaSyncService`, `ProviderClientInterface`'e bağımlı;
  somut client `AppServiceProvider` binding'i üzerinden enjekte edilir.

**Depends on:** Faz 1 (Product modeli, migration'lar)

**Verification:**
```
php artisan tinker --execute="app(\App\Services\Sync\DeltaSyncService::class)->sync(\App\Enums\ProviderType::DummyJson)"
# ilk çalıştırma: added=100, ikinci çalıştırma (veri değişmediyse): added=0 updated=0
php artisan test --filter=HashServiceTest
```

429/circuit-breaker senaryoları gerçek API'de tetiklenemez (mock API'ler
rate-limit uygulamıyor) — Faz 7'de `Http::fake()` ile unit test edilecek.

---

## Faz 3 — Queue, Job & Scheduler Katmanı

**Goal:** Sync işlemi arka planda, provider-bazlı kilitli, retry/backoff'lu
bir job olarak çalışır; scheduler her 5-10 dakikada bir tetikler; Horizon
dashboard'u ayakta.

**Files:**
- `app/Jobs/SyncProviderJob.php` — `implements ShouldQueue, ShouldBeUnique`;
  `$uniqueId` = provider value (`"dummyjson"`/`"fakestore"` — job class'ı
  değil), `$uniqueFor` = `config('sync.job_unique_for')`; `tries = 3`,
  `backoff = [1, 2, 4]`; `handle()` sadece `DeltaSyncService::sync()`'i çağırıp
  sonucu `SyncLog`'a yazar; `failed()` hook'u `AlertService`'e haber verir
  (Faz 5)
- `app/Console/Kernel.php` — `$schedule->job(new SyncProviderJob($provider))
  ->everyFiveMinutes()` her iki provider için (`withoutOverlapping` DEĞİL —
  uniqueness zaten job seviyesinde, scheduler'da tekrar kilitlemek yanlış
  katmanda çözüm olurdu)
- `config/horizon.php` — tek, provider-agnostic `product-sync` kuyruğu
  (`product-sync-supervisor`) — dummyjson/fakestore için ayrı kuyruklara
  bölünmez, hangi provider olduğu zaten job'ın kendi parametresinde;
  supervisor sayısı düşük (mock API'ler küçük, 5rps limit zaten darboğaz)
- Horizon'un hangi container'da, hangi mekanizmayla (supervisord) çalıştığı
  Faz 0'da (`worker` container'ı) çözüldü — burada tekrar edilmiyor (DRY)

**Pattern(s) applied:**
- **Singleton** düşünüldü ama **atlandı** — `ShouldBeUnique`'in cache lock'u
  zaten Redis üzerinden atomik ve stateless; ek bir uygulama-seviyesi
  singleton rate-limiter'a gerek yok, `ThrottledHttpClient` her job
  invocation'ında taze instance olarak yeterli.
- **Template Method** düşünüldü (ortak bir `SyncJob` base class + provider'a
  özel hook) ama **atlandı** — tek bir job class zaten provider'ı parametre
  olarak alıp `DeltaSyncService`'e delege ediyor, iki alt sınıfa ayırmak
  gereksiz soyutlama olurdu.

**SOLID:**
- **SRP:** `SyncProviderJob`'ın tek sorumluluğu queue/retry/uniqueness
  orkestrasyonu; asıl iş mantığı `DeltaSyncService`'te kalır (job'u unit
  test etmek yerine servisi test etmeyi kolaylaştırır).

**Depends on:** Faz 2 (`DeltaSyncService`)

**Verification:**
```
php artisan horizon:install && php artisan migrate
php artisan horizon   # ayrı terminalde
php artisan tinker --execute="App\Jobs\SyncProviderJob::dispatch(\App\Enums\ProviderType::DummyJson)"
# aynı provider için ikinci dispatch aynı anda -> queue'da tekilleştiğini Horizon UI'da doğrula
php artisan schedule:list   # her iki provider'ın 5 dk'da bir göründüğünü doğrula
```

**Not:** `SyncProviderJob::failed()` hook'u `AlertService`'e bağımlı olduğu
için Faz 5'te eklenir (bu fazda henüz yok). Her job attempt kendi `SyncLog`
satırını oluşturur (bir attempt'in mutasyonlarını sıradaki retry'a taşımak
yerine) — bu, `sync/history` endpoint'inde hangi denemenin başarısız/başarılı
olduğunu ayrı ayrı görebilmeyi sağlar ve iş kuyruğu serialization'ının
retry'lar arası mutable state taşıma belirsizliğinden kaçınır.

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
- `app/Services/Alerts/AlertService.php` — `checkAndAlert()` 4 kontrolü
  çalıştırır; her alert tipi için `Cache::remember` ile 5 dk throttle
  (`ALERT_THROTTLE_MINUTES`); `storage/logs/alerts.log`'a
  `{"level":"ALERT","type":...,"severity":...,"timestamp":...,"details":{...}}`
  yazar
- `config/logging.php` — `alerts` diye ayrı bir `channel` (daily, ayrı dosya)
- `app/Notifications/SlackAlertNotification.php` — `ALERT_SLACK_WEBHOOK_URL`
  set'liyse gönderilir (Null Object yerine basit `if (config(...))` — bkz.
  §Yapılmayanlar)
- `SyncProviderJob::failed()` ve `DeltaSyncService` çağrı noktalarına
  `AlertService::checkAndAlert()` çağrısı

**Pattern(s) applied:**
- **Null Object** düşünüldü (`NullAlertChannel` Slack yoksa) ama
  **atlandı** — tek bir `if (config('services.slack_webhook'))` çağrı
  noktasında yeterince açık, ayrı bir sınıf hiyerarşisi over-engineering
  olurdu.
- **Observer** düşünüldü (model/job event listener'ları ile alert tetikleme)
  ama **atlandı** — alert kontrolü sync akışının kendi doğruluğunun bir
  parçası (kaç tane ardışık hata oldu), yan etki değil; `DeltaSyncService`/
  `SyncProviderJob` içinde doğrudan çağrı daha okunur.

**SOLID:**
- **SRP:** `AlertService` sadece eşik kontrolü + throttle + log/notify
  yapar; sync mantığına karışmaz.

**Depends on:** Faz 3 (job failure hook), Faz 2 (consecutive-failure sayacı
`ThrottledHttpClient`'ta zaten tutuluyor)

**Verification:**
```
# eşik altı bir senaryo simüle et (ör. provider URL'sini yanlış env'e çevirip 5 job fail ettir)
tail -f storage/logs/alerts.log
php artisan test --filter=AlertServiceTest
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
  job seviyesinde (`ShouldBeUnique`) çözülüyor, iki katmanda aynı kilidi
  tutmak kafa karıştırıcı olurdu.

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
