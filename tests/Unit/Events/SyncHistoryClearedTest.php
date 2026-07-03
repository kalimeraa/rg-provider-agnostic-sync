<?php

namespace Tests\Unit\Events;

use App\Events\SyncHistoryCleared;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `SyncController::clearHistory()` tarafından yayınlanır — dashboard'u açık
 * tutan HERKESİN geçmiş tablosunu, isteği atan sekmeyle sınırlı kalmadan
 * anında boşaltır.
 *
 * @covers \App\Events\SyncHistoryCleared
 */
class SyncHistoryClearedTest extends TestCase
{
    #[Test]
    public function implementsShouldBroadcastNowForInstantDelivery(): void
    {
        $this->assertInstanceOf(ShouldBroadcastNow::class, new SyncHistoryCleared());
    }

    #[Test]
    public function broadcastsOnTheSharedSyncStatusChannel(): void
    {
        $channel = (new SyncHistoryCleared())->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertSame('sync-status', $channel->name);
    }

    #[Test]
    public function broadcastsUnderTheSyncHistoryClearedEventName(): void
    {
        $this->assertSame('sync-history.cleared', (new SyncHistoryCleared())->broadcastAs());
    }
}
