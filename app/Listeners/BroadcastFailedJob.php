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
        // Named argüman KULLANILMAZ: Dispatchable::dispatch() trait'i
        // `func_get_args()` ile çalışıyor ve hiç formal parametresi yok
        // (`public static function dispatch()`) — PHP, parametresiz bir
        // metoda named argüman geçilince "Unknown named parameter" fatal
        // hatası fırlatır (bkz. SyncRunCoordinator'daki aynı sınıf hatanın
        // düzeltmesi, CHANGELOG.md). Bu satır hiç test edilmediği için
        // production'da fark edilmemişti — her failed job, dashboard'a
        // broadcast edilmeye çalışılırken sessizce fatal hata veriyordu.
        FailedJobRecorded::dispatch(
            $event->job->uuid(),
            $event->job->resolveName(),
            $event->job->getQueue(),
            $event->exception->getMessage(),
        );
    }
}
