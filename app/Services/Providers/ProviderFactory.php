<?php

namespace App\Services\Providers;

use App\Contracts\ProviderClientInterface;
use App\Enums\ProviderType;
use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Contracts\Container\Container;

/**
 * ProviderType enum değerini somut ProviderClientInterface implementasyonuna
 * çeviren Factory. Yeni bir tedarikçi eklemek burada tek bir `match` kolu
 * eklemek demektir; SyncRunCoordinator/FetchProviderPageJob bu factory
 * sayesinde hangi provider'ın hangi sınıfa karşılık geldiğini hiç bilmez.
 *
 * `ThrottledHttpClient`'ı BİLEREK burada, elle inşa ediyoruz (container'ın
 * otomatik autowiring'ine bırakmıyoruz): client artık pacing/circuit-breaker
 * durumunu `$providerKey`'e göre Redis'te paylaşıyor (bkz. o class'ın
 * PHPDoc'u), ve bu anahtar provider'a göre değişmesi gereken tek parametre
 * — container'ın "hangi provider için" bilgisi olmadan bunu autowire etmesi
 * mümkün değil.
 */
class ProviderFactory
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Verilen provider için, kendi provider-key'ine sahip taze bir
     * ThrottledHttpClient'la donatılmış bir client instance'ı üretir.
     */
    public function make(ProviderType $provider): ProviderClientInterface
    {
        $http = new ThrottledHttpClient(
            providerKey: $provider->value,
            requestsPerSecond: (int) config('sync.rate_limit_per_second', 5),
            maxConsecutiveFailures: (int) config('sync.max_consecutive_failures', 5),
        );

        return match ($provider) {
            ProviderType::DummyJson => $this->container->makeWith(DummyJsonProvider::class, ['http' => $http]),
            ProviderType::FakeStore => $this->container->makeWith(FakeStoreProvider::class, ['http' => $http]),
        };
    }
}
