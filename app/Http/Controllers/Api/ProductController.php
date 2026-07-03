<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Yerel `products` tablosunu dışa açan salt-okunur (read-only) endpoint.
 * Senkronizasyonun kendisi SyncController/SyncProviderJob üzerinden yürür.
 */
class ProductController extends Controller
{
    use ApiResponseTrait;

    /**
     * `GET /api/products` — aktif (soft-delete edilmemiş) ürünleri en son
     * senkronize edilene göre sayfalar. `?provider=dummyjson` ile tek bir
     * provider'a filtrelenebilir.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        $products = Product::query()
            ->when(
                $request->query('provider'),
                fn ($query, $provider) => $query->where('provider_type', $provider)
            )
            ->latest('last_synced_at')
            ->paginate($perPage);

        return $this->paginated(ProductResource::collection($products->items()), $products);
    }
}
