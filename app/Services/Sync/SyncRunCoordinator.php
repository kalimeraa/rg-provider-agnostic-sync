<?php

namespace App\Services\Sync;

use App\DTOs\SyncResult;
use App\Enums\ProviderType;
use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Exceptions\Sync\PaginationLimitExceededException;
use App\Jobs\FetchProviderPageJob;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Alerts\AlertService;
use App\Services\Providers\ProviderFactory;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Bir provider'ın senkronizasyonunu, sayfa başına ayrı bir
 * `FetchProviderPageJob` olarak paralel çalışabilecek şekilde `Bus::batch()`
 * ile yürütür. Bu class, eski (tek job'lu) mimarideki `ShouldBeUnique` +
 * "tüm sayfaları döngüyle çek + tek transaction'da diff'le" tasarımının
 * yerini alır — o tasarımda tek bir job'un TÜM sayfaları çekmeye çalışırken
 * zaman aşımına uğrama riski vardı (bkz. CHANGELOG.md), bu class o riski
 * ortadan kaldırır ama karşılığında üç şeyi kendisi çözmek zorunda:
 *
 * 1. **Uniqueness**: `ShouldBeUnique` tek bir job'a bağlı, bir batch'in
 *    TAMAMINA (tüm sayfalar bitene kadar) yayılan bir kilide değil. Bu
 *    yüzden burada elle bir `Cache::lock()` tutuluyor: `start()` kilidi
 *    alır, batch'in `then()`/`catch()` callback'leri (hangisi tetiklenirse)
 *    `Cache::restoreLock()` ile AYNI kilidi (owner token'ı taşınarak, farklı
 *    bir process/job'dan) serbest bırakır.
 * 2. **Silme tespiti**: artık hiçbir tek çağrı uzak listenin TAMAMINI
 *    görmüyor (bkz. DeltaSyncService'in PHPDoc'u) — bu yüzden `then()`
 *    (TÜM sayfalar başarıyla bitince) bir "sweep" adımı çalıştırır: bu
 *    run'ın sabit başlangıç zaman damgasından (`$syncRunStartedAt`) daha
 *    eski `last_synced_at`'e sahip ürünler (= bu run'da hiçbir sayfa
 *    tarafından dokunulmadı) soft-delete edilir.
 * 3. **Sayaç toplama**: her sayfa job'unun kendi added/updated sayısı,
 *    run bitince tek bir `SyncResult`'ta toplanabilsin diye Redis'te
 *    (`SyncLog` id'sine göre) atomik olarak biriktirilir.
 */
class SyncRunCoordinator
{
    /**
     * Bir provider'ın raporladığı `totalPages` bu sayıyı aşarsa sync hemen
     * durdurulur (provider'ın `total` alanı bozuk/anormal büyük olabilir) —
     * binlerce sayfa job'u kuyruklamak yerine anlaşılır bir hata verilir.
     */
    private const MAX_PAGES = 50;

    private const LOCK_PREFIX = 'product-sync-lock:';

    private const COUNTER_ADDED_PREFIX = 'sync-run-added:';

    private const COUNTER_UPDATED_PREFIX = 'sync-run-updated:';

    public function __construct(
        private readonly ProviderFactory $providerFactory,
        private readonly AlertService $alerts,
    ) {
    }

    /**
     * Bir provider için yeni bir sync run başlatır: provider için zaten
     * aktif bir run varsa (kilit tutuluyorsa) sessizce hiçbir şey yapmadan
     * döner (eski `ShouldBeUnique` davranışını taklit eder). Aksi halde
     * kilidi alır, ilk sayfayı çekip toplam sayfa sayısını öğrenir, tüm
     * sayfalar için birer `FetchProviderPageJob` içeren bir batch dispatch
     * eder ve döner — batch'in kendisinin bitmesini BEKLEMEZ.
     */
    public function start(ProviderType $provider): void
    {
        $lock = Cache::lock(self::LOCK_PREFIX.$provider->value, (int) config('sync.job_unique_for', 900));

        if (! $lock->get()) {
            return;
        }

        $owner = $lock->owner();
        $syncRunStartedAt = now();

        ThrottledHttpClient::resetConsecutiveFailures($provider->value);

        $log = SyncLog::create([
            'provider_type' => $provider,
            'started_at' => $syncRunStartedAt,
            'status' => 'running',
        ]);

        try {
            $firstPage = $this->providerFactory->make($provider)->fetchPage(0);

            if ($firstPage->totalPages > self::MAX_PAGES) {
                throw new PaginationLimitExceededException(
                    "Provider {$provider->value} toplam {$firstPage->totalPages} sayfa raporladı ".
                    '(güvenlik sınırı: '.self::MAX_PAGES.') — sync durduruldu.'
                );
            }

            $jobs = collect(range(0, $firstPage->totalPages - 1))
                ->map(fn (int $page) => new FetchProviderPageJob($provider, $page, $syncRunStartedAt, $log->id))
                ->all();

            Bus::batch($jobs)
                ->name("product-sync:{$provider->value}:{$log->id}")
                ->onQueue('product-sync')
                ->then(function (Batch $batch) use ($provider, $log, $syncRunStartedAt, $owner) {
                    app(self::class)->finishSuccessfully($provider, $log->id, $syncRunStartedAt, $owner);
                })
                ->catch(function (Batch $batch, Throwable $e) use ($provider, $log, $owner) {
                    app(self::class)->finishWithFailure($provider, $log->id, $owner, $e);
                })
                ->dispatch();
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            $lock->release();

            throw $e;
        }
    }

    /**
     * Bir sayfa job'unun kendi upsert sonucunu (added/updated) bu run için
     * paylaşımlı sayaçlara ekler. `FetchProviderPageJob::handle()` tarafından
     * çağrılır.
     */
    public function recordPageResult(int $syncLogId, int $added, int $updated): void
    {
        if ($added > 0) {
            Cache::increment(self::COUNTER_ADDED_PREFIX.$syncLogId, $added);
        }

        if ($updated > 0) {
            Cache::increment(self::COUNTER_UPDATED_PREFIX.$syncLogId, $updated);
        }
    }

    /**
     * Batch'teki TÜM sayfa job'ları başarıyla bitince (`Bus::batch()->then()`)
     * çağrılır: sweep-delete'i çalıştırır, sayaçları toplayıp `SyncLog`'u
     * "completed" olarak kapatır, kilidi serbest bırakır.
     */
    public function finishSuccessfully(ProviderType $provider, int $syncLogId, CarbonInterface $syncRunStartedAt, string $lockOwner): void
    {
        $deleted = Product::where('provider_type', $provider)
            ->whereNull('deleted_at')
            ->where('last_synced_at', '<', $syncRunStartedAt)
            ->get()
            ->each(fn (Product $product) => $product->delete())
            ->count();

        $result = new SyncResult(
            added: (int) Cache::get(self::COUNTER_ADDED_PREFIX.$syncLogId, 0),
            updated: (int) Cache::get(self::COUNTER_UPDATED_PREFIX.$syncLogId, 0),
            deleted: $deleted,
        );

        $this->forgetCounters($syncLogId);

        SyncLog::whereKey($syncLogId)->update([
            'status' => 'completed',
            'completed_at' => now(),
            'products_added' => $result->added,
            'products_updated' => $result->updated,
            'products_deleted' => $result->deleted,
        ]);

        $this->alerts->recordSyncSuccess($provider);
        $this->alerts->checkQueueBacklog();

        $this->releaseLock($provider, $lockOwner);
    }

    /**
     * Batch'teki herhangi bir sayfa job'u kalıcı olarak başarısız olup
     * batch iptal edilince (`Bus::batch()->catch()`) çağrılır. Sweep-delete
     * BİLEREK çalıştırılmaz — elimizde uzak listenin sadece bir kısmı var,
     * eksik sayfalardaki ürünleri yanlışlıkla "silinmiş" sayardık.
     */
    public function finishWithFailure(ProviderType $provider, int $syncLogId, string $lockOwner, Throwable $e): void
    {
        $this->forgetCounters($syncLogId);

        SyncLog::whereKey($syncLogId)->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $e->getMessage(),
        ]);

        if ($e instanceof CircuitBreakerOpenException) {
            $this->alerts->recordCircuitBreakerTripped($provider, $e->consecutiveFailures);
        }

        $this->alerts->recordSyncFailure($provider);
        $this->alerts->checkQueueBacklog();

        $this->releaseLock($provider, $lockOwner);
    }

    private function forgetCounters(int $syncLogId): void
    {
        Cache::forget(self::COUNTER_ADDED_PREFIX.$syncLogId);
        Cache::forget(self::COUNTER_UPDATED_PREFIX.$syncLogId);
    }

    private function releaseLock(ProviderType $provider, string $owner): void
    {
        Cache::restoreLock(self::LOCK_PREFIX.$provider->value, $owner)->release();
    }
}
