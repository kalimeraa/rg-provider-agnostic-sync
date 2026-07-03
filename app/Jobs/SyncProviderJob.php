<?php

namespace App\Jobs;

use App\Enums\ProviderType;
use App\Services\Alerts\AlertService;
use App\Services\Sync\SyncRunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Scheduler'ın (ve manuel `/api/sync/trigger`'ın) dispatch ettiği ince
 * orkestrasyon job'u. Asıl iş `SyncRunCoordinator::start()`'ta: provider
 * kilidini alır, sayfaları öğrenir ve gerçek işi yapan
 * `FetchProviderPageJob`'lardan oluşan bir `Bus::batch()` dispatch eder —
 * bu job kendisi batch'in bitmesini BEKLEMEZ, sadece onu tetikleyip döner.
 *
 * Artık `ShouldBeUnique` DEĞİL: uniqueness artık bu job'un kendi ömrüne
 * değil, tetiklediği TÜM batch'in ömrüne yayılmalı — bu yüzden kilit
 * `SyncRunCoordinator` içinde elle (`Cache::lock`) yönetiliyor (bkz. o
 * class'ın PHPDoc'u).
 *
 * `tries`/`backoff`/`failed()` burada sadece "sayfaları öğrenmek için
 * yapılan ilk istek (page 0) başarısız oldu" senaryosunu kapsar — bir
 * sayfa job'unun batch içinde kalıcı olarak başarısız olması ayrı bir yoldan
 * (`SyncRunCoordinator::finishWithFailure()`, batch'in `catch()` callback'i
 * üzerinden) ele alınır, burada TEKRAR işlenmez.
 */
class SyncProviderJob implements ShouldQueue
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

    public function handle(SyncRunCoordinator $coordinator): void
    {
        $coordinator->start($this->provider);
    }

    /**
     * Sadece `handle()`'ın kendisi (yani "page 0'ı öğren + batch'i
     * dispatch et" adımı) `tries` hakkının tamamını tüketip kalıcı olarak
     * başarısız olursa Laravel tarafından bir kez çağrılır.
     */
    public function failed(Throwable $exception): void
    {
        app(AlertService::class)->recordSyncFailure($this->provider);
    }
}
