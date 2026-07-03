<?php

namespace App\Services\Providers;

use App\Contracts\ProviderClientInterface;
use App\Enums\ProviderType;
use Illuminate\Contracts\Container\Container;

/**
 * ProviderType enum değerini somut ProviderClientInterface implementasyonuna
 * çeviren Factory. Yeni bir tedarikçi eklemek burada tek bir `match` kolu
 * eklemek demektir; DeltaSyncService bu factory sayesinde hangi provider'ın
 * hangi sınıfa karşılık geldiğini hiç bilmez.
 */
class ProviderFactory
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Verilen provider için container'dan (ThrottledHttpClient dahil tüm
     * bağımlılıkları enjekte edilmiş) taze bir client instance'ı üretir.
     */
    public function make(ProviderType $provider): ProviderClientInterface
    {
        return match ($provider) {
            ProviderType::DummyJson => $this->container->make(DummyJsonProvider::class),
            ProviderType::FakeStore => $this->container->make(FakeStoreProvider::class),
        };
    }
}
