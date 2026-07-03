<?php

namespace Tests\Unit\Services\Sync;

use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Exceptions\Sync\ProviderRequestException;
use App\Services\Sync\ThrottledHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case gereksinimi: "Rate limiting ve throttling" + "Retry mekanizması ve
 * exponential backoff" mutlaka test edilmeli. Her testte benzersiz bir
 * `$providerKey` kullanılır (Redis/array cache'teki pacing/circuit-breaker
 * durumu paylaşımlı olduğu için, farklı testlerin birbirinin durumunu
 * kirletmemesi için).
 */
class ThrottledHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function client(int $rps = 1000, int $maxFailures = 5): ThrottledHttpClient
    {
        return new ThrottledHttpClient('test-'.uniqid(), $rps, $maxFailures);
    }

    /**
     * Aynı saniyede izin verilenden fazla istek atılırsa, istekler arasına
     * `1/rate_limit_per_second` kadar bekleme girmeli.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::get
     * @covers \App\Services\Sync\ThrottledHttpClient::throttle
     */
    #[Test]
    public function moreRequestsThanTheRateLimitPerSecondWaitBetweenCalls(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // 2 rps => istekler arasında en az 0.5s olmalı.
        $client = $this->client(rps: 2);

        $start = microtime(true);
        $client->get('https://example.test/a');
        $client->get('https://example.test/b');
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.45, $elapsed);
    }

    /**
     * Yüksek bir rate limitte istekler arasında anlamlı bir bekleme
     * OLMAMALI — throttle mekanizması hızlı isteklerde ek gecikme katmıyor.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::throttle
     */
    #[Test]
    public function highRateLimitProceedsWithoutWaiting(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $client = $this->client(rps: 1000);

        $start = microtime(true);
        $client->get('https://example.test/a');
        $client->get('https://example.test/b');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.2, $elapsed);
    }

    /**
     * Başarılı bir response'un body'si array olarak dönmeli.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::get
     */
    #[Test]
    public function successfulResponseBodyIsReturnedAsArray(): void
    {
        Http::fake(['*' => Http::response(['products' => [1, 2, 3], 'total' => 3], 200)]);

        $result = $this->client()->get('https://example.test/products');

        $this->assertSame(['products' => [1, 2, 3], 'total' => 3], $result);
    }

    /**
     * 404, hata sayılmamalı (meşru bir "kaynak yok" cevabı) — boş array
     * dönmeli, ardışık başarısızlık sayacı ARTMAMALI.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::get
     */
    #[Test]
    public function notFoundResponseIsNotCountedAsFailureAndReturnsEmptyArray(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $result = $this->client()->get('https://example.test/products/999');

        $this->assertSame([], $result);
    }

    /**
     * 429 yanıtı, `BACKOFF_SECONDS` sırasına (1s, 2s, ...) göre bekleyip
     * tekrar denenmeli; sonunda başarılı olursa sonucu dönmeli.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::requestWithBackoff
     */
    #[Test]
    public function tooManyRequestsResponseIsRetriedWithExponentialBackoff(): void
    {
        Http::fake([
            'https://example.test/rate-limited' => Http::sequence()
                ->push(['error' => 'slow down'], 429)
                ->push(['error' => 'slow down'], 429)
                ->push(['ok' => true], 200),
        ]);

        $client = $this->client(rps: 1000);

        $start = microtime(true);
        $result = $client->get('https://example.test/rate-limited');
        $elapsed = microtime(true) - $start;

        $this->assertSame(['ok' => true], $result);
        // İki 429 sonrası backoff sırası 1s + 2s = en az ~3s beklemiş olmalı.
        $this->assertGreaterThanOrEqual(2.8, $elapsed);
    }

    /**
     * 429 backoff hakları tükenirse (hâlâ 429 dönüyorsa) `ProviderRequestException`
     * fırlatılmalı.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::requestWithBackoff
     */
    #[Test]
    public function exhaustingBackoffAttemptsOnPersistentRateLimitThrowsProviderRequestException(): void
    {
        Http::fake([
            'https://example.test/always-429' => Http::sequence()
                ->push(['error' => 1], 429)
                ->push(['error' => 1], 429)
                ->push(['error' => 1], 429)
                ->push(['error' => 1], 429),
        ]);

        $this->expectException(ProviderRequestException::class);

        $this->client(rps: 1000)->get('https://example.test/always-429');
    }

    /**
     * Bağlantı hatası (`ConnectionException`) da 429 gibi backoff ile tekrar
     * denenmeli — deneme hakkı tükenmeden önce sunucu düzelirse başarılı
     * sonuç dönmeli.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::requestWithBackoff
     */
    #[Test]
    public function connectionExceptionIsRetriedWithBackoffAndCanStillSucceed(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['ok' => true], 200);
        });

        $result = $this->client(rps: 1000)->get('https://example.test/flaky-connection');

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(2, $attempts);
    }

    /**
     * Bağlantı hatası ardı ardına devam edip backoff hakları (3 deneme)
     * tükenirse `registerFailure()` tetiklenmeli — `ProviderRequestException`
     * fırlatılmalı (henüz circuit breaker eşiğine ulaşılmadıysa).
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::requestWithBackoff
     */
    #[Test]
    public function exhaustingBackoffAttemptsOnPersistentConnectionExceptionRegistersFailure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $this->expectException(ProviderRequestException::class);

        $this->client(rps: 1000, maxFailures: 5)->get('https://example.test/always-down');
    }

    /**
     * Ardışık `maxFailures` (varsayılan 5) başarısız istekten sonra circuit
     * breaker açılmalı — `CircuitBreakerOpenException` fırlatılmalı ve
     * ardışık sayıyı yapılandırılmış bir alan olarak taşımalı.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::registerFailure
     */
    #[Test]
    public function fiveConsecutiveFailuresOpenTheCircuitBreaker(): void
    {
        Http::fake(['https://example.test/broken' => Http::response(['error' => 1], 500)]);

        $client = $this->client(rps: 1000, maxFailures: 5);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $client->get('https://example.test/broken');
                $this->fail('ProviderRequestException bekleniyordu.');
            } catch (CircuitBreakerOpenException $e) {
                $this->fail("Circuit breaker {$attempt}. denemede erken açıldı.");
            } catch (ProviderRequestException $e) {
                // beklenen: henüz eşiğe ulaşılmadı
            }
        }

        try {
            $client->get('https://example.test/broken');
            $this->fail('CircuitBreakerOpenException bekleniyordu.');
        } catch (CircuitBreakerOpenException $e) {
            $this->assertSame(5, $e->consecutiveFailures);
        }
    }

    /**
     * Başarılı bir istek, ardışık hata sayacını sıfırlamalı — sayaç
     * sıfırlanmasaydı hemen ardından gelen 4 hata YANLIŞLIKLA circuit
     * breaker'ı açardı.
     *
     * @covers \App\Services\Sync\ThrottledHttpClient::get
     * @covers \App\Services\Sync\ThrottledHttpClient::registerFailure
     */
    #[Test]
    public function successfulRequestResetsConsecutiveFailureCounter(): void
    {
        Http::fake([
            'https://example.test/flaky' => Http::sequence()
                ->push(['error' => 1], 500)
                ->push(['error' => 1], 500)
                ->push(['error' => 1], 500)
                ->push(['error' => 1], 500)
                ->push(['ok' => true], 200) // sayaç burada sıfırlanır
                ->push(['error' => 1], 500)
                ->push(['error' => 1], 500)
                ->push(['error' => 1], 500)
                ->push(['error' => 1], 500),
        ]);

        $client = $this->client(rps: 1000, maxFailures: 5);

        for ($i = 0; $i < 4; $i++) {
            try {
                $client->get('https://example.test/flaky');
            } catch (ProviderRequestException) {
                // beklenen
            }
        }

        // 5. çağrı: başarılı response, sayaç sıfırlanır.
        $result = $client->get('https://example.test/flaky');
        $this->assertSame(['ok' => true], $result);

        // Sayaç sıfırlandığı için sıradaki 4 hata YENİDEN circuit breaker'ı açmamalı.
        for ($i = 0; $i < 4; $i++) {
            try {
                $client->get('https://example.test/flaky');
            } catch (CircuitBreakerOpenException) {
                $this->fail('Sayaç sıfırlanmamış — circuit breaker erken açıldı.');
            } catch (ProviderRequestException) {
                // beklenen
            }
        }
    }
}
