<?php

namespace Tests\Unit\Jobs;

use App\Enums\ProviderType;
use App\Jobs\SyncProviderJob;
use App\Services\Alerts\AlertService;
use App\Services\Sync\SyncRunCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Scheduler'ın/API'nin dispatch ettiği ince orkestrasyon job'u — bkz. o
 * class'ın PHPDoc'u ("failed() sadece page-0 öğrenme adımının tükenmesini
 * kapsar, bir sayfa job'unun batch içi hatası DEĞİL").
 *
 * @covers \App\Jobs\SyncProviderJob
 */
class SyncProviderJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function handleDelegatesToSyncRunCoordinatorStart(): void
    {
        $coordinator = $this->createMock(SyncRunCoordinator::class);
        $coordinator->expects($this->once())
            ->method('start')
            ->with(ProviderType::FakeStore);

        (new SyncProviderJob(ProviderType::FakeStore))->handle($coordinator);
    }

    #[Test]
    public function isDispatchedOnTheProductSyncQueue(): void
    {
        $job = new SyncProviderJob(ProviderType::DummyJson);

        $this->assertSame('product-sync', $job->queue);
    }

    #[Test]
    public function allowsThreeAttemptsWithExponentialBackoff(): void
    {
        $job = new SyncProviderJob(ProviderType::DummyJson);

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 2, 4], $job->backoff);
    }

    #[Test]
    public function failedRecordsSyncFailureThroughAlertService(): void
    {
        $this->mock(AlertService::class, function ($mock) {
            $mock->shouldReceive('recordSyncFailure')->once()->with(ProviderType::DummyJson);
        });

        (new SyncProviderJob(ProviderType::DummyJson))->failed(new RuntimeException('page 0 fetch failed'));
    }
}
