<?php

namespace Tests\Unit\Jobs;

use App\Enums\ProviderType;
use App\Exceptions\Sync\CircuitBreakerOpenException;
use App\Jobs\FetchProviderPageJob;
use App\Services\Providers\ProviderFactory;
use App\Services\Sync\DeltaSyncService;
use App\Services\Sync\SyncRunCoordinator;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bir provider'ın tek bir sayfasını çekip upsert eden batch elemanı — bkz.
 * o class'ın PHPDoc'u ("Batchable", cancelled-batch kısayolu,
 * CircuitBreakerOpenException'ın retry edilmeden fail() ile işaretlenmesi).
 */
class FetchProviderPageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * Batch'teki başka bir sayfa zaten kalıcı olarak başarısız olup batch'i
     * iptal ettiyse, bu job HTTP isteği bile atmadan hiçbir şey yapmadan
     * çıkmalı — kısmi/eksik veriyle upsert yapıp yanlış sinyal üretmemeli.
     *
     * @covers \App\Jobs\FetchProviderPageJob::handle
     */
    #[Test]
    public function skipsWorkWhenItsBatchIsAlreadyCancelled(): void
    {
        $cancelledBatch = $this->createMock(Batch::class);
        $cancelledBatch->method('cancelled')->willReturn(true);

        // Batchable::batch() normalde gerçek job_batches tablosuna bakar; burada
        // davranışı izole test edebilmek için doğrudan mock'lanıyor.
        $job = $this->getMockBuilder(FetchProviderPageJob::class)
            ->setConstructorArgs([ProviderType::DummyJson, 0, now(), 1])
            ->onlyMethods(['batch'])
            ->getMock();
        $job->method('batch')->willReturn($cancelledBatch);

        $providerFactory = $this->createMock(ProviderFactory::class);
        $providerFactory->expects($this->never())->method('make');

        $job->handle($providerFactory, app(DeltaSyncService::class), app(SyncRunCoordinator::class));
    }

    /**
     * Batch iptal edilmemişse job normal akışını izler: sayfayı çeker,
     * upsert eder ve sonucu coordinator'ın paylaşımlı sayaçlarına kaydeder.
     *
     * @covers \App\Jobs\FetchProviderPageJob::handle
     */
    #[Test]
    public function upsertsPageAndRecordsResultWhenBatchIsNotCancelled(): void
    {
        Http::fake(['*' => Http::response(['products' => [
            ['id' => 1, 'title' => 'X', 'price' => 1, 'stock' => 1, 'description' => 'd'],
        ], 'total' => 1], 200)]);

        $job = new FetchProviderPageJob(ProviderType::DummyJson, 0, now(), 1);

        $job->handle(app(ProviderFactory::class), app(DeltaSyncService::class), app(SyncRunCoordinator::class));

        $this->assertDatabaseHas('products', ['provider_type' => 'dummyjson', 'external_id' => '1']);
    }

    /**
     * Circuit breaker açılınca (`CircuitBreakerOpenException`) job bunu
     * RETRY ETMEDEN `$this->fail()` ile kalıcı başarısız işaretlemeli —
     * provider zaten ayakta değilken aynı job'u tekrar denemek anlamsız.
     * Sayaç, eşiğin (varsayılan 5) bir eksiğine (4) önceden seedlenerek
     * BU isteğin tam olarak eşiği aştığı senaryo izole test ediliyor.
     *
     * @covers \App\Jobs\FetchProviderPageJob::handle
     */
    #[Test]
    public function marksJobAsPermanentlyFailedWithoutRetryWhenCircuitBreakerOpens(): void
    {
        Cache::put('throttle:consecutive-failures:dummyjson', 4, now()->addHour());
        Http::fake(['*' => Http::response(['error' => true], 500)]);

        $job = $this->getMockBuilder(FetchProviderPageJob::class)
            ->setConstructorArgs([ProviderType::DummyJson, 0, now(), 1])
            ->onlyMethods(['fail'])
            ->getMock();

        $job->expects($this->once())
            ->method('fail')
            ->with($this->isInstanceOf(CircuitBreakerOpenException::class));

        $job->handle(app(ProviderFactory::class), app(DeltaSyncService::class), app(SyncRunCoordinator::class));
    }
}
