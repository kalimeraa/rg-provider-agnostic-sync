<?php

namespace App\Contracts;

use App\DTOs\ProviderPage;

/**
 * Bir tedarikçi API'sinden ürün çekmenin ortak sözleşmesi (Strategy pattern).
 * DummyJsonProvider ve FakeStoreProvider bu interface'i implemente eder;
 * SyncRunCoordinator hangi implementasyonla çalıştığını bilmeden aynı
 * şekilde kullanır — yeni bir tedarikçi eklemek yeni bir implementasyon
 * demektir, çağıran taraflara dokunulmaz.
 *
 * `fetchAll()` YERİNE `fetchPage()`: senkronizasyon artık tek bir job içinde
 * tüm sayfaları döngüyle çekmiyor, her sayfayı ayrı bir `FetchProviderPageJob`
 * olarak kuyruğa alıyor (paralel çalışabilsin, tek bir job'un tüm sayfaları
 * çekmeye çalışırken zaman aşımına uğrama riskini ortadan kaldırsın). Bu
 * yüzden interface artık "tek sayfa getir" + "kaç sayfa var" bilgisini
 * sunuyor, "hepsini getir"i değil.
 */
interface ProviderClientInterface
{
    /**
     * Provider'ın `$page`'inci sayfasını (0-tabanlı), normalize edilmiş
     * ürünlerle birlikte çeker. Sayfalama yapmayan provider'lar (ör.
     * FakeStore) için `$page` her zaman 0 olmalı ve `totalPages` 1 döner.
     */
    public function fetchPage(int $page): ProviderPage;

    /**
     * Provider'daki tek bir ürünü kendi id'siyle çeker, aynı şekilde
     * normalize eder. Provider'da böyle bir ürün yoksa null döner.
     *
     * @return array{external_id: string, name: string, price: float, stock: int, description: string}|null
     */
    public function fetchOne(string $externalId): ?array;
}
