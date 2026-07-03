<?php

namespace Tests\Unit\Services\Sync;

use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Exceptions\Sync\ProviderRequestException;
use App\Services\Sync\ThrottledHttpClient;
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

    #[Test]
    public function ayni_saniyede_izin_verilenden_fazla_istek_atarsa_bekler(): void
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

    #[Test]
    public function yuksek_rate_limitte_beklemeden_hemen_devam_eder(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $client = $this->client(rps: 1000);

        $start = microtime(true);
        $client->get('https://example.test/a');
        $client->get('https://example.test/b');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.2, $elapsed);
    }

    #[Test]
    public function dogru_response_body_array_olarak_donuyor(): void
    {
        Http::fake(['*' => Http::response(['products' => [1, 2, 3], 'total' => 3], 200)]);

        $result = $this->client()->get('https://example.test/products');

        $this->assertSame(['products' => [1, 2, 3], 'total' => 3], $result);
    }

    #[Test]
    public function s_404_hata_sayilmaz_bos_array_doner(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $result = $this->client()->get('https://example.test/products/999');

        $this->assertSame([], $result);
    }

    #[Test]
    public function s_429_yanitini_exponential_backoff_ile_tekrar_dener(): void
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

    #[Test]
    public function backoff_tukenirse_provider_request_exception_firlar(): void
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

    #[Test]
    public function ardisik_5_basarisiz_istekten_sonra_circuit_breaker_acilir(): void
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

    #[Test]
    public function basarili_istek_ardisik_hata_sayacini_sifirlar(): void
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
