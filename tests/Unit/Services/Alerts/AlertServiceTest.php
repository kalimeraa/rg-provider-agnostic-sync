<?php

namespace Tests\Unit\Services\Alerts;

use App\Enums\ProviderType;
use App\Services\Alerts\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case/bonus gereksinimi: alerting sisteminin 4 eşiği (ardışık sync fail,
 * failed-job backlog, ardışık API fail/circuit breaker, queue backlog) ve
 * throttle'ı test edilir. Bu Laravel sürümünde `Log::fake()` yok; `alerts`
 * kanalı `single` driver kullandığı için gerçek `storage/logs/alerts.log`
 * dosyası doğrudan okunup doğrulanır (her testten önce/sonra temizlenir).
 */
class AlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private AlertService $alerts;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alerts = app(AlertService::class);
        $this->logPath = storage_path('logs/alerts.log');

        Cache::flush();
        @unlink($this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    private function lastAlertPayload(): array
    {
        $this->assertFileExists($this->logPath, 'alerts.log oluşmamış — alert tetiklenmedi.');

        $lines = array_filter(explode("\n", file_get_contents($this->logPath)));
        $lastLine = end($lines);

        // Log satırı "[tarih] kanal.SEVIYE: {json}" formatında; sadece JSON kısmını al.
        $jsonStart = strpos($lastLine, '{');

        return json_decode(substr($lastLine, $jsonStart), true);
    }

    /**
     * Ardışık başarısızlık sayısı eşiğin ALTINDAYSA hiçbir alert üretilmemeli.
     *
     * @covers \App\Services\Alerts\AlertService::recordSyncFailure
     */
    #[Test]
    public function belowThresholdConsecutiveSyncFailuresProduceNoAlert(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 3]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        $this->assertFileDoesNotExist($this->logPath);
    }

    /**
     * Eşiğe ulaşılınca `CONSECUTIVE_SYNC_FAILURES` tipinde, doğru provider
     * ve sayaç bilgisini taşıyan bir alert üretilmeli.
     *
     * @covers \App\Services\Alerts\AlertService::recordSyncFailure
     */
    #[Test]
    public function reachingThresholdProducesConsecutiveSyncFailuresAlert(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 3]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        $payload = $this->lastAlertPayload();

        $this->assertSame('ALERT', $payload['level']);
        $this->assertSame('CONSECUTIVE_SYNC_FAILURES', $payload['type']);
        $this->assertSame('dummyjson', $payload['provider']);
        $this->assertSame(3, $payload['consecutive_failures']);
    }

    /**
     * Başarılı bir sync, ardışık hata sayacını sıfırlamalı — aralıklı
     * başarısızlıklar yanlışlıkla "ardışık" sayılmamalı.
     *
     * @covers \App\Services\Alerts\AlertService::recordSyncSuccess
     */
    #[Test]
    public function successfulSyncResetsConsecutiveFailureCounter(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 3]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncSuccess(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        // Sayaç sıfırlandığı için sadece 1. hata sayılır, eşiğe (3) ulaşılmamıştır.
        $this->assertFileDoesNotExist($this->logPath);
    }

    /**
     * İki farklı provider'ın ardışık hata sayaçları birbirinden bağımsız
     * olmalı — biri eşiğe yaklaşırken diğeri hiç etkilenmemeli.
     *
     * @covers \App\Services\Alerts\AlertService::recordSyncFailure
     */
    #[Test]
    public function differentProvidersHaveIndependentFailureCounters(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 3]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::FakeStore);

        $this->assertFileDoesNotExist($this->logPath);
    }

    /**
     * Circuit breaker'ın bildirdiği ardışık başarısızlık sayısı eşiği
     * geçince `CONSECUTIVE_API_FAILURES` alert'i üretilmeli.
     *
     * @covers \App\Services\Alerts\AlertService::recordCircuitBreakerTripped
     */
    #[Test]
    public function circuitBreakerTrippedAboveThresholdProducesAlert(): void
    {
        config(['sync.alerts.consecutive_api_failures' => 5]);

        $this->alerts->recordCircuitBreakerTripped(ProviderType::DummyJson, 5);

        $payload = $this->lastAlertPayload();

        $this->assertSame('CONSECUTIVE_API_FAILURES', $payload['type']);
        $this->assertSame(5, $payload['consecutive_failures']);
    }

    /**
     * Circuit breaker'ın bildirdiği sayı ALERT eşiğinin altındaysa
     * (config'teki alert eşiği circuit breaker'ın kendi eşiğinden farklı
     * ayarlanabildiği için) hiçbir alert üretilmemeli.
     *
     * @covers \App\Services\Alerts\AlertService::recordCircuitBreakerTripped
     */
    #[Test]
    public function circuitBreakerTrippedBelowThresholdProducesNoAlert(): void
    {
        config(['sync.alerts.consecutive_api_failures' => 10]);

        $this->alerts->recordCircuitBreakerTripped(ProviderType::DummyJson, 5);

        $this->assertFileDoesNotExist($this->logPath);
    }

    /**
     * `failed_jobs` tablosundaki toplam satır sayısı eşiği geçince
     * `FAILED_JOB_THRESHOLD` alert'i üretilmeli (provider-agnostic).
     *
     * @covers \App\Services\Alerts\AlertService::checkFailedJobBacklog
     */
    #[Test]
    public function failedJobBacklogAboveThresholdProducesAlert(): void
    {
        config(['sync.alerts.failed_job_threshold' => 2]);

        DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'redis', 'queue' => 'product-sync', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()],
            ['uuid' => 'b', 'connection' => 'redis', 'queue' => 'product-sync', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()],
        ]);

        $this->alerts->checkFailedJobBacklog();

        $payload = $this->lastAlertPayload();

        $this->assertSame('FAILED_JOB_THRESHOLD', $payload['type']);
        $this->assertSame(2, $payload['failed_job_count']);
    }

    /**
     * `product-sync` kuyruğundaki bekleyen job sayısı eşiği geçince
     * `QUEUE_BACKLOG` alert'i üretilmeli.
     *
     * @covers \App\Services\Alerts\AlertService::checkQueueBacklog
     */
    #[Test]
    public function queueBacklogAboveThresholdProducesAlert(): void
    {
        config(['sync.alerts.queue_backlog_threshold' => 100]);

        Queue::shouldReceive('size')->with('product-sync')->andReturn(150);

        $this->alerts->checkQueueBacklog();

        $payload = $this->lastAlertPayload();

        $this->assertSame('QUEUE_BACKLOG', $payload['type']);
        $this->assertSame(150, $payload['queue_size']);
    }

    /**
     * Kuyruk boyutu eşiğin altındaysa hiçbir alert üretilmemeli.
     *
     * @covers \App\Services\Alerts\AlertService::checkQueueBacklog
     */
    #[Test]
    public function queueBacklogBelowThresholdProducesNoAlert(): void
    {
        config(['sync.alerts.queue_backlog_threshold' => 100]);

        Queue::shouldReceive('size')->with('product-sync')->andReturn(10);

        $this->alerts->checkQueueBacklog();

        $this->assertFileDoesNotExist($this->logPath);
    }

    /**
     * `ALERT_SLACK_WEBHOOK_URL` yapılandırılmışsa, structured log'a EK
     * OLARAK yapılandırılan webhook'a düz bir `Http::post()` atılmalı.
     *
     * @covers \App\Services\Alerts\AlertService::recordSyncFailure
     */
    #[Test]
    public function alertAlsoPostsToSlackWebhookWhenConfigured(): void
    {
        config([
            'sync.alerts.consecutive_sync_failures' => 1,
            'sync.alerts.slack_webhook_url' => 'https://hooks.slack.test/services/T000/B000/XXX',
        ]);
        Http::fake();

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.test/services/T000/B000/XXX'
                && str_contains($request['text'], 'CONSECUTIVE_SYNC_FAILURES');
        });
    }

    /**
     * Webhook URL'i BOŞ bırakılmışsa (varsayılan) hiçbir HTTP isteği
     * atılmamalı — sadece structured log yeterli olmalı.
     *
     * @covers \App\Services\Alerts\AlertService::recordSyncFailure
     */
    #[Test]
    public function noHttpRequestIsMadeWhenSlackWebhookIsNotConfigured(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 1, 'sync.alerts.slack_webhook_url' => '']);
        Http::fake();

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        Http::assertNothingSent();
    }

    /**
     * Aynı tip+provider için throttle penceresi (varsayılan 5 dk) içinde
     * ikinci bir alert TEKRAR üretilmemeli — dosya tekrar yazılmamalı.
     *
     * @covers \App\Services\Alerts\AlertService::recordSyncFailure
     */
    #[Test]
    public function sameAlertTypeIsNotReproducedWithinThrottleWindow(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 1, 'sync.alerts.throttle_minutes' => 5]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $firstWriteTime = filemtime($this->logPath);

        clearstatcache();
        sleep(1);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        clearstatcache();
        $this->assertSame($firstWriteTime, filemtime($this->logPath), 'Throttle penceresi içinde dosya tekrar yazılmamalı.');
    }
}
