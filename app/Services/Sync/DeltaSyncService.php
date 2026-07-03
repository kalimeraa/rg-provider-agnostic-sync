<?php

namespace App\Services\Sync;

use App\DTOs\SyncResult;
use App\Enums\ProviderType;
use App\Models\Product;
use App\Services\Providers\ProviderFactory;
use Illuminate\Support\Facades\DB;

/**
 * Bir provider için hash-bazlı delta senkronizasyonu yapar: provider'dan
 * gelen güncel liste ile DB'deki mevcut kayıtları `external_id` üzerinden
 * eşleştirip yeni/değişen/silinen ürünleri tespit eder ve tek bir
 * `DB::transaction()` içinde uygular (idempotency + tutarlılık garantisi).
 */
class DeltaSyncService
{
    public function __construct(
        private readonly ProviderFactory $providerFactory,
        private readonly HashService $hashService,
    ) {
    }

    /**
     * Verilen provider'ı senkronize eder:
     * 1. Provider'dan tüm ürünleri çeker (HTTP hataları burada, transaction
     *    başlamadan önce fırlar — DB'ye yarım bir sync yazılmaz).
     * 2. DB'deki mevcut kayıtları (soft-delete edilmiş olanlar dahil)
     *    `external_id`'ye göre indeksler.
     * 3. Her uzak ürün için: DB'de yoksa ekler; soft-delete edilmişse geri
     *    getirir; hash'i değiştiyse günceller; değişmediyse sadece
     *    `last_synced_at`'i tazeler.
     * 4. DB'de olup uzak listede artık bulunmayan (ve henüz silinmemiş)
     *    ürünleri soft-delete eder.
     *
     * Aynı sync iki kez çalıştırılsa bile (idempotency) DB'de duplicate
     * kayıt oluşmaz — unique constraint + bu eşleştirme mantığı sayesinde.
     */
    public function sync(ProviderType $provider): SyncResult
    {
        $remoteProducts = $this->providerFactory->make($provider)->fetchAll()->keyBy('external_id');

        $added = 0;
        $updated = 0;
        $deleted = 0;

        DB::transaction(function () use ($provider, $remoteProducts, &$added, &$updated, &$deleted) {
            $existing = Product::withTrashed()
                ->where('provider_type', $provider)
                ->get()
                ->keyBy('external_id');

            foreach ($remoteProducts as $externalId => $item) {
                $hash = $this->hashService->hash($item);
                $current = $existing->get($externalId);

                if ($current === null) {
                    Product::create([
                        'provider_type' => $provider,
                        'external_id' => $externalId,
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'stock' => $item['stock'],
                        'description' => $item['description'],
                        'data_hash' => $hash,
                        'last_synced_at' => now(),
                    ]);

                    $added++;

                    continue;
                }

                // Ürün daha önce soft-delete edilmişse (provider'da kaybolmuştu) ve şimdi tekrar
                // görünüyorsa geri getir; restore başlı başına "updated" sayılmaz, sadece hash
                // değiştiyse aşağıdaki blok updated sayacını artırır.
                if ($current->trashed()) {
                    $current->restore();
                }

                if ($current->data_hash !== $hash) {
                    $current->update([
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'stock' => $item['stock'],
                        'description' => $item['description'],
                        'data_hash' => $hash,
                        'last_synced_at' => now(),
                    ]);

                    $updated++;
                } else {
                    // İçerik değişmedi; yine de "en son ne zaman görüldü" bilgisini güncelle.
                    $current->forceFill(['last_synced_at' => now()])->save();
                }
            }

            // DB'de olup uzak listede artık karşılığı olmayan external_id'ler = provider'da silinmiş ürünler.
            $missingExternalIds = $existing->keys()->diff($remoteProducts->keys());

            // whereNull('deleted_at'): zaten soft-delete edilmiş bir ürünü tekrar "silindi" saymamak için.
            $deleted = Product::where('provider_type', $provider)
                ->whereIn('external_id', $missingExternalIds)
                ->whereNull('deleted_at')
                ->get()
                ->each(fn (Product $product) => $product->delete())
                ->count();
        });

        return new SyncResult($added, $updated, $deleted);
    }
}
