<?php

namespace App\Services\Providers;

use App\Contracts\ProviderClientInterface;
use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Support\Collection;

/**
 * DummyJSON (https://dummyjson.com/products) için ProviderClientInterface
 * implementasyonu. API `limit`/`skip`/`total` ile sayfalama yapıyor
 * (şu an ~194 ürün); `fetchAll()` `total`'a ulaşana kadar sayfa sayfa çeker.
 */
class DummyJsonProvider implements ProviderClientInterface
{
    /** Her sayfada çekilecek ürün sayısı. */
    private const PAGE_SIZE = 100;

    public function __construct(private readonly ThrottledHttpClient $http)
    {
    }

    /**
     * `GET /products?limit=&skip=` ile tüm sayfaları dolaşıp normalize
     * edilmiş ürünleri tek bir Collection'da toplar.
     */
    public function fetchAll(): Collection
    {
        $baseUrl = config('sync.providers.dummyjson.base_url');
        $products = collect();
        $skip = 0;
        $total = 0;

        do {
            $response = $this->http->get("{$baseUrl}/products", [
                'limit' => self::PAGE_SIZE,
                'skip' => $skip,
            ]);

            $batch = collect($response['products'] ?? []);
            $products = $products->merge($batch->map(fn (array $item) => $this->normalize($item)));

            $total = (int) ($response['total'] ?? 0);
            $skip += self::PAGE_SIZE;
        } while ($skip < $total);

        return $products->values();
    }

    /**
     * `GET /products/{id}` ile tek bir ürünü çeker. DummyJSON, olmayan bir
     * id için gerçekten 404 dönüyor (curl ile doğrulandı:
     * `{"message":"Product with id '...' not found"}`); ThrottledHttpClient
     * bu 404'ü zaten boş array'e normalize ettiği için burada sadece
     * `empty($response)` kontrolü yeterli.
     */
    public function fetchOne(string $externalId): ?array
    {
        $baseUrl = config('sync.providers.dummyjson.base_url');

        $response = $this->http->get("{$baseUrl}/products/{$externalId}");

        if (empty($response)) {
            return null;
        }

        return $this->normalize($response);
    }

    /**
     * DummyJSON'ın ham ürün şeklini ortak normalize şekline çevirir
     * (`title`→`name` vb.); `rating`, `images`, `category` gibi volatile/
     * ilgisiz alanlar bilinçli olarak atlanır.
     *
     * @param  array<string, mixed>  $item
     * @return array{external_id: string, name: string, price: float, stock: int, description: string}
     */
    private function normalize(array $item): array
    {
        return [
            'external_id' => (string) $item['id'],
            'name' => $item['title'],
            'price' => (float) $item['price'],
            'stock' => (int) ($item['stock'] ?? 0),
            'description' => $item['description'] ?? '',
        ];
    }
}
