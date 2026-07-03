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
 *
 * @covers \App\Services\Sync\DeltaSyncService::upsertPage
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

    /**
     * Daha önce hiç görülmemiş bir `external_id` eklenmeli, `added=1` dönmeli.
     */
    #[Test]
    public function addsANewProduct(): void
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

    /**
     * Hash aynıysa ürün GÜNCELLENMEMELİ (added=0, updated=0) ama
     * `last_synced_log_id` yine de bu run'a tazelenmeli (mark-and-sweep'in
     * ürünü "hâlâ görüldü" sayabilmesi için).
     */
    #[Test]
    public function unchangedHashSkipsUpdateButRefreshesLastSyncedMarker(): void
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

    /**
     * İçerik (fiyat, isim, stok, açıklama) değişince ürün güncellenmeli
     * (updated=1), yeni değerler DB'ye yazılmalı.
     */
    #[Test]
    public function updatesProductWhenContentChanges(): void
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

    /**
     * Case gereksinimi: idempotency. Aynı ürün iki kez upsert edilse bile
     * DUPLICATE KAYIT oluşmamalı — unique constraint + upsert garantisi.
     */
    #[Test]
    public function runningTwiceDoesNotCreateDuplicateRecordsIdempotency(): void
    {
        $item = ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 1);
        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 2);

        $this->assertSame(1, Product::where('provider_type', ProviderType::DummyJson)->where('external_id', '1')->count());
    }

    /**
     * Daha önce soft-delete edilmiş bir ürün, provider'da tekrar
     * görününce RESTORE edilmeli (yeni bir kayıt olarak değil).
     */
    #[Test]
    public function reappearingProductIsRestoredFromSoftDelete(): void
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

    /**
     * İki farklı provider, AYNI `external_id`'ye sahip ürünleri ayrı ayrı
     * kayıt olarak tutabilmeli — unique constraint `(provider_type,
     * external_id)` bileşik olduğu için çakışma olmamalı.
     */
    #[Test]
    public function differentProvidersCanShareTheSameExternalId(): void
    {
        $item = ['external_id' => '1', 'name' => 'Kalem', 'price' => 9.99, 'stock' => 10, 'description' => 'Mavi kalem'];

        $this->service->upsertPage(ProviderType::DummyJson, [$item], now(), 1);
        $this->service->upsertPage(ProviderType::FakeStore, [$item], now(), 1);

        $this->assertSame(2, Product::where('external_id', '1')->count());
    }
}
