<?php

namespace App\Services\Sync;

/**
 * Normalize edilmiş bir ürünün delta-sync için kullanılan içerik hash'ini
 * hesaplar. DeltaSyncService, provider'dan gelen hash'i DB'deki
 * `data_hash` ile karşılaştırıp değişiklik olup olmadığına karar verir.
 */
class HashService
{
    /**
     * Normalize edilmiş bir ürünün kanonik sha256 hash'i. Bilinçli olarak
     * external_id/provider_type'ı (içerik değil kimlik bilgisi) ve
     * provider'a özgü volatile alanları (rating, resim, kategori vb.)
     * hash'e dahil etmez — bunlar değişse bile ürün "içeriği" değişmiş
     * sayılmaz.
     *
     * `price` `number_format` ile 2 ondalığa sabitlenir ki float
     * temsil farkları (ör. 9.990000001) hash'i gereksiz yere değiştirmesin.
     *
     * @param  array{name: string, price: float|int|string, stock: int, description: ?string}  $product
     */
    public function hash(array $product): string
    {
        $payload = [
            'name' => (string) $product['name'],
            'price' => number_format((float) $product['price'], 2, '.', ''),
            'stock' => (int) $product['stock'],
            'description' => (string) ($product['description'] ?? ''),
        ];

        return hash('sha256', json_encode($payload));
    }
}
