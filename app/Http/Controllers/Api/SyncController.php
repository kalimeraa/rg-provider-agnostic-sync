<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\TriggerSyncRequest;
use App\Http\Resources\SyncLogResource;
use App\Http\Traits\ApiResponseTrait;
use App\Jobs\SyncProviderJob;
use App\Models\SyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Sync tetikleme, durum/geçmiş görüntüleme ve failed-job retry
 * endpoint'lerini barındırır. İş mantığının tamamı zaten
 * SyncProviderJob/SyncRunCoordinator'da — bu controller sadece
 * request → job/sorgu → response çevirisi yapar (ince controller).
 */
class SyncController extends Controller
{
    use ApiResponseTrait;

    /**
     * `POST /api/sync/trigger` — verilen provider için manuel bir
     * `SyncProviderJob` dispatch eder. Provider için zaten aktif bir sync
     * run'ı varsa `SyncRunCoordinator`'ın kilidi yüzünden bu dispatch
     * sessizce hiçbir şey yapmaz (bkz. o class'ın PHPDoc'u) — ama endpoint
     * yine de 202 döner, çünkü "kabul edildi/kuyruğa alınmaya çalışıldı"
     * anlamındadır; gerçek durumu `GET /api/sync/status` gösterir.
     */
    public function trigger(TriggerSyncRequest $request): JsonResponse
    {
        $provider = ProviderType::from($request->validated('provider'));

        SyncProviderJob::dispatch($provider);

        return $this->success(
            data: ['provider' => $provider->value],
            message: 'Senkronizasyon kuyruğa alındı',
            status: 202,
        );
    }

    /**
     * `GET /api/sync/status` — her iki provider için: şu an aktif bir sync
     * çalışıp çalışmadığı ve en son tamamlanan/başarısız sync bilgisi.
     *
     * "Aktif mi" bilgisi Laravel'in dahili unique-job cache kilidini
     * okumak yerine BİLEREK kendi `sync_logs` tablomuzdan çıkarılıyor
     * (`status = running` ve `completed_at IS NULL` olan bir satır var mı):
     * bu hem test edilebilir hem de framework'ün iç cache key formatına
     * (`laravel_unique_job:...`) kırılgan biçimde bağımlı olmaktan kaçınıyor.
     */
    public function status(): JsonResponse
    {
        $providers = collect(ProviderType::cases())->map(function (ProviderType $provider) {
            $isRunning = SyncLog::where('provider_type', $provider)
                ->where('status', 'running')
                ->whereNull('completed_at')
                ->exists();

            // latest('id') — latest('started_at') DEĞİL: `started_at` saniye
            // hassasiyetinde (MySQL timestamp) ve art arda hızlı çalışan iki
            // run aynı saniyeye denk gelebilir; `id` monoton ve her zaman
            // gerçek oluşturulma sırasını yansıtır (integration testleriyle
            // canlı yakalanan bir hataydı — bkz. CHANGELOG.md).
            $lastSync = SyncLog::where('provider_type', $provider)
                ->whereIn('status', ['completed', 'failed'])
                ->latest('id')
                ->first();

            return [
                'provider' => $provider->value,
                'is_running' => $isRunning,
                'last_sync' => $lastSync ? new SyncLogResource($lastSync) : null,
            ];
        });

        return $this->success(data: $providers);
    }

    /**
     * `GET /api/sync/history` — geçmiş sync loglarını en yeniden eskiye
     * doğru sayfalar. `?provider=dummyjson` ile tek bir provider'a
     * filtrelenebilir.
     */
    public function history(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        $logs = SyncLog::query()
            ->when(
                $request->query('provider'),
                fn ($query, $provider) => $query->where('provider_type', $provider)
            )
            ->latest('id') // bkz. status() metodundaki latest('id') açıklaması
            ->paginate($perPage);

        return $this->paginated(SyncLogResource::collection($logs->items()), $logs);
    }

    /**
     * `GET /api/sync/failed-jobs` — Laravel'in native `failed_jobs`
     * tablosunu (ayrı bir DLQ tablosu değil, bkz. CLAUDE.md) en yeniden
     * eskiye sayfalar. `payload`'daki `displayName` alanından okunabilir
     * bir `job_class` alanı türetilir.
     */
    public function failedJobs(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->paginate($perPage);

        $data = $jobs->getCollection()->map(function (object $job) {
            $payload = json_decode($job->payload, true);

            return [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'job_class' => $payload['displayName'] ?? null,
                'queue' => $job->queue,
                'exception' => $job->exception,
                'retry_count' => $job->retry_count,
                'failed_at' => $job->failed_at,
            ];
        });

        return $this->paginated($data, $jobs);
    }

    /**
     * `POST /api/sync/retry/{jobId}` — `{jobId}`, `failed_jobs.uuid`'dir
     * (numeric `id` DEĞİL). Bu projede `QUEUE_FAILED_DRIVER=database-uuids`
     * kullanıldığından (config/queue.php), Laravel'in failed-job
     * provider'ı (`DatabaseUuidFailedJobProvider::find()`/`forget()`)
     * job'u `uuid` koluna göre arar; `queue:retry {id}` komutunun `id`
     * argümanı da bu yüzden uuid bekler. Bu, gerçek bir failed job
     * üretilip canlı test edilerek doğrulandı — ilk yazımda numeric `id`
     * varsayılmıştı ve "Unable to find failed job" hatasıyla sessizce
     * başarısız oluyordu.
     *
     * Sıra kritik: önce `retry_count` artırılır, SONRA `queue:retry`
     * çağrılır — çünkü `queue:retry` başarılı olduğunda `failed_jobs`
     * satırını hemen siler (`forget()`); ters sırada çalıştırılsaydı
     * increment'in hedef satırı bulunamaz, sessizce hiçbir şey olmazdı.
     */
    public function retry(string $jobId): JsonResponse
    {
        $job = DB::table('failed_jobs')->where('uuid', $jobId)->first();

        if ($job === null) {
            return $this->error('JOB_NOT_FOUND', 'Belirtilen failed job bulunamadı', 404);
        }

        DB::table('failed_jobs')->where('uuid', $jobId)->increment('retry_count');

        Artisan::call('queue:retry', ['id' => [$jobId]]);

        return $this->success(message: 'Job retry için kuyruğa alındı');
    }
}
