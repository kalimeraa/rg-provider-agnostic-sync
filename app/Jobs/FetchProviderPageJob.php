<?php

namespace App\Jobs;

use App\Enums\ProviderType;
use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Services\Providers\ProviderFactory;
use App\Services\Sync\DeltaSyncService;
use App\Services\Sync\SyncRunCoordinator;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Bir provider'ın TEK bir sayfasını çeker ve DB'ye upsert eder.
 * `SyncRunCoordinator::start()` tarafından, bir provider'ın tüm sayfaları
 * için `Bus::batch()` içinde birlikte dispatch edilir — böylece sayfalar
 * paralel çalışabilir ve tek bir job'un TÜM sayfaları çekmeye çalışırken
 * zaman aşımına uğrama riski ortadan kalkar.
 *
 * `Batchable`: `$this->batch()` ile bu job'un ait olduğu batch'e erişip
 * iptal edilip edilmediğini kontrol edebilir — batch'teki başka bir sayfa
 * zaten kalıcı olarak başarısız olup batch'i iptal ettiyse, bu job kendi
 * işini boşuna yapmaz.
 */
class FetchProviderPageJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maksimum deneme sayısı (case gereksinimi: 3 deneme). */
    public int $tries = 3;

    /** Denemeler arası bekleme süreleri (saniye) — exponential backoff: 1s, 2s, 4s. */
    /** @var array<int, int> */
    public array $backoff = [1, 2, 4];

    public function __construct(
        public readonly ProviderType $provider,
        public readonly int $page,
        public readonly CarbonInterface $syncRunStartedAt,
        public readonly int $syncLogId,
    ) {
        $this->onQueue('product-sync');
    }

    /**
     * Batch iptal edildiyse (bu run'daki başka bir sayfa kalıcı olarak
     * başarısız olduysa) hiçbir şey yapmadan çıkar — kısmi/eksik veriyle
     * upsert yapıp yanlış sinyaller üretmemek için.
     *
     * `CircuitBreakerOpenException` özel olarak yakalanıp retry edilmeden
     * `fail()` ile job'u kalıcı olarak başarısız işaretler: circuit breaker
     * zaten "bu provider için art arda çok fazla istek başarısız oldu"
     * demek, aynı job'u tekrar denemek anlamsız — bu, batch'in hemen iptal
     * edilip diğer bekleyen sayfa job'larının da çalışmadan atlanmasını sağlar.
     */
    public function handle(ProviderFactory $providerFactory, DeltaSyncService $deltaSyncService, SyncRunCoordinator $coordinator): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $page = $providerFactory->make($this->provider)->fetchPage($this->page);
            $result = $deltaSyncService->upsertPage($this->provider, $page->items, $this->syncRunStartedAt, $this->syncLogId);

            $coordinator->recordPageResult($this->syncLogId, $result['added'], $result['updated']);
        } catch (CircuitBreakerOpenException $e) {
            $this->fail($e);
        }
    }
}
