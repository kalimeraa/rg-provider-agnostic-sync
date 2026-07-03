<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * `GET /api/health` — sistemin ayakta olup olmadığını, DB ve Redis
 * bağlantılarını gerçekten kullanmaya çalışarak (canlı ping) kontrol eder.
 * Queue worker/scheduler'ın kendisi burada kontrol edilmez (bu case'in
 * kapsamı dışında bırakıldı — Horizon dashboard zaten bunu gösteriyor).
 */
class HealthController extends Controller
{
    use ApiResponseTrait;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo() !== null),
            'redis' => $this->check(function () {
                // Predis::ping() bağlantı yoksa exception fırlatır; döndürdüğü
                // Predis\Response\Status nesnesi zaten "bağlantı çalışıyor" demektir.
                Redis::connection()->ping();

                return true;
            }),
        ];

        $healthy = ! in_array(false, $checks, true);

        return $this->success(
            data: array_merge(['status' => $healthy ? 'ok' : 'degraded'], $checks),
            status: $healthy ? 200 : 503,
        );
    }

    /**
     * Verilen probu çalıştırır; herhangi bir exception fırlarsa (bağlantı
     * hatası) `false` döner — çağıran taraf bunu "sağlıksız" olarak yorumlar.
     */
    private function check(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
