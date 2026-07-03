<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

/**
 * Gerçek bir tarayıcıda (headless Chrome, `selenium` container'ı üzerinden)
 * dashboard'u uçtan uca test eder — bkz. README.md §11 "Neden gerçek bir
 * E2E test": `SyncIdempotencyAndSweepTest` (Feature/) bir INTEGRATION
 * testtir (PHP katmanları arası, HTTP çağrıları hariç gerçek zincir); bu
 * dosya ise GERÇEK sayfa render'ı + gerçek JS + gerçek Reverb WebSocket
 * bağlantısı + gerçek nginx/php-fpm/MySQL/Redis stack'ine karşı çalışır —
 * hiçbir katman mock/fake DEĞİL. Kasıtlı olarak veritabanı state'ine dair
 * kesin sayı assertion'ları YAPILMAZ (dev DB'sini truncate etmeden, canlı
 * çalışan stack'e karşı testin tekrarlanabilir kalması için) — sadece
 * "sayfa hiç yenilenmeden, WebSocket üzerinden canlı güncelleniyor mu"
 * davranışı doğrulanır.
 */
class DashboardSyncTest extends DuskTestCase
{
    /**
     * Dashboard yüklenince başlık görünmeli ve WebSocket bağlantısı
     * kurulup "canlı" rozetini göstermeli.
     */
    #[Test]
    public function dashboardLoadsAndEstablishesLiveWebSocketConnection(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->assertSee('Sync Dashboard')
                ->waitForTextIn('#connection-badge', 'canlı', 10);
        });
    }

    /**
     * "Şimdi Senkronize Et" butonuna basınca, sync tamamlanınca (WebSocket
     * push ile, SAYFA HİÇ YENİLENMEDEN) "Son çalışma" zaman damgası YENİ bir
     * değere güncellenmeli — bu, tam zincirin (tarayıcı → nginx → php-fpm →
     * SyncRunCoordinator → gerçek DummyJSON API → MySQL → Reverb →
     * tarayıcıya geri WebSocket push) gerçekten çalıştığının kanıtıdır.
     * Gerçek dev queue (Horizon/Redis) çok hızlı işleyebildiği için
     * (bazen <1sn), butonun ARA'daki "disabled" anını yakalamaya
     * çalışmak yerine (yarış koşulu, kırılgan) NİHAİ sonucun değiştiğini
     * bekliyoruz.
     */
    #[Test]
    public function triggeringASyncUpdatesTheDashboardLiveWithoutPageReload(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')
                ->waitFor('[data-last-sync-for="dummyjson"]', 10);

            $before = $browser->text('[data-last-sync-for="dummyjson"]');

            $browser->click('.trigger-btn[data-provider="dummyjson"]')
                ->waitUsing(60, 250, function () use ($browser, $before) {
                    return $browser->text('[data-last-sync-for="dummyjson"]') !== $before;
                }, 'Son çalışma zaman damgası WebSocket üzerinden güncellenmedi.')
                ->assertPathIs('/');
        });
    }
}
