<?php

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/health` — DB ve Redis bağlantısını gerçekten kullanarak (canlı
 * ping) kontrol eder; ikisi de sağlıklıysa 200, biri bile başarısızsa 503
 * dönmeli.
 */
class HealthControllerTest extends TestCase
{
    /**
     * Hem DB hem Redis bağlantısı sağlamsa 200 + `status:ok` dönmeli.
     *
     * @covers \App\Http\Controllers\Api\HealthController::__invoke
     */
    #[Test]
    public function returns200WhenDatabaseAndRedisAreHealthy(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['status' => 'ok', 'database' => true, 'redis' => true]]);
    }

    /**
     * Redis bağlantısı başarısız olursa `check()`'in `catch (Throwable)` dalı
     * devreye girmeli: `redis:false`, genel `status:degraded`, HTTP 503.
     *
     * @covers \App\Http\Controllers\Api\HealthController::__invoke
     * @covers \App\Http\Controllers\Api\HealthController::check
     */
    #[Test]
    public function returns503WhenRedisConnectionFails(): void
    {
        // Gerçekten var olmayan bir host'a bağlanmaya çalışıp GERÇEK bir
        // bağlantı hatası tetikliyoruz — Redis::ping()'i mock'lamak yerine.
        config([
            'database.redis.default.host' => 'redis-host-does-not-exist',
            'database.redis.default.port' => 1,
        ]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJson(['success' => true, 'data' => ['status' => 'degraded', 'database' => true, 'redis' => false]]);
    }
}
