<?php

namespace App\Enums;

/**
 * Desteklenen iki tedarikçi API'si. Her case'in string değeri, kalıcı ve
 * saklanabilir bir provider kimliği gerektiği her yerde kullanılır:
 * `products.provider_type` / `sync_logs.provider_type` DB kolonları,
 * `SyncProviderJob` uniqueness kilit anahtarı ve `config/sync.php` array
 * key'leri.
 */
enum ProviderType: string
{
    case DummyJson = 'dummyjson';
    case FakeStore = 'fakestore';

    /**
     * Dashboard/UI'da gösterilecek okunabilir isim.
     */
    public function label(): string
    {
        return match ($this) {
            self::DummyJson => 'DummyJSON',
            self::FakeStore => 'FakeStore API',
        };
    }
}
