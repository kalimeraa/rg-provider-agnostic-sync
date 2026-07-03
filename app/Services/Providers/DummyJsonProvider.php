<?php

namespace App\Services\Providers;

use App\Contracts\ProviderClientInterface;
use App\DTOs\ProviderPage;
use App\Services\Sync\ThrottledHttpClient;

/**
 * DummyJSON (https://dummyjson.com/products) için ProviderClientInterface
 * implementasyonu. API `limit`/`skip`/`total` ile sayfalama yapıyor (şu an
 * ~194 ürün, `PAGE_SIZE`=100 ile 2 sayfa). Sayfalama artık bu class'ın
 * KENDİ İÇİNDE bir döngü değil — her sayfa `SyncRunCoordinator` tarafından
 * ayrı bir `FetchProviderPageJob` olarak kuyruklanır (paralel çalışabilsin,
 * tek bir job'un tüm sayfaları çekmeye çalışırken zaman aşımına uğrama
 * riski olmasın). `totalPages` bu class tarafından hesaplanır ki
 * coordinator provider'ın sayfa boyutunu hiç bilmek zorunda kalmasın.
 */
class DummyJsonProvider implements ProviderClientInterface
{
    /** Her sayfada çekilecek ürün sayısı. */
    private const PAGE_SIZE = 100;

    public function __construct(private readonly ThrottledHttpClient $http)
    {
    }

    /**
     * `GET /products?limit=&skip=` ile tek bir sayfayı çeker, normalize eder
     * ve provider'ın rapor ettiği `total`'dan toplam sayfa sayısını hesaplar.
     */
    public function fetchPage(int $page): ProviderPage
    {
        $baseUrl = config('sync.providers.dummyjson.base_url');

        $response = $this->http->get("{$baseUrl}/products", [
            'limit' => self::PAGE_SIZE,
            'skip' => $page * self::PAGE_SIZE,
        ]);

        $items = collect($response['products'] ?? [])
            ->map(fn (array $item) => $this->normalize($item))
            ->values()
            ->all();

        $total = (int) ($response['total'] ?? 0);
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return new ProviderPage(items: $items, totalPages: $totalPages);
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
