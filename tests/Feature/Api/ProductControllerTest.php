<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/products` — yerel, salt-okunur ürün listesi endpoint'i.
 */
class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ürünler, case'in zorunlu tuttuğu `meta:{page,per_page,total}` ile
     * birlikte sayfalanmalı.
     *
     * @covers \App\Http\Controllers\Api\ProductController::index
     */
    #[Test]
    public function indexReturnsProductsWithPaginationMeta(): void
    {
        Product::factory()->count(25)->create(['provider_type' => 'dummyjson']);

        $response = $this->getJson('/api/products?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [['id', 'provider', 'external_id', 'name', 'price', 'stock', 'description', 'last_synced_at']],
                'meta' => ['page', 'per_page', 'total'],
            ]);

        $this->assertSame(25, $response->json('meta.total'));
        $this->assertCount(10, $response->json('data'));
    }

    /**
     * `?provider=` query parametresi listeyi tek bir provider'a filtrelemeli.
     *
     * @covers \App\Http\Controllers\Api\ProductController::index
     */
    #[Test]
    public function indexFiltersByProviderQueryParameter(): void
    {
        Product::factory()->count(3)->create(['provider_type' => 'dummyjson']);
        Product::factory()->count(2)->create(['provider_type' => 'fakestore']);

        $response = $this->getJson('/api/products?provider=fakestore');

        $this->assertSame(2, $response->json('meta.total'));
    }

    /**
     * Soft-delete edilmiş ürünler listede GÖRÜNMEMELİ — sadece aktif
     * ürünler dönmeli.
     *
     * @covers \App\Http\Controllers\Api\ProductController::index
     */
    #[Test]
    public function softDeletedProductsAreExcludedFromTheList(): void
    {
        Product::factory()->create(['provider_type' => 'dummyjson']);
        $deleted = Product::factory()->create(['provider_type' => 'dummyjson']);
        $deleted->delete();

        $response = $this->getJson('/api/products');

        $this->assertSame(1, $response->json('meta.total'));
    }
}
