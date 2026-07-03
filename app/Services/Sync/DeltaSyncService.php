<?php

namespace App\Services\Sync;

use App\Enums\ProviderType;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tek bir sayfalık normalize edilmiş ürün listesini hash-bazlı olarak DB'ye
 * upsert eder (ekle/güncelle/değişmediyse sadece `last_synced_at`'i tazele).
 *
 * **SİLME MANTIĞI BİLEREK BURADA YOK.** Eskiden (tek job'lu mimaride) bu
 * servis TÜM uzak listeyi görüp DB'de karşılığı olmayanları tek seferde
 * soft-delete edebiliyordu. Artık senkronizasyon `SyncRunCoordinator`
 * tarafından sayfa başına bir `FetchProviderPageJob`'a bölündüğü için, HİÇBİR
 * TEK ÇAĞRI uzak listenin TAMAMINI görmüyor — sadece kendi sayfasını. Silme
 * tespiti bu yüzden bir "mark-and-sweep" ile `SyncRunCoordinator`'ın TÜM
 * sayfa job'ları bittikten SONRA çalıştırdığı ayrı bir adıma taşındı: her
 * upsert `last_synced_at`'i bu sync run'a özel SABİT bir zaman damgasıyla
 * (`$syncRunStartedAt`, her item için değil, tüm run için aynı) işaretler;
 * run tamamlanınca bu damgadan daha eski `last_synced_at`'e sahip ürünler
 * ("bu run'da hiçbir sayfa tarafından dokunulmadı" demektir) silinir.
 */
class DeltaSyncService
{
    public function __construct(private readonly HashService $hashService)
    {
    }

    /**
     * Verilen sayfadaki her normalize edilmiş item'ı upsert eder. Her item
     * kendi `DB::transaction()`'ı içinde, satır kilidiyle (`lockForUpdate`)
     * işlenir — aynı `external_id`'ye iki farklı sayfa job'unun aynı anda
     * dokunması teorik olarak mümkün değildir (sayfalar ayrık ürün
     * kümeleridir), ama satır kilidi yine de ucuz bir güvenlik payıdır.
     *
     * @param  array<int, array{external_id: string, name: string, price: float, stock: int, description: string}>  $items
     * @return array{added: int, updated: int}
     */
    public function upsertPage(ProviderType $provider, array $items, CarbonInterface $syncRunStartedAt): array
    {
        $added = 0;
        $updated = 0;

        foreach ($items as $item) {
            $hash = $this->hashService->hash($item);

            DB::transaction(function () use ($provider, $item, $hash, $syncRunStartedAt, &$added, &$updated) {
                $current = Product::withTrashed()
                    ->where('provider_type', $provider)
                    ->where('external_id', $item['external_id'])
                    ->lockForUpdate()
                    ->first();

                if ($current === null) {
                    Product::create([
                        'provider_type' => $provider,
                        'external_id' => $item['external_id'],
                        'name' => $item['name'],
                        'price' => $item['price'],
                        'stock' => $item['stock'],
                        'description' => $item['description'],
                        'data_hash' => $hash,
                        'last_synced_at' => $syncRunStartedAt,
                    ]);

                    $added++;

                    return;
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
                        'last_synced_at' => $syncRunStartedAt,
                    ]);

                    $updated++;
                } else {
                    // İçerik değişmedi; yine de "bu run'da görüldü" damgasını işle (sweep adımı için).
                    $current->forceFill(['last_synced_at' => $syncRunStartedAt])->save();
                }
            });
        }

        return ['added' => $added, 'updated' => $updated];
    }
}
