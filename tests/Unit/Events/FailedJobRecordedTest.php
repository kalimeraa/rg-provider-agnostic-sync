<?php

namespace Tests\Unit\Events;

use App\Events\FailedJobRecorded;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `BroadcastFailedJob` listener'ının ürettiği event — dashboard'un failed-jobs
 * tablosunu polling'siz güncellemesini sağlar.
 *
 * @covers \App\Events\FailedJobRecorded
 */
class FailedJobRecordedTest extends TestCase
{
    #[Test]
    public function implementsShouldBroadcastNowForInstantDelivery(): void
    {
        $event = new FailedJobRecorded('uuid-1', 'App\\Jobs\\FetchProviderPageJob', 'product-sync', 'boom');

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    #[Test]
    public function broadcastsOnTheSharedSyncStatusChannel(): void
    {
        $event = new FailedJobRecorded('uuid-1', 'App\\Jobs\\FetchProviderPageJob', 'product-sync', 'boom');

        $channel = $event->broadcastOn();

        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertSame('sync-status', $channel->name);
    }

    #[Test]
    public function broadcastsUnderTheFailedJobRecordedEventName(): void
    {
        $event = new FailedJobRecorded('uuid-1', 'App\\Jobs\\FetchProviderPageJob', 'product-sync', 'boom');

        $this->assertSame('failed-job.recorded', $event->broadcastAs());
    }

    #[Test]
    public function broadcastPayloadMatchesFailedJobsApiShape(): void
    {
        // Dashboard'un tek bir render fonksiyonu hem GET /api/sync/failed-jobs
        // hem bu canlı event'i işleyebilsin diye alan isimleri kasıtlı olarak aynı.
        $event = new FailedJobRecorded(
            uuid: 'uuid-1',
            jobClass: 'App\\Jobs\\FetchProviderPageJob',
            queue: 'product-sync',
            exceptionMessage: 'Connection refused',
        );

        $payload = $event->broadcastWith();

        $this->assertSame('uuid-1', $payload['uuid']);
        $this->assertSame('App\\Jobs\\FetchProviderPageJob', $payload['job_class']);
        $this->assertSame('product-sync', $payload['queue']);
        $this->assertSame('Connection refused', $payload['exception']);
        $this->assertArrayHasKey('failed_at', $payload);
    }
}
