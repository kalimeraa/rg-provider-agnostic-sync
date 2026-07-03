<?php

namespace Tests\Unit\Services\Providers;

use App\Services\Providers\FakeStoreProvider;
use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FakeStore API (sayfalama yapmayan, tüm ürünleri tek seferde dönen)
 * provider implementasyonu.
 */
class FakeStoreProviderTest extends TestCase
{
    private function provider(): FakeStoreProvider
    {
        return new FakeStoreProvider(new ThrottledHttpClient('test-'.uniqid(), 1000, 5));
    }

    /**
     * FakeStore sayfalama yapmadığı için TÜM ürünler tek "sayfa"da (page 0,
     * totalPages=1) dönmeli.
     *
     * @covers \App\Services\Providers\FakeStoreProvider::fetchPage
     */
    #[Test]
    public function returnsAllProductsInASinglePageWithoutPagination(): void
    {
        Http::fake([
            '*/products' => Http::response([
                ['id' => 1, 'title' => 'Backpack', 'price' => 109.95, 'description' => 'd', 'category' => 'x', 'rating' => ['rate' => 3.9, 'count' => 120]],
                ['id' => 2, 'title' => 'Shirt', 'price' => 22.3, 'description' => 'd2'],
            ], 200),
        ]);

        $page = $this->provider()->fetchPage(0);

        $this->assertSame(1, $page->totalPages);
        $this->assertCount(2, $page->items);
    }

    /**
     * FakeStore API'sinde `stock` alanı yok (envanter kavramı yok) — sabit
     * `0` olarak normalize edilmeli.
     *
     * @covers \App\Services\Providers\FakeStoreProvider::fetchPage
     */
    #[Test]
    public function normalizesMissingStockFieldToZero(): void
    {
        Http::fake(['*/products' => Http::response([
            ['id' => 1, 'title' => 'Backpack', 'price' => 109.95, 'description' => 'd'],
        ], 200)]);

        $item = $this->provider()->fetchPage(0)->items[0];

        $this->assertSame(0, $item['stock']);
        $this->assertSame('1', $item['external_id']);
        $this->assertSame('Backpack', $item['name']);
    }

    /**
     * `SyncRunCoordinator` bu provider için `totalPages=1` gördüğü için
     * asla `page>0` ile çağırmaz, ama savunma amaçlı: 2. sayfa istenirse
     * boş bir sonuç dönmeli (tüm veriyi TEKRAR döndürüp duplicate
     * upsert'e yol açmamalı).
     *
     * @covers \App\Services\Providers\FakeStoreProvider::fetchPage
     */
    #[Test]
    public function requestingASecondPageReturnsEmptyItems(): void
    {
        Http::fake(['*/products' => Http::response([
            ['id' => 1, 'title' => 'X', 'price' => 1, 'description' => 'd'],
        ], 200)]);

        $page = $this->provider()->fetchPage(1);

        $this->assertSame([], $page->items);
        $this->assertSame(1, $page->totalPages);
    }

    /**
     * FakeStore, olmayan bir id için 404 değil boş body ile 200 dönüyor
     * (curl ile doğrulandı) — `fetchOne()` bunu null olarak yorumlamalı.
     *
     * @covers \App\Services\Providers\FakeStoreProvider::fetchOne
     */
    #[Test]
    public function fetchOneReturnsNullForEmptyBodyResponse(): void
    {
        Http::fake(['*/products/999' => Http::response(null, 200)]);

        $this->assertNull($this->provider()->fetchOne('999'));
    }

    /**
     * Var olan bir ürün için `fetchOne()` doğru şekilde normalize edilmiş
     * veriyi dönmeli (stock yine sabit 0).
     *
     * @covers \App\Services\Providers\FakeStoreProvider::fetchOne
     */
    #[Test]
    public function fetchOneNormalizesAnExistingProduct(): void
    {
        Http::fake(['*/products/1' => Http::response([
            'id' => 1, 'title' => 'Backpack', 'price' => 109.95, 'description' => 'd',
        ], 200)]);

        $result = $this->provider()->fetchOne('1');

        $this->assertSame('1', $result['external_id']);
        $this->assertSame(0, $result['stock']);
    }
}
