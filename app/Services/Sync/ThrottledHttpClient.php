<?php

namespace App\Services\Sync;

use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Exceptions\Sync\ProviderRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Tek bir tedarikçi API'sine giden istekleri sabit bir hızda aralıklandırır
 * (rate limiting), 429 (Too Many Requests) yanıtlarını exponential backoff
 * ile tekrar dener ve tek bir sync çalıştırması içinde çok fazla ardışık
 * başarısız istek olursa circuit breaker'ı devreye sokar (sync'i durdurur).
 *
 * **Paylaşımlı/dağıtık durum (Redis üzerinden), instance-local DEĞİL:**
 * `SyncRunCoordinator` bir provider'ın senkronizasyonunu artık TEK bir job
 * içinde değil, `Bus::batch()` ile paralel çalışan birden çok
 * `FetchProviderPageJob`'a bölerek yürütüyor — bu job'lar aynı anda farklı
 * worker process'lerinde çalışabilir. Pacing (rate limit) ve ardışık
 * başarısızlık sayacı bu yüzden `$providerKey`'e göre Redis'te (Cache
 * facade üzerinden) tutulur; her worker'ın kendi içinde ayrı ayrı 5rps
 * uygulaması (ve toplamda limit'i aşması) yerine, TÜM worker'lar birlikte
 * tek bir paylaşımlı bütçeye uyar. Aynı sebeple ardışık başarısızlık sayacı
 * da paylaşılır: "bu sync çalıştırması boyunca, hangi worker'da olursa
 * olsun, ardışık N istek başarısız oldu mu" sorusunu doğru cevaplayabilmek
 * için.
 */
class ThrottledHttpClient
{
    /** 429/bağlantı hatası sonrası tekrar deneme aralıkları (saniye): 1s, 2s, 4s. */
    private const BACKOFF_SECONDS = [1, 2, 4];

    private const PACING_STATE_PREFIX = 'throttle:next-allowed:';

    private const PACING_LOCK_PREFIX = 'throttle:pacing-lock:';

    private const FAILURE_COUNT_PREFIX = 'throttle:consecutive-failures:';

    private const FAILURE_LOCK_PREFIX = 'throttle:failure-lock:';

    /**
     * @param  string  $providerKey  Pacing/circuit-breaker durumunun paylaşıldığı anahtar — `ProviderType::value` (ör. "dummyjson"). İki farklı provider birbirinin rate limit'ini/sayacını ASLA etkilemez.
     * @param  int  $requestsPerSecond  Saniyede izin verilen maksimum istek sayısı (config('sync.rate_limit_per_second')).
     * @param  int  $maxConsecutiveFailures  Bu sayıya ulaşınca CircuitBreakerOpenException fırlatılır.
     */
    public function __construct(
        private readonly string $providerKey,
        private readonly int $requestsPerSecond = 5,
        private readonly int $maxConsecutiveFailures = 5,
    ) {
    }

    /**
     * Bir provider için paylaşımlı ardışık-başarısızlık sayacını sıfırlar.
     * `SyncRunCoordinator`, her yeni sync çalıştırması BAŞLARKEN bunu
     * çağırır — önceki bir çalıştırmadan (ya da yarıda kalmış bir worker
     * çökmesinden) kalma bir sayının yeni çalıştırmaya sızmaması için.
     */
    public static function resetConsecutiveFailures(string $providerKey): void
    {
        Cache::forget(self::FAILURE_COUNT_PREFIX.$providerKey);
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
            $this->resetFailures();

            return [];
        }

        if ($response->failed()) {
            $this->registerFailure($url);
        }

        $this->resetFailures();

        return $response->json() ?? [];
    }

    /**
     * Paylaşımlı "bir sonraki isteğe izin verilen an" damgasını Redis'te
     * atomik olarak (Cache::lock ile korunan bir read-modify-write) günceller
     * ve gerekirse `usleep` ile bekler. Birden çok worker aynı anda çağırsa
     * bile aralarında en az `1/requestsPerSecond` saniye kalmasını garanti eder.
     */
    private function throttle(): void
    {
        $minInterval = 1 / max($this->requestsPerSecond, 1);
        $stateKey = self::PACING_STATE_PREFIX.$this->providerKey;

        $waitSeconds = Cache::lock(self::PACING_LOCK_PREFIX.$this->providerKey, 5)
            ->block(5, function () use ($stateKey, $minInterval) {
                $now = microtime(true);
                $nextAllowed = (float) Cache::get($stateKey, 0.0);
                $startAt = max($now, $nextAllowed);

                Cache::put($stateKey, $startAt + $minInterval, now()->addMinutes(2));

                return max(0.0, $startAt - $now);
            });

        if ($waitSeconds > 0) {
            usleep((int) ($waitSeconds * 1_000_000));
        }
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
     * Paylaşımlı ardışık-başarısızlık sayacını bir artırır ve her zaman bir
     * exception fırlatır: eşik aşıldıysa `CircuitBreakerOpenException` (sync
     * tamamen durur — batch'in diğer sayfa job'ları da iptal edilir), aksi
     * halde `ProviderRequestException` (bu tek istek/sayfa başarısız, o
     * sayfa job'unun kendi retry mekanizması devreye girer).
     */
    private function registerFailure(string $url): never
    {
        $key = self::FAILURE_COUNT_PREFIX.$this->providerKey;

        $count = Cache::lock(self::FAILURE_LOCK_PREFIX.$this->providerKey, 5)
            ->block(5, function () use ($key) {
                $count = (int) Cache::get($key, 0) + 1;
                Cache::put($key, $count, now()->addHour());

                return $count;
            });

        if ($count >= $this->maxConsecutiveFailures) {
            throw new CircuitBreakerOpenException(
                $count,
                "Sync aborted after {$count} consecutive failed requests (last: {$url})"
            );
        }

        throw new ProviderRequestException("Request failed: {$url}");
    }

    private function resetFailures(): void
    {
        Cache::forget(self::FAILURE_COUNT_PREFIX.$this->providerKey);
    }
}
