<?php

namespace Tests\Feature\Api;

use App\Models\SyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case gereksinimi: 7 API endpoint'i (+ dashboard için eklenen 1 ekstra
 * endpoint) standart `{success,data,meta,message}`/`{success:false,error:
 * {code,message}}` zarfıyla çalışmalı. `QUEUE_CONNECTION=sync` olduğu için
 * (.env.testing) `/sync/trigger` çağrısı SyncRunCoordinator'ı senkron
 * çalıştırır — bu yüzden provider HTTP çağrıları `Http::fake()` ile
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

    /**
     * Geçerli bir `provider` ile tetiklenen sync 202 dönmeli ve
     * (QUEUE_CONNECTION=sync sayesinde bu istek içinde) gerçekten
     * tamamlanıp `sync_logs`/`products`'a yazmalı.
     *
     * @covers \App\Http\Controllers\Api\SyncController::trigger
     */
    #[Test]
    public function triggerWithValidProviderReturns202AndCompletesSyncSynchronously(): void
    {
        $this->fakeProviderResponse();

        $response = $this->postJson('/api/sync/trigger', ['provider' => 'dummyjson']);

        $response->assertStatus(202)
            ->assertJson(['success' => true, 'data' => ['provider' => 'dummyjson']]);

        $this->assertDatabaseHas('sync_logs', ['provider_type' => 'dummyjson', 'status' => 'completed']);
        $this->assertDatabaseHas('products', ['provider_type' => 'dummyjson', 'external_id' => '1']);
    }

    /**
     * `provider` alanı hiç gönderilmezse 422 + case'in standart hata
     * zarfında bir `VALIDATION_ERROR` dönmeli.
     *
     * @covers \App\Http\Controllers\Api\SyncController::trigger
     */
    #[Test]
    public function triggerWithMissingProviderReturns422ValidationError(): void
    {
        $response = $this->postJson('/api/sync/trigger', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['error' => ['code', 'message']]);

        $this->assertSame('VALIDATION_ERROR', $response->json('error.code'));
    }

    /**
     * `ProviderType` enum'unda karşılığı olmayan bir `provider` değeri de
     * 422 döndürmeli (case'in tanıdığı iki değerden biri değilse reddedilir).
     *
     * @covers \App\Http\Controllers\Api\SyncController::trigger
     */
    #[Test]
    public function triggerWithUnknownProviderReturns422Error(): void
    {
        $response = $this->postJson('/api/sync/trigger', ['provider' => 'amazon']);

        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /**
     * Her iki provider için de `is_running` ve `last_sync` alanlarını
     * içeren bir satır dönmeli — provider hiç sync edilmemiş olsa bile.
     *
     * @covers \App\Http\Controllers\Api\SyncController::status
     */
    #[Test]
    public function statusReturnsARowForEachSupportedProvider(): void
    {
        $response = $this->getJson('/api/sync/status');

        $response->assertStatus(200)->assertJson(['success' => true]);

        $providers = collect($response->json('data'))->pluck('provider');
        $this->assertTrue($providers->contains('dummyjson'));
        $this->assertTrue($providers->contains('fakestore'));
    }

    /**
     * Geçmiş `sync_logs` kayıtları, case'in zorunlu tuttuğu
     * `meta:{page,per_page,total}` ile birlikte sayfalanmalı.
     *
     * @covers \App\Http\Controllers\Api\SyncController::history
     */
    #[Test]
    public function historyReturnsRowsWithPaginationMeta(): void
    {
        SyncLog::factory()->count(15)->create(['provider_type' => 'dummyjson']);

        $response = $this->getJson('/api/sync/history?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'meta' => ['page', 'per_page', 'total']]);

        $this->assertSame(10, $response->json('meta.per_page'));
        $this->assertSame(15, $response->json('meta.total'));
        $this->assertCount(10, $response->json('data'));
    }

    /**
     * `?provider=` query parametresi geçmişi tek bir provider'a filtrelemeli.
     *
     * @covers \App\Http\Controllers\Api\SyncController::history
     */
    #[Test]
    public function historyFiltersByProviderQueryParameter(): void
    {
        SyncLog::factory()->count(3)->create(['provider_type' => 'dummyjson']);
        SyncLog::factory()->count(2)->create(['provider_type' => 'fakestore']);

        $response = $this->getJson('/api/sync/history?provider=fakestore');

        $this->assertSame(2, $response->json('meta.total'));
    }

    /**
     * `DELETE /api/sync/history`, `status != running` olan tüm satırları
     * silmeli ve silinen satır sayısını `data.deleted`'de dönmeli.
     *
     * @covers \App\Http\Controllers\Api\SyncController::clearHistory
     */
    #[Test]
    public function clearHistoryDeletesCompletedAndFailedLogs(): void
    {
        SyncLog::factory()->count(2)->create(['provider_type' => 'dummyjson', 'status' => 'completed']);
        SyncLog::factory()->create(['provider_type' => 'fakestore', 'status' => 'failed']);

        $response = $this->deleteJson('/api/sync/history');

        $response->assertStatus(200)->assertJson(['success' => true, 'data' => ['deleted' => 3]]);
        $this->assertDatabaseCount('sync_logs', 0);
    }

    /**
     * `status = running` olan bir satır (o an devam eden bir sync) `DELETE
     * /api/sync/history` ile SİLİNMEMELİ — aksi halde o run bitince
     * `SyncRunCoordinator`'ın güncellemeye çalışacağı satır kaybolurdu.
     *
     * @covers \App\Http\Controllers\Api\SyncController::clearHistory
     */
    #[Test]
    public function clearHistoryPreservesCurrentlyRunningLog(): void
    {
        $running = SyncLog::factory()->create(['provider_type' => 'dummyjson', 'status' => 'running']);
        SyncLog::factory()->create(['provider_type' => 'fakestore', 'status' => 'completed']);

        $response = $this->deleteJson('/api/sync/history');

        $response->assertStatus(200)->assertJson(['data' => ['deleted' => 1]]);
        $this->assertDatabaseHas('sync_logs', ['id' => $running->id]);
        $this->assertDatabaseCount('sync_logs', 1);
    }

    /**
     * Hiç failed job yokken boş bir liste (ama doğru zarf) dönmeli.
     *
     * @covers \App\Http\Controllers\Api\SyncController::failedJobs
     */
    #[Test]
    public function failedJobsReturnsEmptyListWhenNoneExist(): void
    {
        $response = $this->getJson('/api/sync/failed-jobs');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data', 'meta']);

        $this->assertSame([], $response->json('data'));
    }

    /**
     * Gerçek `failed_jobs` satırları, `payload.displayName`'den türetilen
     * bir `job_class` alanıyla birlikte doğru şekilde eşlenmeli.
     *
     * @covers \App\Http\Controllers\Api\SyncController::failedJobs
     */
    #[Test]
    public function failedJobsMapsRealRowsIntoReadableShape(): void
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'redis',
            'queue' => 'product-sync',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\FetchProviderPageJob']),
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
            'retry_count' => 2,
        ]);

        $response = $this->getJson('/api/sync/failed-jobs');

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('uuid', $uuid);

        $this->assertNotNull($row);
        $this->assertSame('App\\Jobs\\FetchProviderPageJob', $row['job_class']);
        $this->assertSame('product-sync', $row['queue']);
        $this->assertSame(2, $row['retry_count']);
    }

    /**
     * Var olmayan bir `uuid` ile retry denenirse 404 + `JOB_NOT_FOUND` dönmeli.
     *
     * @covers \App\Http\Controllers\Api\SyncController::retry
     */
    #[Test]
    public function retryWithUnknownUuidReturns404Error(): void
    {
        $response = $this->postJson('/api/sync/retry/'.Str::uuid());

        $response->assertStatus(404)
            ->assertJson(['success' => false, 'error' => ['code' => 'JOB_NOT_FOUND']]);
    }

    /**
     * Var olan bir failed job için retry: `retry_count` artırılıp
     * `queue:retry` çağrılmalı — bu satırı `failed_jobs`'tan siler (bkz.
     * SyncController::retry() PHPDoc'undaki sıralama açıklaması).
     *
     * @covers \App\Http\Controllers\Api\SyncController::retry
     */
    #[Test]
    public function retryWithExistingFailedJobSucceedsAndRemovesTheRow(): void
    {
        $uuid = (string) Str::uuid();

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

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }
}
