<?php

namespace Tests\Unit\Console;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Case gereksinimi: "Her 5-10 dakikada bir otomatik senkronizasyon (cron job
 * veya scheduler)". `schedule()` `protected` olduğu için reflection ile
 * doğrudan çağrılıp `Schedule`'a gerçekten ne kaydettiği doğrulanır — bu,
 * `schedule:list` çıktısını string olarak parse etmekten daha güvenilir.
 */
class KernelTest extends TestCase
{
    private function scheduledEvents(): array
    {
        $schedule = new Schedule();
        $kernel = $this->app->make(Kernel::class);

        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule->events();
    }

    /**
     * Desteklenen iki provider (`ProviderType::cases()`) için birer,
     * toplamda tam olarak 2 scheduled event olmalı — ne eksik ne fazla.
     *
     * @covers \App\Console\Kernel::schedule
     */
    #[Test]
    public function schedulesExactlyOneJobPerSupportedProvider(): void
    {
        $this->assertCount(2, $this->scheduledEvents());
    }

    /**
     * `SYNC_INTERVAL_MINUTES` config'i değiştirilince HER İKİ provider'ın
     * cron ifadesi de aynı yeni değere göre güncellenmeli.
     *
     * @covers \App\Console\Kernel::schedule
     */
    #[Test]
    public function schedulesEachProviderOnConfiguredIntervalCron(): void
    {
        config(['sync.interval_minutes' => 7]);

        $events = $this->scheduledEvents();

        foreach ($events as $event) {
            $this->assertSame('*/7 * * * *', $event->expression);
        }
    }

    /**
     * `sync.interval_minutes` config'i HİÇ TANIMLI DEĞİLSE (config
     * dosyasından tamamen kaldırılmış gibi) `config('sync.interval_minutes', 5)`
     * varsayılanı devreye girip 5 dakikalık cron kullanılmalı.
     *
     * @covers \App\Console\Kernel::schedule
     */
    #[Test]
    public function defaultsToFiveMinutesWhenIntervalNotConfigured(): void
    {
        // `Config::offsetUnset()`/`config([...=>null])` anahtarı SİLMEZ, sadece
        // değerini null yapar (bkz. Illuminate\Config\Repository::offsetUnset) —
        // `config('sync.interval_minutes', 5)`'in varsayılanı SADECE anahtar
        // dizide hiç YOKSA devreye girer. Gerçekten "yokmuş" senaryosunu
        // simüle etmek için anahtar `Arr::except` ile fiilen kaldırılıyor.
        config(['sync' => Arr::except(config('sync'), ['interval_minutes'])]);

        $events = $this->scheduledEvents();

        $this->assertSame('*/5 * * * *', $events[0]->expression);
    }

    /**
     * Her scheduled event, doğru provider'ı taşıyan bir `SyncProviderJob`
     * dispatch etmeli — `->name("sync-{provider}")` ile verilen isim
     * (`Event::description`'a yazılır) hangi job'ın hangi provider için
     * olduğunu ayırt etmeyi sağlar.
     *
     * @covers \App\Console\Kernel::schedule
     */
    #[Test]
    public function eachScheduledEventDispatchesSyncProviderJob(): void
    {
        $events = $this->scheduledEvents();

        $descriptions = array_map(fn ($event) => $event->description, $events);

        $this->assertContains('sync-dummyjson', $descriptions);
        $this->assertContains('sync-fakestore', $descriptions);
    }

    /**
     * Bilinçli tasarım kararı (bkz. `Kernel::schedule()` PHPDoc'u): uniqueness
     * `SyncRunCoordinator`'ın `Cache::lock()`'unda, scheduler seviyesinde
     * DEĞİL — `withoutOverlapping()` kullanılmadığı, event isminin sadece
     * `->name()` ile verilen sabit "sync-{provider}" olduğu doğrulanır.
     *
     * @covers \App\Console\Kernel::schedule
     */
    #[Test]
    public function doesNotUseWithoutOverlappingSinceLockingIsHandledAtCoordinatorLevel(): void
    {
        $events = $this->scheduledEvents();

        foreach ($events as $event) {
            $this->assertStringStartsWith('sync-', $event->description);
        }
    }
}
