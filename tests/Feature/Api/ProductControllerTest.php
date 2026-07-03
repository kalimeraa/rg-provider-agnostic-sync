<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function index_pagination_meta_ile_urunleri_doner(): void
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

    #[Test]
    public function index_provider_filtresi_calisir(): void
    {
        Product::factory()->count(3)->create(['provider_type' => 'dummyjson']);
        Product::factory()->count(2)->create(['provider_type' => 'fakestore']);

        $response = $this->getJson('/api/products?provider=fakestore');

        $this->assertSame(2, $response->json('meta.total'));
    }

    #[Test]
    public function soft_delete_edilmis_urunler_listede_gorunmez(): void
    {
        Product::factory()->create(['provider_type' => 'dummyjson']);
        $deleted = Product::factory()->create(['provider_type' => 'dummyjson']);
        $deleted->delete();

        $response = $this->getJson('/api/products');

        $this->assertSame(1, $response->json('meta.total'));
    }
}
