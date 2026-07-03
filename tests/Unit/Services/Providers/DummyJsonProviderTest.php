<?php

namespace Tests\Unit\Services\Providers;

use App\Services\Providers\DummyJsonProvider;
use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DummyJsonProviderTest extends TestCase
{
    private function provider(): DummyJsonProvider
    {
        return new DummyJsonProvider(new ThrottledHttpClient('test-'.uniqid(), 1000, 5));
    }

    #[Test]
    public function ham_urunu_ortak_normalize_seklinede_cevirir(): void
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
                        // Volatile alanlar — normalize sonucuna DAHİL OLMAMALI:
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

    #[Test]
    public function totaldan_dogru_sayfa_sayisini_hesaplar(): void
    {
        Http::fake(['*/products*' => Http::response(['products' => [], 'total' => 194], 200)]);

        // 194 ürün, PAGE_SIZE=100 => ceil(194/100) = 2 sayfa.
        $this->assertSame(2, $this->provider()->fetchPage(0)->totalPages);
    }

    #[Test]
    public function total_sifirsa_en_az_1_sayfa_doner(): void
    {
        Http::fake(['*/products*' => Http::response(['products' => [], 'total' => 0], 200)]);

        $this->assertSame(1, $this->provider()->fetchPage(0)->totalPages);
    }

    #[Test]
    public function fetchOne_olmayan_urun_icin_null_doner(): void
    {
        Http::fake(['*/products/999' => Http::response(['message' => "Product with id '999' not found"], 404)]);

        $this->assertNull($this->provider()->fetchOne('999'));
    }

    #[Test]
    public function fetchOne_var_olan_urunu_normalize_eder(): void
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
