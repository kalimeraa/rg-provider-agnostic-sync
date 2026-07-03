<?php

namespace Tests\Unit\Services\Providers;

use App\Services\Providers\DummyJsonProvider;
use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DummyJSON (`limit`/`skip`/`total` sayfalamalı) provider implementasyonu.
 */
class DummyJsonProviderTest extends TestCase
{
    private function provider(): DummyJsonProvider
    {
        return new DummyJsonProvider(new ThrottledHttpClient('test-'.uniqid(), 1000, 5));
    }

    /**
     * Ham DummyJSON ürün şekli ortak normalize şekline çevrilmeli; volatile
     * alanlar (rating, category, images) sonuca DAHİL EDİLMEMELİ.
     *
     * @covers \App\Services\Providers\DummyJsonProvider::fetchPage
     */
    #[Test]
    public function normalizesRawItemIntoTheSharedProductShape(): void
    {
        Http::fake([
            '*/products*' => Http::response([
                'products' => [
                    [
                        'id' => 1,
                        'title' => 'Essence Mascara',
                        'price' => 9.99,
                        'stock' => 99,
                        'description' => 'Açıklama',
                        'rating' => 4.5,
                        'category' => 'beauty',
                        'images' => ['a.png'],
                    ],
                ],
                'total' => 1,
            ], 200),
        ]);

        $page = $this->provider()->fetchPage(0);

        $this->assertSame(1, $page->totalPages);
        $this->assertSame([
            'external_id' => '1',
            'name' => 'Essence Mascara',
            'price' => 9.99,
            'stock' => 99,
            'description' => 'Açıklama',
        ], $page->items[0]);
    }

    /**
     * `total`'dan sayfa boyutuna (100) göre doğru sayfa sayısı hesaplanmalı.
     *
     * @covers \App\Services\Providers\DummyJsonProvider::fetchPage
     */
    #[Test]
    public function calculatesTotalPagesFromReportedTotal(): void
    {
        Http::fake(['*/products*' => Http::response(['products' => [], 'total' => 194], 200)]);

        // 194 ürün, PAGE_SIZE=100 => ceil(194/100) = 2 sayfa.
        $this->assertSame(2, $this->provider()->fetchPage(0)->totalPages);
    }

    /**
     * `total=0` olsa bile en az 1 sayfa dönmeli (sıfır sayfalı bir batch
     * anlamsız olurdu).
     *
     * @covers \App\Services\Providers\DummyJsonProvider::fetchPage
     */
    #[Test]
    public function zeroTotalStillReportsAtLeastOnePage(): void
    {
        Http::fake(['*/products*' => Http::response(['products' => [], 'total' => 0], 200)]);

        $this->assertSame(1, $this->provider()->fetchPage(0)->totalPages);
    }

    /**
     * Var olmayan bir ürün id'si için `fetchOne()` null dönmeli (gerçek
     * DummyJSON 404 döndürüyor, `ThrottledHttpClient` bunu boş array'e
     * çeviriyor).
     *
     * @covers \App\Services\Providers\DummyJsonProvider::fetchOne
     */
    #[Test]
    public function fetchOneReturnsNullForNonExistentProduct(): void
    {
        Http::fake(['*/products/999' => Http::response(['message' => "Product with id '999' not found"], 404)]);

        $this->assertNull($this->provider()->fetchOne('999'));
    }

    /**
     * Var olan bir ürün için `fetchOne()` doğru şekilde normalize edilmiş
     * veriyi dönmeli.
     *
     * @covers \App\Services\Providers\DummyJsonProvider::fetchOne
     */
    #[Test]
    public function fetchOneNormalizesAnExistingProduct(): void
    {
        Http::fake(['*/products/1' => Http::response([
            'id' => 1, 'title' => 'X', 'price' => 5, 'stock' => 2, 'description' => 'd',
        ], 200)]);

        $result = $this->provider()->fetchOne('1');

        $this->assertSame('1', $result['external_id']);
        $this->assertSame('X', $result['name']);
        $this->assertSame(2, $result['stock']);
    }
}
