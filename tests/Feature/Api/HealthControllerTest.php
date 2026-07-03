<?php

namespace Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthControllerTest extends TestCase
{
    #[Test]
    public function health_veritabani_ve_redis_baglantisi_saglamsa_200_doner(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['status' => 'ok', 'database' => true, 'redis' => true]]);
    }
}
