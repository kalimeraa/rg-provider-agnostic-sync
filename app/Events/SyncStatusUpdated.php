<?php

namespace App\Events;

use App\Enums\ProviderType;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Bir provider'ın sync run'ı (tüm sayfalar + sweep, ya da kalıcı hata ile)
 * sonuçlanınca yayınlanır. Dashboard bunu dinleyip `setInterval` polling
 * beklemeden anında kendini tazeler (bkz. resources/views/dashboard.blade.php).
 *
 * Herkese açık, kimlik doğrulaması gerektirmeyen bir kanal (`sync-status`) —
 * bu dashboard internal bir araç, taşınan veri (added/updated/deleted
 * sayıları, provider adı) hassas değil; private/presence kanal + auth
 * mekanizması kurmak bu senaryoda over-engineering olurdu.
 *
 * `ShouldBroadcastNow` (kuyruklanan `ShouldBroadcast` DEĞİL): sync zaten
 * bir job/batch'in en sonunda tetikleniyor, olayı ayrıca kuyruklamak
 * gereksiz bir gecikme katmanı eklerdi — anında broadcast edilmesi yeterli.
 */
class SyncStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly ProviderType $provider,
        public readonly string $status,
        public readonly ?string $startedAt = null,
        public readonly ?string $completedAt = null,
        public readonly int $added = 0,
        public readonly int $updated = 0,
        public readonly int $deleted = 0,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('sync-status');
    }

    /**
     * Dashboard'un event ismini elle string birleştirmeden (`App\Events\...`
     * yerine) yakalayabilmesi için kısa, sabit bir isim.
     */
    public function broadcastAs(): string
    {
        return 'sync-status.updated';
    }

    /**
     * Alanlar bilerek `SyncLogResource`'la aynı isimlendirmede
     * (`products_added` vb.) — dashboard'un tarayıcı tarafı, hem ilk HTTP
     * yüklemesinden (`GET /api/sync/history`) hem canlı socket event'inden
     * gelen satırları AYNI render fonksiyonuyla işleyebilsin diye.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'provider' => $this->provider->value,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'products_added' => $this->added,
            'products_updated' => $this->updated,
            'products_deleted' => $this->deleted,
            'error_message' => $this->errorMessage,
        ];
    }
}
