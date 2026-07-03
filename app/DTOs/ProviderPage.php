<?php

namespace App\DTOs;

/**
 * `ProviderClientInterface::fetchPage()`'in dönüş değeri. `totalPages`
 * provider'ın KENDİSİ tarafından hesaplanır (kendi sayfa boyutu bilgisiyle)
 * — çağıran taraf (SyncRunCoordinator) provider'ın sayfa boyutunu hiç
 * bilmek zorunda kalmaz, sadece "kaç sayfa var" bilgisini kullanır.
 */
final class ProviderPage
{
    /**
     * @param  array<int, array{external_id: string, name: string, price: float, stock: int, description: string}>  $items  Bu sayfadaki normalize edilmiş ürünler.
     * @param  int  $totalPages  Provider'ın toplam sayfa sayısı (1'den az olamaz).
     */
    public function __construct(
        public readonly array $items,
        public readonly int $totalPages,
    ) {
    }
}
