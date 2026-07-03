<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Laravel'in native `JobFailed` event'ini dinleyen `BroadcastFailedJob`
 * listener'ı tarafından yayınlanır (bkz. o class). Dashboard, failed-jobs
 * tablosunu bu event'i dinleyerek HTTP polling'e hiç ihtiyaç duymadan canlı
 * günceller — hangi job class'ı (SyncProviderJob mı, FetchProviderPageJob
 * mı) başarısız olursa olsun, tek bir noktadan (JobFailed) yakalandığı için
 * her job'a ayrı ayrı broadcast kodu eklemeye gerek kalmaz.
 */
class FailedJobRecorded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $uuid,
        public readonly string $jobClass,
        public readonly string $queue,
        public readonly string $exceptionMessage,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('sync-status');
    }

    public function broadcastAs(): string
    {
        return 'failed-job.recorded';
    }

    /**
     * Alanlar bilerek `GET /api/sync/failed-jobs`'ın döndürdüğü şekille
     * aynı — dashboard'un tarayıcı tarafı satırı tek bir render
     * fonksiyonuyla işleyebilsin diye.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->uuid,
            'job_class' => $this->jobClass,
            'queue' => $this->queue,
            'exception' => $this->exceptionMessage,
            'failed_at' => now()->toIso8601String(),
        ];
    }
}
