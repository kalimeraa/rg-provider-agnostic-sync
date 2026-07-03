<?php

namespace App\Services\Alerts;

use App\Enums\ProviderType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Case'in istediği 4 alert senaryosunu kontrol eder: (1) bir provider'ın
 * ardışık N sync dispatch'i tamamen başarısız olması, (2) `failed_jobs`
 * sayısının bir eşiği geçmesi, (3) bir provider'ın tek bir sync
 * çalıştırması içinde ardışık N API isteğinin başarısız olması (circuit
 * breaker tetiklenmesi), (4) `product-sync` kuyruğundaki bekleyen job
 * sayısının bir eşiği geçmesi.
 *
 * Her alert tipi, aynı provider için `config('sync.alerts.throttle_minutes')`
 * (varsayılan 5 dakika) içinde en fazla bir kez üretilir; log her zaman
 * `storage/logs/alerts.log`'a structured JSON olarak yazılır,
 * `ALERT_SLACK_WEBHOOK_URL` set'liyse ayrıca Slack'e de gönderilir.
 */
class AlertService
{
    private const FAILURE_STREAK_CACHE_PREFIX = 'alert:sync-failure-streak:';

    private const THROTTLE_CACHE_PREFIX = 'alert:throttle:';

    /**
     * Bir provider'ın sync'i (tüm retry'ları tükenip) tamamen başarılı
     * olduğunda çağrılır: ardışık başarısızlık sayacını sıfırlar. Bu
     * olmadan, bir sync'in "her 3 çalıştırmada 1 kez başarısız olması"
     * gibi aralıklı bir durum yanlışlıkla ardışık sayılabilirdi.
     */
    public function recordSyncSuccess(ProviderType $provider): void
    {
        Cache::forget(self::FAILURE_STREAK_CACHE_PREFIX.$provider->value);
    }

    /**
     * Bir provider'ın sync'i (tüm retry'ları tükenip, `SyncProviderJob::failed()`
     * tetiklenerek) tamamen başarısız olduğunda çağrılır. Ardışık başarısızlık
     * sayacını bir artırır; eşik (`ALERT_CONSECUTIVE_SYNC_FAILURES`, varsayılan
     * 3) aşıldıysa alert üretir. Ayrıca `failed_jobs` eşiğini de kontrol eder
     * (bu da bir job'un başarısız olduğu anda büyüyen bir sayı).
     */
    public function recordSyncFailure(ProviderType $provider): void
    {
        $key = self::FAILURE_STREAK_CACHE_PREFIX.$provider->value;
        $streak = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $streak, now()->addDay());

        $threshold = (int) config('sync.alerts.consecutive_sync_failures', 3);

        if ($streak >= $threshold) {
            $this->alert('CONSECUTIVE_SYNC_FAILURES', 'critical', $provider, [
                'consecutive_failures' => $streak,
            ]);
        }

        $this->checkFailedJobBacklog();
    }

    /**
     * `ThrottledHttpClient`'ın circuit breaker'ı tetiklendiğinde
     * (`CircuitBreakerOpenException`) çağrılır — bu, tek bir sync
     * çalıştırması içinde ardışık N API isteğinin başarısız olduğu
     * anlamına gelir. Eşik `ALERT_CONSECUTIVE_API_FAILURES` (varsayılan 5)
     * ile karşılaştırılır; genelde circuit breaker'ın kendi eşiğiyle
     * (`SYNC_MAX_CONSECUTIVE_FAILURES`) aynı değere denk gelir, ama iki ayrı
     * config değeri olarak bırakıldı (biri "sync'i ne zaman durdur", diğeri
     * "ne zaman alert'e değer" — farklı ayarlanabilirler).
     */
    public function recordCircuitBreakerTripped(ProviderType $provider, int $consecutiveFailures): void
    {
        $threshold = (int) config('sync.alerts.consecutive_api_failures', 5);

        if ($consecutiveFailures >= $threshold) {
            $this->alert('CONSECUTIVE_API_FAILURES', 'critical', $provider, [
                'consecutive_failures' => $consecutiveFailures,
            ]);
        }
    }

    /**
     * `failed_jobs` tablosundaki toplam satır sayısı eşiği
     * (`ALERT_FAILED_JOB_THRESHOLD`, varsayılan 10) geçtiyse alert üretir.
     * Provider'a özgü değildir (failed_jobs provider ayrımı yapmaz).
     */
    public function checkFailedJobBacklog(): void
    {
        $count = DB::table('failed_jobs')->count();
        $threshold = (int) config('sync.alerts.failed_job_threshold', 10);

        if ($count >= $threshold) {
            $this->alert('FAILED_JOB_THRESHOLD', 'warning', null, [
                'failed_job_count' => $count,
            ]);
        }
    }

    /**
     * `product-sync` kuyruğunda bekleyen (henüz işlenmemiş) job sayısı
     * eşiği (`ALERT_QUEUE_BACKLOG_THRESHOLD`, varsayılan 100) geçtiyse
     * alert üretir.
     */
    public function checkQueueBacklog(): void
    {
        $size = Queue::size('product-sync');
        $threshold = (int) config('sync.alerts.queue_backlog_threshold', 100);

        if ($size >= $threshold) {
            $this->alert('QUEUE_BACKLOG', 'warning', null, [
                'queue_size' => $size,
            ]);
        }
    }

    /**
     * Ortak alert üretim mantığı: throttle kontrolü, structured JSON log,
     * ve (yapılandırılmışsa) Slack webhook bildirimi.
     *
     * Throttle anahtarı `{type}:{provider|global}` bazlıdır — aynı tipte
     * bir alert bir provider için throttle'lanmış olsa bile, başka bir
     * provider için hâlâ tetiklenebilir (bkz. CLAUDE.md/case: "Aynı alert
     * 5 dakikada 1 kez gönderilmeli").
     *
     * @param  array<string, mixed>  $details
     */
    private function alert(string $type, string $severity, ?ProviderType $provider, array $details): void
    {
        $throttleKey = self::THROTTLE_CACHE_PREFIX.$type.':'.($provider?->value ?? 'global');

        if (Cache::has($throttleKey)) {
            return;
        }

        $throttleMinutes = (int) config('sync.alerts.throttle_minutes', 5);
        Cache::put($throttleKey, true, now()->addMinutes($throttleMinutes));

        $payload = array_merge([
            'level' => 'ALERT',
            'type' => $type,
            'severity' => $severity,
            'timestamp' => now()->toIso8601String(),
        ], $provider ? ['provider' => $provider->value] : [], $details);

        Log::channel('alerts')->critical(json_encode($payload));

        $webhookUrl = config('sync.alerts.slack_webhook_url');

        if (! empty($webhookUrl)) {
            // Bilerek ayrı bir Notification class'ı YOK: tek, sabit bir webhook
            // URL'ine düz bir POST atmak, Laravel'in notifiable-routing
            // makinesini (queue, channel seçimi vb.) gerektirmeyecek kadar
            // basit bir senaryo — Http::fake() ile aynı şekilde test edilebilir.
            Http::post($webhookUrl, [
                'text' => sprintf('[%s] %s: %s', strtoupper($severity), $type, json_encode($details)),
            ]);
        }
    }
}
