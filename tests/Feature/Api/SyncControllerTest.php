<?php

namespace Tests\Feature\Api;

use App\Models\SyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case gereksinimi: 7 API endpoint'i standart `{success,data,meta,message}`/
 * `{success:false,error:{code,message}}` zarfıyla çalışmalı. `QUEUE_CONNECTION=sync`
 * olduğu için (phpunit.xml) `/sync/trigger` çağrısı SyncRunCoordinator'ı
 * senkron çalıştırır — bu yüzden provider HTTP çağrıları `Http::fake()` ile
 * sahtelenir (gerçek DummyJSON/FakeStore'a gidilmez).
 */
class SyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CACHE_DRIVER=array test süreci boyunca paylaşılır — SyncRunCoordinator'ın
        // kilit/pacing durumu başka bir test dosyasına sızmasın diye.
        Cache::flush();
    }

    private function fakeProviderResponse(): void
    {
        Http::fake([
            '*dummyjson.com*' => Http::response(['products' => [
                ['id' => 1, 'title' => 'X', 'price' => 9.99, 'stock' => 5, 'description' => 'd'],
            ], 'total' => 1], 200),
            '*fakestoreapi.com*' => Http::response([
                ['id' => 1, 'title' => 'Y', 'price' => 5, 'description' => 'd'],
            ], 200),
        ]);
    }

    #[Test]
    public function trigger_gecerli_provider_ile_202_doner_ve_sync_tamamlanir(): void
    {
        $this->fakeProviderResponse();

        $response = $this->postJson('/api/sync/trigger', ['provider' => 'dummyjson']);

        $response->assertStatus(202)
            ->assertJson(['success' => true, 'data' => ['provider' => 'dummyjson']]);

        $this->assertDatabaseHas('sync_logs', ['provider_type' => 'dummyjson', 'status' => 'completed']);
        $this->assertDatabaseHas('products', ['provider_type' => 'dummyjson', 'external_id' => '1']);
    }

    #[Test]
    public function trigger_provider_eksikse_422_validation_error_doner(): void
    {
        $response = $this->postJson('/api/sync/trigger', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['error' => ['code', 'message']]);

        $this->assertSame('VALIDATION_ERROR', $response->json('error.code'));
    }

    #[Test]
    public function trigger_gecersiz_provider_ile_422_doner(): void
    {
        $response = $this->postJson('/api/sync/trigger', ['provider' => 'amazon']);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    #[Test]
    public function status_her_iki_provider_icin_veri_doner(): void
    {
        $response = $this->getJson('/api/sync/status');

        $response->assertStatus(200)->assertJson(['success' => true]);

        $providers = collect($response->json('data'))->pluck('provider');
        $this->assertTrue($providers->contains('dummyjson'));
        $this->assertTrue($providers->contains('fakestore'));
    }

    #[Test]
    public function history_pagination_meta_ile_birlikte_doner(): void
    {
        SyncLog::factory()->count(15)->create(['provider_type' => 'dummyjson']);

        $response = $this->getJson('/api/sync/history?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'meta' => ['page', 'per_page', 'total']]);

        $this->assertSame(10, $response->json('meta.per_page'));
        $this->assertSame(15, $response->json('meta.total'));
        $this->assertCount(10, $response->json('data'));
    }

    #[Test]
    public function history_provider_filtresi_calisir(): void
    {
        SyncLog::factory()->count(3)->create(['provider_type' => 'dummyjson']);
        SyncLog::factory()->count(2)->create(['provider_type' => 'fakestore']);

        $response = $this->getJson('/api/sync/history?provider=fakestore');

        $this->assertSame(2, $response->json('meta.total'));
    }

    #[Test]
    public function failed_jobs_bos_liste_doner(): void
    {
        $response = $this->getJson('/api/sync/failed-jobs');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'meta']);
    }

    #[Test]
    public function retry_olmayan_job_icin_404_doner(): void
    {
        $response = $this->postJson('/api/sync/retry/'.\Illuminate\Support\Str::uuid());

        $response->assertStatus(404)
            ->assertJson(['success' => false, 'error' => ['code' => 'JOB_NOT_FOUND']]);
    }

    #[Test]
    public function retry_var_olan_failed_job_icin_basarili_doner(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'product-sync',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\FetchProviderPageJob']),
            'exception' => 'RuntimeException: test',
            'failed_at' => now(),
            'retry_count' => 0,
        ]);

        $response = $this->postJson("/api/sync/retry/{$uuid}");

        $response->assertStatus(200)->assertJson(['success' => true]);

        // queue:retry başarılı satırı hemen siler (bkz. SyncController PHPDoc'u) —
        // retry_count'un artırıldığı, satır silinmeden ÖNCE gerçekleşmiş olmalı.
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }
}
