<?php

namespace Tests\Unit\Services\Providers;

use App\Services\Providers\FakeStoreProvider;
use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FakeStoreProviderTest extends TestCase
{
    private function provider(): FakeStoreProvider
    {
        return new FakeStoreProvider(new ThrottledHttpClient('test-'.uniqid(), 1000, 5));
    }

    #[Test]
    public function sayfalama_olmadan_tum_urunleri_tek_sayfada_doner(): void
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

    #[Test]
    public function stock_alani_olmadigi_icin_sabit_0_olarak_normalize_edilir(): void
    {
        Http::fake(['*/products' => Http::response([
            ['id' => 1, 'title' => 'Backpack', 'price' => 109.95, 'description' => 'd'],
        ], 200)]);

        $item = $this->provider()->fetchPage(0)->items[0];

        $this->assertSame(0, $item['stock']);
        $this->assertSame('1', $item['external_id']);
        $this->assertSame('Backpack', $item['name']);
    }

    #[Test]
    public function ikinci_sayfa_istenirse_bos_doner(): void
    {
        Http::fake(['*/products' => Http::response([
            ['id' => 1, 'title' => 'X', 'price' => 1, 'description' => 'd'],
        ], 200)]);

        $page = $this->provider()->fetchPage(1);

        $this->assertSame([], $page->items);
        $this->assertSame(1, $page->totalPages);
    }

    #[Test]
    public function fetchOne_bos_body_donerse_null_doner(): void
    {
        Http::fake(['*/products/999' => Http::response(null, 200)]);

        $this->assertNull($this->provider()->fetchOne('999'));
    }

    #[Test]
    public function fetchOne_var_olan_urunu_normalize_eder(): void
    {
        Http::fake(['*/products/1' => Http::response([
            'id' => 1, 'title' => 'Backpack', 'price' => 109.95, 'description' => 'd',
        ], 200)]);

        $result = $this->provider()->fetchOne('1');

        $this->assertSame('1', $result['external_id']);
        $this->assertSame(0, $result['stock']);
    }
}
