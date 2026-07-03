<?php

namespace App\Jobs;

use App\Enums\ProviderType;
use App\Models\SyncLog;
use App\Services\Sync\DeltaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Bir provider için tek bir delta sync çalıştırır. Uniqueness provider
 * bazlıdır (job invocation bazlı değil): bir DummyJSON sync'i çalışırken
 * ikinci bir DummyJSON sync'i kuyruğa alınamaz/başlatılamaz, ama bir
 * FakeStore sync'i aynı anda gayet rahat çalışabilir.
 *
 * Her deneme (retry'lar dahil) kendi SyncLog satırını yazar; böylece sync
 * geçmişinde hangi denemenin başarısız olduğu, hangisinin sonunda başarılı
 * olduğu ayrı ayrı görülebilir — retry'lar arasında tek bir satır sessizce
 * üzerine yazılmaz.
 */
class SyncProviderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maksimum deneme sayısı (case gereksinimi: 3 deneme). */
    public int $tries = 3;

    /** Denemeler arası bekleme süreleri (saniye) — exponential backoff: 1s, 2s, 4s. */
    /** @var array<int, int> */
    public array $backoff = [1, 2, 4];

    public function __construct(public readonly ProviderType $provider)
    {
        // Tek, provider-agnostic bir kuyruk: "default" yerine anlamlı bir isim,
        // dummyjson/fakestore için ayrı kuyruklara BÖLÜNMÜYOR — hangi provider
        // olduğu zaten $this->provider'da taşınıyor, ayrı kuyruk gereksiz.
        $this->onQueue('product-sync');
    }

    /**
     * Kilit anahtarı olarak sadece provider değeri kullanılır — hangi job
     * payload'ının tetiklediğinden bağımsız olarak, provider başına aktif
     * tek bir sync olur.
     */
    public function uniqueId(): string
    {
        return $this->provider->value;
    }

    /**
     * Kilidin en fazla ne kadar (saniye) tutulacağı — bir worker job'u
     * bitirmeden ölürse kilidin sonsuza dek asılı kalmaması için bir üst sınır
     * (config('sync.job_unique_for'), varsayılan 900 saniye).
     */
    public function uniqueFor(): int
    {
        return (int) config('sync.job_unique_for', 900);
    }

    /**
     * Asıl iş burada: önce "running" durumunda bir SyncLog satırı açar,
     * DeltaSyncService'i çalıştırır, sonucu (added/updated/deleted) aynı
     * satıra yazar. Hata olursa satırı "failed" olarak işaretleyip hatayı
     * tekrar fırlatır — böylece Laravel'in kendi retry/backoff mekanizması
     * (tries/backoff) devreye girer.
     */
    public function handle(DeltaSyncService $service): void
    {
        $log = SyncLog::create([
            'provider_type' => $this->provider,
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $result = $service->sync($this->provider);

            $log->update([
                'completed_at' => now(),
                'status' => 'completed',
                'products_added' => $result->added,
                'products_updated' => $result->updated,
                'products_deleted' => $result->deleted,
            ]);
        } catch (Throwable $e) {
            $log->update([
                'completed_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
