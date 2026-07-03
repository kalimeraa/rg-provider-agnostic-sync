<?php

namespace App\Exceptions\Sync;

/**
 * Bir provider, tek bir sync çalıştırması içinde çok fazla ardışık başarısız
 * request ürettiğinde fırlatılır (config('sync.max_consecutive_failures')).
 * Sync'i durdurur; böylece SyncProviderJob bunu loglayıp kendi retry/backoff
 * mekanizmasına devredebilir.
 *
 * `$consecutiveFailures` yapılandırılmış bir alan olarak taşınır (sadece
 * exception mesajının metnine gömülü değil) ki AlertService bunu string
 * parse etmeden doğrudan okuyup "5 ardışık API hatası" alert'ine dahil edebilsin.
 */
class CircuitBreakerOpenException extends ProviderRequestException
{
    public function __construct(public readonly int $consecutiveFailures, string $message)
    {
        parent::__construct($message);
    }
}
