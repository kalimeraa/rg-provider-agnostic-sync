<?php

namespace App\Providers;

use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * ThrottledHttpClient'ı config'ten okunan rate-limit/circuit-breaker
     * değerleriyle `bind()` eder (singleton DEĞİL — her `make()` çağrısında
     * taze bir instance üretilir ki `consecutiveFailures` sayacı her sync
     * çalıştırmasında sıfırdan başlasın). Bu sayede DummyJsonProvider/
     * FakeStoreProvider constructor'larına otomatik enjekte edilir.
     */
    public function register(): void
    {
        $this->app->bind(ThrottledHttpClient::class, fn () => new ThrottledHttpClient(
            requestsPerSecond: (int) config('sync.rate_limit_per_second', 5),
            maxConsecutiveFailures: (int) config('sync.max_consecutive_failures', 5),
        ));
    }

    /**
     * Bu proje için ek bir bootstrap adımı gerekmiyor.
     */
    public function boot(): void
    {
        //
    }
}
