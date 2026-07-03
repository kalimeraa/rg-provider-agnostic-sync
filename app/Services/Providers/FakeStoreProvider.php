<?php

namespace App\Services\Providers;

use App\Contracts\ProviderClientInterface;
use App\DTOs\ProviderPage;
use App\Services\Sync\ThrottledHttpClient;

/**
 * FakeStore API (https://fakestoreapi.com/products) için
 * ProviderClientInterface implementasyonu. DummyJSON'dan farklı olarak bu
 * API sayfalama yapmaz — `GET /products` düz bir JSON array olarak tüm
 * ürünleri (şu an 20 adet) tek seferde döner. Bu yüzden `fetchPage()` için
 * tek bir "sayfa" (page 0) her zaman TÜM veriyi içerir ve `totalPages`
 * daima 1'dir — SyncRunCoordinator bu provider için hep tek bir
 * `FetchProviderPageJob` kuyruklar.
 */
class FakeStoreProvider implements ProviderClientInterface
{
    public function __construct(private readonly ThrottledHttpClient $http)
    {
    }

    /**
     * `GET /products` ile (sayfalama olmadan) tüm ürünleri tek seferde
     * çekip normalize eder. `$page` parametresi göz ardı edilir (her zaman
     * tüm veri tek "sayfa"dır); 0'dan farklı bir `$page` çağrılırsa boş
     * döner (SyncRunCoordinator zaten `totalPages=1` gördüğü için hiçbir
     * zaman `$page > 0` ile çağırmaz — bu sadece savunma amaçlı).
     */
    public function fetchPage(int $page): ProviderPage
    {
        if ($page > 0) {
            return new ProviderPage(items: [], totalPages: 1);
        }

        $baseUrl = config('sync.providers.fakestore.base_url');

        $items = collect($this->http->get("{$baseUrl}/products"))
            ->map(fn (array $item) => $this->normalize($item))
            ->values()
            ->all();

        return new ProviderPage(items: $items, totalPages: 1);
    }

    /**
     * `GET /products/{id}` ile tek bir ürünü çeker. FakeStore, olmayan bir
     * id için 404 değil, boş body ile HTTP 200 döner (curl ile doğrulandı);
     * bu durumda `$this->http->get()` boş array döner ve `empty($response)`
     * kontrolü bunu "bulunamadı" olarak yakalar.
     */
    public function fetchOne(string $externalId): ?array
    {
        $baseUrl = config('sync.providers.fakestore.base_url');

        $response = $this->http->get("{$baseUrl}/products/{$externalId}");

        if (empty($response)) {
            return null;
        }

        return $this->normalize($response);
    }

    /**
     * FakeStore'un ham ürün şeklini ortak normalize şekline çevirir.
     * `stock` alanı FakeStore API'sinde hiç yok (envanter kavramı yok);
     * bu yüzden sabit `0` olarak set edilir — bkz.
     * docs/IMPLEMENTATION_PLAN.md "Provider API şeması netleştirmesi".
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
            'stock' => 0,
            'description' => $item['description'] ?? '',
        ];
    }
}
