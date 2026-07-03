<?php

namespace Tests\Feature;

use App\Enums\ProviderType;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Sync\SyncRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bonus: Integration/E2E test. Case'in "Idempotency (aynı job 2 kez
 * çalışırsa)" ve "yeni eklenen ve silinen ürünleri tespit etme"
 * gereksinimlerini, gerçek `SyncRunCoordinator` + `Bus::batch()` +
 * `FetchProviderPageJob` akışının TAMAMI üzerinden (mock edilmiş sadece
 * HTTP katmanı) uçtan uca doğrular — tek bir servis metodunu değil,
 * production'da gerçekten çalışan tüm zinciri.
 *
 * `$currentIds`, DummyJSON'ın "şu an provider'da hangi ürünler var"
 * durumunu simüle eder; `fakeDummyJson()` sadece BİR KEZ registre edilir,
 * kapatma (`Http::fake` closure'ı) her çağrıldığında `$this->currentIds`'i
 * GÜNCEL haliyle okur — bu yüzden testler arasında sadece `$currentIds`'i
 * değiştirmek yeterli, `Http::fake()`'i tekrar çağırmaya gerek yok.
 *
 * @covers \App\Services\Sync\SyncRunCoordinator::start
 * @covers \App\Services\Sync\SyncRunCoordinator::finishSuccessfully
 */
class SyncIdempotencyAndSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> */
    private array $currentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // CACHE_DRIVER=array test sürecinin TAMAMI boyunca paylaşılır (bu
        // dosyaya özel değil) — başka bir test dosyasının bıraktığı
        // pacing/circuit-breaker/kilit durumu bu testleri etkilemesin diye.
        Cache::flush();
    }

    private function fakeDummyJson(): void
    {
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'dummyjson.com')) {
                return Http::response([], 404);
            }

            $skip = (int) ($request['skip'] ?? 0);
            $limit = (int) ($request['limit'] ?? 100);

            $pageIds = array_slice($this->currentIds, $skip, $limit);

            $products = array_map(fn (int $id) => [
                'id' => $id,
                'title' => "Ürün {$id}",
                'price' => 10 + $id,
                'stock' => 5,
                'description' => 'd',
            ], $pageIds);

            return Http::response(['products' => $products, 'total' => count($this->currentIds)], 200);
        });
    }

    private function latestLog(): SyncLog
    {
        // latest('id') — latest('started_at') DEĞİL: bkz. SyncController::status()'taki
        // aynı düzeltmenin açıklaması (saniye hassasiyetli timestamp çakışması).
        return SyncLog::where('provider_type', ProviderType::DummyJson)->latest('id')->firstOrFail();
    }

    /**
     * 150 ürün (2 sayfa: 100 + 50) doğru şekilde eklenmeli; batch tam
     * olarak 2 `FetchProviderPageJob` içermeli.
     */
    #[Test]
    public function twoPageSyncAddsAllProductsCorrectly(): void
    {
        $this->currentIds = range(1, 150); // 150 ürün => 2 sayfa (100 + 50)
        $this->fakeDummyJson();

        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        $log = $this->latestLog();

        $this->assertSame('completed', $log->status);
        $this->assertSame(150, $log->products_added);
        $this->assertSame(150, Product::where('provider_type', ProviderType::DummyJson)->count());

        $batch = DB::table('job_batches')->latest('created_at')->first();
        $this->assertSame(2, $batch->total_jobs, 'Batch tam olarak 2 sayfa job\'u içermeli (150/100 => 2 sayfa).');
    }

    /**
     * Case gereksinimi: idempotency. Aynı sync run'ı iki kez çalıştırılırsa
     * ikincide `added=0`/`updated=0` olmalı, duplicate kayıt OLUŞMAMALI.
     */
    #[Test]
    public function runningTheSameSyncTwiceProducesNoDuplicatesAndZeroAddedOnSecondRun(): void
    {
        $this->currentIds = range(1, 150);
        $this->fakeDummyJson();

        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);
        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        $log = $this->latestLog();

        $this->assertSame(0, $log->products_added);
        $this->assertSame(0, $log->products_updated);
        $this->assertSame(
            150,
            Product::where('provider_type', ProviderType::DummyJson)->count(),
            'İkinci çalıştırma duplicate kayıt oluşturmamalı (idempotency).'
        );
    }

    /**
     * İçeriği değişen (hash'i bozulan) bir ürün, sıradaki sync'te doğru
     * hash'e geri güncellenmeli.
     */
    #[Test]
    public function productWithChangedContentIsUpdatedOnNextRun(): void
    {
        $this->currentIds = range(1, 10);
        $this->fakeDummyJson();
        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        $originalHash = Product::where('external_id', '5')->first()->data_hash;

        // Fiyatlar normalize fonksiyonunda "10 + id" — id sabit ama provider'ın
        // döndürdüğü price'ı değiştirecek şekilde closure'ı özelleştirmek yerine,
        // burada aynı id'yi FARKLI bir price ile simüle etmek için currentIds'i
        // aynı bırakıp price hesaplamasını değiştiremeyeceğimiz için doğrudan
        // DB'deki hash'i bozup "içerik değişti" senaryosunu tetikliyoruz.
        Product::where('external_id', '5')->update(['data_hash' => 'stale-hash']);

        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        $product = Product::where('external_id', '5')->first();
        $this->assertNotSame('stale-hash', $product->data_hash);
        $this->assertSame($originalHash, $product->data_hash);
    }

    /**
     * Case gereksinimi: "silinen ürünleri tespit etme". Provider'dan artık
     * dönmeyen bir ürün, mark-and-sweep ile soft-delete edilmeli; DİĞER
     * ürünler ETKİLENMEMELİ.
     */
    #[Test]
    public function productMissingFromProviderIsSoftDeletedBySweep(): void
    {
        $this->currentIds = range(1, 150);
        $this->fakeDummyJson();
        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        // Ürün 150 artık provider'da yok.
        $this->currentIds = range(1, 149);
        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        $log = $this->latestLog();
        $this->assertSame(1, $log->products_deleted);
        $this->assertSoftDeleted('products', ['provider_type' => 'dummyjson', 'external_id' => '150']);

        // Diğer 149 ürün etkilenmemeli.
        $this->assertSame(149, Product::where('provider_type', ProviderType::DummyJson)->whereNull('deleted_at')->count());
    }

    /**
     * Sweep ile silinen bir ürün, provider'da TEKRAR görününce restore
     * edilmeli (yeni bir kayıt olarak değil, `deleted_at` temizlenerek).
     */
    #[Test]
    public function productThatReappearsAfterSweepIsRestored(): void
    {
        $this->currentIds = range(1, 150);
        $this->fakeDummyJson();
        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        $this->currentIds = range(1, 149);
        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);
        $this->assertSoftDeleted('products', ['external_id' => '150']);

        // 150 tekrar ortaya çıkıyor.
        $this->currentIds = range(1, 150);
        app(SyncRunCoordinator::class)->start(ProviderType::DummyJson);

        $product = Product::where('external_id', '150')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->deleted_at);
    }
}
