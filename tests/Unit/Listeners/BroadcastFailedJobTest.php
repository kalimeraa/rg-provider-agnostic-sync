<?php

namespace Tests\Unit\Listeners;

use App\Events\FailedJobRecorded;
use App\Listeners\BroadcastFailedJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Laravel'in native `JobFailed` event'ini (hangi job class'ı olursa olsun)
 * `FailedJobRecorded` broadcast event'ine çevirir — bkz. o class'ın PHPDoc'u.
 *
 * @covers \App\Listeners\BroadcastFailedJob
 */
class BroadcastFailedJobTest extends TestCase
{
    #[Test]
    public function translatesJobFailedIntoFailedJobRecordedWithMappedFields(): void
    {
        Event::fake([FailedJobRecorded::class]);

        $job = $this->createMock(Job::class);
        $job->method('uuid')->willReturn('uuid-123');
        $job->method('resolveName')->willReturn('App\\Jobs\\FetchProviderPageJob');
        $job->method('getQueue')->willReturn('product-sync');

        $event = new JobFailed('redis', $job, new RuntimeException('Connection refused'));

        (new BroadcastFailedJob())->handle($event);

        Event::assertDispatched(FailedJobRecorded::class, function (FailedJobRecorded $recorded) {
            return $recorded->uuid === 'uuid-123'
                && $recorded->jobClass === 'App\\Jobs\\FetchProviderPageJob'
                && $recorded->queue === 'product-sync'
                && $recorded->exceptionMessage === 'Connection refused';
        });
    }
}
