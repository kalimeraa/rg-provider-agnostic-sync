<?php

namespace App\Http\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Tüm API controller'larının ortak response zarfı. Case gereksinimi:
 * başarılı response `{success, data, meta, message}`, hatalı response
 * `{success:false, error:{code, message}}` şeklinde olmalı. Hiçbir
 * controller `response()->json()`'u doğrudan çağırmamalı — bu trait'i
 * kullanmalı (bkz. CLAUDE.md "Key conventions").
 */
trait ApiResponseTrait
{
    /**
     * Başarılı bir işlemi standart zarfla döner.
     *
     * @param  mixed  $data  Response body'sinin `data` alanı.
     * @param  array<string, mixed>|null  $meta  Sayfalama gibi ek bilgiler; sayfalı endpoint'lerde zorunludur.
     */
    protected function success(mixed $data = null, ?string $message = null, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Sayfalı bir sonucu, case'in zorunlu tuttuğu
     * `meta: {page, per_page, total}` alanıyla birlikte döner.
     *
     * @param  iterable<int, mixed>  $data  Zaten istenen şekle (ör. Resource) dönüştürülmüş sayfa öğeleri.
     */
    protected function paginated(iterable $data, LengthAwarePaginator $paginator, ?string $message = null): JsonResponse
    {
        return $this->success(
            data: $data,
            message: $message,
            meta: [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        );
    }

    /**
     * Başarısız bir işlemi standart hata zarfıyla döner.
     */
    protected function error(string $code, string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
