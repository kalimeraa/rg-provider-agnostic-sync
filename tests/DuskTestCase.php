<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Prepare for Dusk test execution. Yerel bir ChromeDriver process'i
     * BAŞLATILMAZ — `docker-compose.yml`'deki ayrı `selenium`
     * (`selenium/standalone-chrome`) container'ına, `driver()`'daki
     * `DUSK_DRIVER_URL` üzerinden `RemoteWebDriver` ile bağlanılır.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        //
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://selenium:4444',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }

    /**
     * Uygulamanın base URL'i — `config('app.url')` (host'ta `localhost:8080`)
     * BİLEREK KULLANILMAZ: tarayıcı, host makinede değil `selenium`
     * container'ında çalışıyor, `docker-compose.yml`'in AYNI ağındaki
     * `webserver` (nginx) servisine kendi container ağı üzerinden
     * doğrudan erişebiliyor.
     */
    protected function baseUrl(): string
    {
        return $_ENV['DUSK_BASE_URL'] ?? env('DUSK_BASE_URL') ?? 'http://webserver';
    }
}
