<?php

namespace Tests\Unit\Services\Sync;

use App\Enums\ProviderType;
use App\Models\Product;
use App\Services\Sync\DeltaSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case gereksinimi: "Delta sync (yeni, güncellenen, değişmeyen ürünler)"
 * mutlaka test edilmeli. `upsertPage()` artık silme mantığı İÇERMEZ (bkz.
 * CLAUDE.md/CHANGELOG — silme `SyncRunCoordinator`'ın sweep adımında), bu
 * yüzden burada sadece ekleme/güncelleme/değişmeme senaryoları test edilir.
 * `$syncLogId` parametresi, testlerde farklı "run"ları simüle etmek için
 * her çağrıda ayrı bir tam sayı olarak geçilir (gerçek bir `SyncLog`
 * satırına karşılık gelmesi bu testler için gerekmiyor).
 */
class DeltaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeltaSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DeltaSyncService::class);
    }

    #[Test]
    public function yeni_urunu_ekler(): void
    {
        $result = $this->service->upsertPage(ProviderType::DummyJson, [
            ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'],
        ], now(), 1);

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseHas('products', [
            'provider_type' => 'dummyjson',
            'external_id' => '1',
            'name' => 'Kalem',
            'last_synced_log_id' => 1,
        ]);
    }

    #[Test]
    public function hash_ayniysa_urunu_guncellemez_ama_last_synced_bilgisini_tazeler(): void
    {
        $item = ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];
        $runOne = now()->subMinutes(10);

        $this->service->upsertPage(ProviderType::DummyJson, [$item], $runOne, 1);
        $originalHash = Product::where('external_id', '1')->first()->data_hash;

        $runTwo = now();
        $result = $this->service->upsertPage(ProviderType::DummyJson, [$item], $runTwo, 2);

        $product = Product::where('external_id', '1')->first();

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame($originalHash, $product->data_hash);
        $this->assertSame(2, $product->last_synced_log_id);
    }

    #[Test]
    public function icerik_degisince_urunu_gunceller(): void
    {
        $this->service->upsertPage(ProviderType::DummyJson, [
            ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'],
        ], now(), 1);

        $result = $this->service->upsertPage(ProviderType::DummyJson, [
            ['external_id' => '1', 'name' => 'Kalem', 'price' => 12.50, 'stock' => 10, 'description' => 'Mavi kalem'],
        ], now(), 2);

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseHas('products', [
            'external_id' => '1',
            'price' => 12.50,
            'last_synced_log_id' => 2,
        ]);
    }

    #[Test]
    public function iki_kez_calistirilsa_bile_duplicate_kayit_olusturmaz_idempotency(): void
    {
        $item = ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 1);
        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 2);

        $this->assertSame(1, Product::where('provider_type', ProviderType::DummyJson)->where('external_id', '1')->count());
    }

    #[Test]
    public function soft_delete_edilmis_urun_tekrar_gorununce_geri_getirilir(): void
    {
        $item = ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 1);
        Product::where('external_id', '1')->first()->delete();

        $this->assertSoftDeleted('products', ['external_id' => '1']);

        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 2);

        $product = Product::where('external_id', '1')->first();
        $this->assertNotNull($product);
        $this->assertNull($product->deleted_at);
    }

    #[Test]
    public function farkli_providerlar_ayni_external_id_ile_ayri_kayit_olusturabilir(): void
    {
        $item = ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 1);
        $this->service->upsertPage(ProviderType::FakeStore, [$item], now(), 1);

        $this->assertSame(2, Product::where('external_id', '1')->count());
    }
}
