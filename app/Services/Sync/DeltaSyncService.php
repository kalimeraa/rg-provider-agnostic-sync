<?php

namespace App\Services\Sync;

use App\Enums\ProviderType;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tek bir sayfalık normalize edilmiş ürün listesini hash-bazlı olarak DB'ye
 * upsert eder (ekle/güncelle/değişmediyse sadece "en son görüldü" bilgisini
 * tazele).
 *
 * **SİLME MANTIĞI BİLEREK BURADA YOK.** Eskiden (tek job'lu mimaride) bu
 * servis TÜM uzak listeyi görüp DB'de karşılığı olmayanları tek seferde
 * soft-delete edebiliyordu. Artık senkronizasyon `SyncRunCoordinator`
 * tarafından sayfa başına bir `FetchProviderPageJob`'a bölündüğü için, HİÇBİR
 * TEK ÇAĞRI uzak listenin TAMAMINI görmüyor — sadece kendi sayfasını. Silme
 * tespiti bu yüzden bir "mark-and-sweep" ile `SyncRunCoordinator`'ın TÜM
 * sayfa job'ları bittikten SONRA çalıştırdığı ayrı bir adıma taşındı: her
 * upsert, ürünü bu run'ın `SyncLog` id'siyle (`last_synced_log_id`) işaretler;
 * run tamamlanınca bu id'den FARKLI (yani bu run'da hiçbir sayfa tarafından
 * dokunulmamış) ürünler silinir.
 *
 * Not: işaretleyici olarak saat (`last_synced_at`) DEĞİL, monoton bir
 * `SyncLog.id` kullanılır — `Http::fake()` ile gerçek ağ gecikmesi olmadan
 * art arda çok hızlı çalışan iki run, container'ın saat çözünürlüğü
 * yüzünden aynı mikrosaniyeye bile denk gelebiliyordu (integration
 * testleriyle canlı yakalandı, bkz. CHANGELOG.md) — ID karşılaştırması bu
 * riski tamamen ortadan kaldırır.
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
    public function upsertPage(ProviderType $provider, array $items, CarbonInterface $syncRunStartedAt, int $syncLogId): array
    {
        $added = 0;
        $updated = 0;

        foreach ($items as $item) {
            $hash = $this->hashService->hash($item);

            DB::transaction(function () use ($provider, $item, $hash, $syncRunStartedAt, $syncLogId, &$added, &$updated) {
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
                        'last_synced_log_id' => $syncLogId,
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
                        'last_synced_log_id' => $syncLogId,
                    ]);

                    $updated++;
                } else {
                    // İçerik değişmedi; yine de "bu run'da görüldü" işaretini yenile (sweep adımı için).
                    $current->forceFill([
                        'last_synced_at' => $syncRunStartedAt,
                        'last_synced_log_id' => $syncLogId,
                    ])->save();
                }
            });
        }

        return ['added' => $added, 'updated' => $updated];
    }
}
