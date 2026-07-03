<?php

namespace App\Exceptions\Sync;

/**
 * Bir provider, tek bir sync çalıştırması içinde çok fazla ardışık başarısız
 * request ürettiğinde fırlatılır (config('sync.max_consecutive_failures')).
 * Sync'i durdurur; böylece SyncProviderJob bunu loglayıp kendi retry/backoff
 * mekanizmasına devredebilir.
 */
class CircuitBreakerOpenException extends ProviderRequestException
{
}
