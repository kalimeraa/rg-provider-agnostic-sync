<?php

namespace Tests\Unit\Services\Sync;

use App\DTOs\ProviderPage;
use App\Enums\ProviderType;
use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Exceptions\Sync\PaginationLimitExceededException;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Providers\ProviderFactory;
use App\Services\Sync\SyncRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Bir provider'ın sync run'ını orkestre eden çekirdek sınıf — bkz. o class'ın
 * PHPDoc'u (job uniqueness kilidi, mark-and-sweep, sayaç toplama). Batch'in
 * gerçek sayfa job'larını Bus::fake() ile izole ederek `start()`'ın
 * dispatch mantığını, `finishSuccessfully()`/`finishWithFailure()`'ı ise
 * doğrudan çağırarak (Bus::batch()->then()/catch() callback'lerinin
 * çağıracağı şekliyle) unit seviyesinde test eder.
 *
 * @covers \App\Services\Sync\SyncRunCoordinator
 */
class SyncRunCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function coordinator(): SyncRunCoordinator
    {
        return app(SyncRunCoordinator::class);
    }

    /**
     * Aynı provider için kilit zaten tutuluyorsa `start()` sessizce dönmeli
     * (job uniqueness) — hiçbir `sync_logs` satırı oluşturmamalı, batch
     * dispatch edilmemeli.
     */
    #[Test]
    public function startReturnsSilentlyWhenProviderLockIsAlreadyHeld(): void
    {
        Bus::fake();

        $lock = Cache::lock('product-sync-lock:dummyjson', 900);
        $lock->get();

        try {
            $this->coordinator()->start(ProviderType::DummyJson);

            $this->assertDatabaseCount('sync_logs', 0);
            Bus::assertNothingBatched();
        } finally {
            $lock->release();
        }
    }

    /**
     * Provider'ın raporladığı `totalPages`, `SyncRunCoordinator::MAX_PAGES`
     * (50) güvenlik sınırını aşarsa sync hemen `failed` olarak kapanmalı ve
     * hiçbir sayfa job'u kuyruklanmamalı.
     */
    #[Test]
    public function stopsImmediatelyWhenProviderReportsMoreThanFiftyPages(): void
    {
        Bus::fake();

        $providerFactory = $this->createMock(ProviderFactory::class);
        $providerClient = $this->createMock(\App\Contracts\ProviderClientInterface::class);
        $providerClient->method('fetchPage')->willReturn(new ProviderPage(items: [], totalPages: 51));
        $providerFactory->method('make')->willReturn($providerClient);

        $this->app->instance(ProviderFactory::class, $providerFactory);

        try {
            $this->coordinator()->start(ProviderType::DummyJson);
            $this->fail('PaginationLimitExceededException bekleniyordu.');
        } catch (PaginationLimitExceededException) {
            // beklenen
        }

        $log = SyncLog::where('provider_type', 'dummyjson')->latest('id')->first();
        $this->assertSame('failed', $log->status);
        Bus::assertNothingBatched();
    }

    /**
     * İlk sayfa (page 0) çekilirken (yani `totalPages` öğrenilmeden) bir
     * hata oluşursa: `sync_logs` direkt `failed` olarak kapanmalı, kilit
     * serbest bırakılmalı (yeni bir sync hemen denenebilmeli) ve exception
     * çağırana yeniden fırlatılmalı.
     */
    #[Test]
    public function releasesLockAndRethrowsWhenFirstPageFetchFails(): void
    {
        Bus::fake();

        $providerFactory = $this->createMock(ProviderFactory::class);
        $providerClient = $this->createMock(\App\Contracts\ProviderClientInterface::class);
        $providerClient->method('fetchPage')->willThrowException(new RuntimeException('provider ayakta değil'));
        $providerFactory->method('make')->willReturn($providerClient);

        $this->app->instance(ProviderFactory::class, $providerFactory);

        try {
            $this->coordinator()->start(ProviderType::DummyJson);
            $this->fail('RuntimeException bekleniyordu.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider ayakta değil', $e->getMessage());
        }

        $log = SyncLog::where('provider_type', 'dummyjson')->latest('id')->first();
        $this->assertSame('failed', $log->status);

        // Kilit serbest kaldıysa ikinci bir start() hemen (sessizce reddedilmeden) çalışabilmeli.
        $providerFactory2 = $this->createMock(ProviderFactory::class);
        $providerClient2 = $this->createMock(\App\Contracts\ProviderClientInterface::class);
        $providerClient2->method('fetchPage')->willReturn(new ProviderPage(items: [], totalPages: 1));
        $providerFactory2->method('make')->willReturn($providerClient2);
        $this->app->instance(ProviderFactory::class, $providerFactory2);

        $this->coordinator()->start(ProviderType::DummyJson);

        $this->assertDatabaseCount('sync_logs', 2);
    }

    /**
     * Batch tamamen bitince (`finishSuccessfully`): sweep-delete bu run'da
     * görülmemiş ürünleri soft-delete etmeli, sayaçlardan toplanan
     * added/updated `sync_logs`'a yazılmalı, kilit serbest bırakılmalı.
     */
    #[Test]
    public function finishSuccessfullySweepsUntouchedProductsAndClosesLogAsCompleted(): void
    {
        $log = SyncLog::create(['provider_type' => 'dummyjson', 'started_at' => now(), 'status' => 'running']);
        $untouched = Product::factory()->create(['provider_type' => 'dummyjson', 'last_synced_log_id' => $log->id - 1]);

        $lock = Cache::lock('product-sync-lock:dummyjson', 900);
        $lock->get();

        Cache::increment('sync-run-added:'.$log->id, 3);
        Cache::increment('sync-run-updated:'.$log->id, 1);

        $this->coordinator()->finishSuccessfully(ProviderType::DummyJson, $log->id, now(), $lock->owner());

        $log->refresh();
        $this->assertSame('completed', $log->status);
        $this->assertSame(3, $log->products_added);
        $this->assertSame(1, $log->products_updated);
        $this->assertSame(1, $log->products_deleted);
        $this->assertSoftDeleted($untouched);

        // Kilit serbest kaldı mı? Yeniden alınabiliyor olmalı.
        $this->assertTrue(Cache::lock('product-sync-lock:dummyjson', 1)->get());
    }

    /**
     * Batch iptal edilince (`finishWithFailure`, circuit breaker DIŞINDA bir
     * sebeple): `sync_logs` `failed` olarak kapanmalı, sweep ÇALIŞTIRILMAMALI
     * (elimizde uzak listenin sadece bir kısmı var).
     */
    #[Test]
    public function finishWithFailureClosesLogAsFailedWithoutRunningSweep(): void
    {
        $log = SyncLog::create(['provider_type' => 'dummyjson', 'started_at' => now(), 'status' => 'running']);
        $untouched = Product::factory()->create(['provider_type' => 'dummyjson', 'last_synced_log_id' => null]);

        $lock = Cache::lock('product-sync-lock:dummyjson', 900);
        $lock->get();

        $this->coordinator()->finishWithFailure(ProviderType::DummyJson, $log->id, $lock->owner(), new RuntimeException('sayfa kalıcı başarısız oldu'));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertSame('sayfa kalıcı başarısız oldu', $log->error_message);
        $this->assertNull($untouched->fresh()->deleted_at, 'Sweep, kısmi/eksik veriyle YANLIŞLIKLA çalıştırılmamalı.');
    }

    /**
     * `finishWithFailure`'a geçilen exception bir `CircuitBreakerOpenException`
     * ise `AlertService::recordCircuitBreakerTripped()` de çağrılmalı (bkz.
     * class'ın PHPDoc'u — sadece `instanceof` kontrolüyle koşullu dal).
     */
    #[Test]
    public function finishWithFailureAlsoReportsCircuitBreakerWhenExceptionIsCircuitBreakerOpen(): void
    {
        // Gerçek AlertService kullanılır (mock DEĞİL) — yan etkisi olan gerçek
        // alerts.log dosyası doğrulanır. Aynı process içinde hem PHPUnit'in
        // native mock'ları hem Mockery'nin karışık kullanımı, bu test dosyasının
        // TAMAMI birlikte çalışırken nadir bir segfault'a yol açıyordu; tek bir
        // mocking çerçevesine (PHPUnit native) sadık kalmak bunu ortadan kaldırdı.
        $logPath = storage_path('logs/alerts.log');
        @unlink($logPath);
        config(['sync.alerts.consecutive_api_failures' => 5]);

        $log = SyncLog::create(['provider_type' => 'dummyjson', 'started_at' => now(), 'status' => 'running']);

        $lock = Cache::lock('product-sync-lock:dummyjson', 900);
        $lock->get();

        app(SyncRunCoordinator::class)->finishWithFailure(
            ProviderType::DummyJson,
            $log->id,
            $lock->owner(),
            new CircuitBreakerOpenException(5, 'ardışık 5 istek başarısız'),
        );

        $this->assertFileExists($logPath);
        $this->assertStringContainsString('CONSECUTIVE_API_FAILURES', file_get_contents($logPath));

        @unlink($logPath);
    }

    /**
     * `recordPageResult()`, sıfır olan bir sayacı (added=0 veya updated=0)
     * Redis'e hiç yazmamalı — gereksiz `INCR` çağrısından kaçınmak için.
     */
    #[Test]
    public function recordPageResultOnlyIncrementsCountersThatAreGreaterThanZero(): void
    {
        $this->coordinator()->recordPageResult(999, added: 0, updated: 0);

        $this->assertNull(Cache::get('sync-run-added:999'));
        $this->assertNull(Cache::get('sync-run-updated:999'));

        $this->coordinator()->recordPageResult(999, added: 2, updated: 0);

        $this->assertSame(2, Cache::get('sync-run-added:999'));
        $this->assertNull(Cache::get('sync-run-updated:999'));

        $this->coordinator()->recordPageResult(999, added: 0, updated: 4);

        $this->assertSame(4, Cache::get('sync-run-updated:999'));
    }
}
