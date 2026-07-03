<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * Bir tedarikçi API'sinden ürün çekmenin ortak sözleşmesi (Strategy pattern).
 * DummyJsonProvider ve FakeStoreProvider bu interface'i implemente eder;
 * DeltaSyncService hangi implementasyonla çalıştığını bilmeden aynı şekilde
 * kullanır — yeni bir tedarikçi eklemek yeni bir implementasyon demektir,
 * DeltaSyncService'e dokunulmaz.
 */
interface ProviderClientInterface
{
    /**
     * Provider'daki tüm ürünleri, normalize edilmiş
     * {external_id, name, price, stock, description} şeklinde çeker.
     *
     * @return Collection<int, array{external_id: string, name: string, price: float, stock: int, description: string}>
     */
    public function fetchAll(): Collection;

    /**
     * Provider'daki tek bir ürünü kendi id'siyle çeker, aynı şekilde
     * normalize eder. Provider'da böyle bir ürün yoksa null döner.
     *
     * @return array{external_id: string, name: string, price: float, stock: int, description: string}|null
     */
    public function fetchOne(string $externalId): ?array;
}
