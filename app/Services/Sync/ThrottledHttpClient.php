<?php

namespace App\Services\Sync;

use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Exceptions\Sync\ProviderRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Tek bir tedarikçi API'sine giden istekleri sabit bir hızda aralıklandırır
 * (rate limiting), 429 (Too Many Requests) yanıtlarını exponential backoff
 * ile tekrar dener ve tek bir sync çalıştırması içinde çok fazla ardışık
 * başarısız istek olursa circuit breaker'ı devreye sokar (sync'i durdurur).
 *
 * Bilinçli olarak singleton DEĞİL: her job/provider çağrısında container
 * tarafından taze bir instance üretilir (bkz. AppServiceProvider), böylece
 * `consecutiveFailures` sayacı doğal olarak her sync çalıştırmasıyla sıfırlanır.
 */
class ThrottledHttpClient
{
    /** 429/bağlantı hatası sonrası tekrar deneme aralıkları (saniye): 1s, 2s, 4s. */
    private const BACKOFF_SECONDS = [1, 2, 4];

    /** Son isteğin gönderildiği an (microtime) — pacing hesaplaması için. */
    private float $lastRequestAt = 0.0;

    /** Bu instance ömrü boyunca art arda başarısız olan istek sayısı. */
    private int $consecutiveFailures = 0;

    /**
     * @param  int  $requestsPerSecond  Saniyede izin verilen maksimum istek sayısı (config('sync.rate_limit_per_second')).
     * @param  int  $maxConsecutiveFailures  Bu sayıya ulaşınca CircuitBreakerOpenException fırlatılır.
     */
    public function __construct(
        private readonly int $requestsPerSecond = 5,
        private readonly int $maxConsecutiveFailures = 5,
    ) {
    }

    /**
     * Rate-limit'e uyarak, gerekirse backoff ile tekrar deneyerek bir GET
     * isteği yapar ve JSON body'yi array olarak döner. 404, "kaynak yok"
     * anlamına geldiği için hata sayılmaz ve boş array döner; diğer
     * 2xx-dışı yanıtlar `registerFailure()` üzerinden exception'a dönüşür.
     *
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    public function get(string $url, array $query = []): array
    {
        $this->throttle();

        $response = $this->requestWithBackoff($url, $query);

        // 404, transport hatası değil meşru bir "kaynak bulunamadı" cevabıdır.
        if ($response->status() === 404) {
            $this->consecutiveFailures = 0;

            return [];
        }

        if ($response->failed()) {
            $this->registerFailure($url);
        }

        $this->consecutiveFailures = 0;

        return $response->json() ?? [];
    }

    /**
     * Gerekirse `usleep` ile bekleyerek istekler arasında en az
     * `1/requestsPerSecond` saniye geçmesini garanti eder.
     */
    private function throttle(): void
    {
        $minInterval = 1 / max($this->requestsPerSecond, 1);
        $elapsed = microtime(true) - $this->lastRequestAt;

        if ($this->lastRequestAt > 0 && $elapsed < $minInterval) {
            usleep((int) (($minInterval - $elapsed) * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }

    /**
     * Tek bir isteği dener; 429 yanıtı veya bağlantı hatası alırsa
     * `BACKOFF_SECONDS` sırasına göre (1s, 2s, 4s) bekleyip kendini tekrar
     * çağırır. Deneme hakkı biterse ya son (hâlâ başarısız) response'u
     * döner ya da bağlantı hatasında `registerFailure()`'ı tetikler.
     *
     * @param  array<string, mixed>  $query
     */
    private function requestWithBackoff(string $url, array $query, int $attempt = 0): Response
    {
        try {
            $response = Http::get($url, $query);
        } catch (ConnectionException $e) {
            if ($attempt >= count(self::BACKOFF_SECONDS)) {
                $this->registerFailure($url);
            }

            sleep(self::BACKOFF_SECONDS[$attempt]);

            return $this->requestWithBackoff($url, $query, $attempt + 1);
        }

        if ($response->status() === 429 && $attempt < count(self::BACKOFF_SECONDS)) {
            sleep(self::BACKOFF_SECONDS[$attempt]);

            return $this->requestWithBackoff($url, $query, $attempt + 1);
        }

        return $response;
    }

    /**
     * Ardışık başarısızlık sayacını bir artırır ve her zaman bir exception
     * fırlatır: eşik aşıldıysa `CircuitBreakerOpenException` (sync tamamen
     * durur), aksi halde `ProviderRequestException` (bu tek istek başarısız,
     * job'un kendi retry mekanizması devreye girer).
     */
    private function registerFailure(string $url): never
    {
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= $this->maxConsecutiveFailures) {
            throw new CircuitBreakerOpenException(
                "Sync aborted after {$this->consecutiveFailures} consecutive failed requests (last: {$url})"
            );
        }

        throw new ProviderRequestException("Request failed: {$url}");
    }
}
