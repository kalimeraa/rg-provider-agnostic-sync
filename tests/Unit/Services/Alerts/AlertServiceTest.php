<?php

namespace Tests\Unit\Services\Alerts;

use App\Enums\ProviderType;
use App\Services\Alerts\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Case/bonus gereksinimi: alerting sisteminin 4 eşiği ve throttle'ı test
 * edilir. Bu Laravel sürümünde `Log::fake()` yok; `alerts` kanalı `single`
 * driver kullandığı için gerçek `storage/logs/alerts.log` dosyası
 * doğrudan okunup doğrulanır (her testten önce/sonra temizlenir).
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

    #[Test]
    public function esik_altinda_ardisik_sync_fail_alert_uretmez(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 3]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        $this->assertFileDoesNotExist($this->logPath);
    }

    #[Test]
    public function esige_ulasinca_ardisik_sync_fail_alert_uretir(): void
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

    #[Test]
    public function basarili_sync_ardisik_hata_sayacini_sifirlar(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 3]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncSuccess(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);

        // Sayaç sıfırlandığı için sadece 1. hata sayılır, eşiğe (3) ulaşılmamıştır.
        $this->assertFileDoesNotExist($this->logPath);
    }

    #[Test]
    public function farkli_providerlarin_ardisik_hata_sayaclari_ayridir(): void
    {
        config(['sync.alerts.consecutive_sync_failures' => 3]);

        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::DummyJson);
        $this->alerts->recordSyncFailure(ProviderType::FakeStore);

        $this->assertFileDoesNotExist($this->logPath);
    }

    #[Test]
    public function circuit_breaker_tetiklenince_esigi_gecince_alert_uretir(): void
    {
        config(['sync.alerts.consecutive_api_failures' => 5]);

        $this->alerts->recordCircuitBreakerTripped(ProviderType::DummyJson, 5);

        $payload = $this->lastAlertPayload();

        $this->assertSame('CONSECUTIVE_API_FAILURES', $payload['type']);
        $this->assertSame(5, $payload['consecutive_failures']);
    }

    #[Test]
    public function circuit_breaker_esik_altindaysa_alert_uretmez(): void
    {
        config(['sync.alerts.consecutive_api_failures' => 10]);

        $this->alerts->recordCircuitBreakerTripped(ProviderType::DummyJson, 5);

        $this->assertFileDoesNotExist($this->logPath);
    }

    #[Test]
    public function failed_job_backlog_esigi_gecince_alert_uretir(): void
    {
        config(['sync.alerts.failed_job_threshold' => 2]);

        \Illuminate\Support\Facades\DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'redis', 'queue' => 'product-sync', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()],
            ['uuid' => 'b', 'connection' => 'redis', 'queue' => 'product-sync', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()],
        ]);

        $this->alerts->checkFailedJobBacklog();

        $payload = $this->lastAlertPayload();

        $this->assertSame('FAILED_JOB_THRESHOLD', $payload['type']);
        $this->assertSame(2, $payload['failed_job_count']);
    }

    #[Test]
    public function ayni_tip_alert_throttle_penceresinde_tekrar_uretilmez(): void
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
