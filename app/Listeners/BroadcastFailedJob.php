<?php

namespace App\Listeners;

use App\Events\FailedJobRecorded;
use Illuminate\Queue\Events\JobFailed;

/**
 * Laravel'in dahili `JobFailed` event'ini (hangi queue job'u olursa olsun,
 * `tries` hakkı tükenip `failed_jobs`'a düştüğünde tetiklenir) dinleyip bunu
 * `FailedJobRecorded` broadcast event'ine çevirir. Tek bir merkezi noktada
 * — `SyncProviderJob` veya `FetchProviderPageJob`'a ayrı ayrı broadcast
 * kodu eklemeye gerek kalmaz.
 */
class BroadcastFailedJob
{
    public function handle(JobFailed $event): void
    {
        FailedJobRecorded::dispatch(
            uuid: $event->job->uuid(),
            jobClass: $event->job->resolveName(),
            queue: $event->job->getQueue(),
            exceptionMessage: $event->exception->getMessage(),
        );
    }
}
