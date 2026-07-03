<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `SyncController::clearHistory()` çağrıldığında yayınlanır — dashboard'u
 * açık tutan HERKESİN geçmiş tablosu, isteği atan sekmeyle sınırlı kalmadan
 * (aynı `sync-status` kanalı üzerinden, diğer canlı event'lerle aynı yolla)
 * anında boşalır.
 */
class SyncHistoryCleared implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function broadcastOn(): Channel
    {
        return new Channel('sync-status');
    }

    public function broadcastAs(): string
    {
        return 'sync-history.cleared';
    }
}
