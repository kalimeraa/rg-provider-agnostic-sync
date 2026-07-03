<?php

namespace App\Console;

use App\Enums\ProviderType;
use App\Jobs\SyncProviderJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Her provider için `SYNC_INTERVAL_MINUTES` (varsayılan 5) dakikada bir
     * `SyncProviderJob`'ı kuyruğa ekleyecek şekilde scheduler'ı tanımlar.
     * İki provider birbirinden bağımsız zamanlanır; aynı anda çakışsalar
     * bile aralarında bir engelleme yok — sadece AYNI provider için
     * `ShouldBeUnique` kilidi devreye girer (bkz. SyncProviderJob).
     *
     * Bilinçli olarak `withoutOverlapping()` KULLANILMADI: iş zaten job
     * seviyesinde tekilleştiriliyor, scheduler seviyesinde ikinci bir kilit
     * mekanizması eklemek yanlış katmanda çözüm/karmaşıklık olurdu.
     */
    protected function schedule(Schedule $schedule): void
    {
        $minutes = max(1, (int) config('sync.interval_minutes', 5));

        foreach (ProviderType::cases() as $provider) {
            $schedule->job(new SyncProviderJob($provider))
                ->cron("*/{$minutes} * * * *")
                ->name("sync-{$provider->value}");
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
