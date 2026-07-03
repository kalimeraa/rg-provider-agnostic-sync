<?php

namespace App\Exceptions\Sync;

use RuntimeException;

/**
 * Tek bir provider HTTP isteği sonuçta başarısız oldu (429 backoff retry'ları
 * tükendi, ya da 2xx/404 dışında bir response geldi, ya da bağlantı hatası
 * oluştu). ThrottledHttpClient tarafından fırlatılır.
 */
class ProviderRequestException extends RuntimeException
{
}
