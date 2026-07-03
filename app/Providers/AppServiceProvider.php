<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * `ThrottledHttpClient` artık burada `bind()` edilmiyor: client'ın
 * pacing/circuit-breaker durumu provider'a göre değişen bir `$providerKey`
 * ile Redis'te paylaşılıyor (bkz. o class'ın PHPDoc'u), bu yüzden
 * `ProviderFactory` onu doğrudan, hangi provider için üretildiğini bilerek
 * inşa ediyor — generic bir container binding'i bu bilgiyi taşıyamazdı.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Bu proje için ek bir bootstrap adımı gerekmiyor.
     */
    public function boot(): void
    {
        //
    }
}
