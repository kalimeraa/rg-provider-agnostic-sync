<?php

namespace App\Exceptions;

use App\Http\Traits\ApiResponseTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * `api/*` altındaki tüm route'larda, hangi exception fırlarsa fırlasın
 * response'un case'in standart `{success:false, error:{code,message}}`
 * zarfında çıkmasını garanti eder (validasyon hataları, 404, 500 dahil).
 * Bu olmadan Laravel'in varsayılan JSON hata formatı
 * (`{"message":..., "errors": {...}}`) sızar ve API tutarsız görünür.
 */
class Handler extends ExceptionHandler
{
    use ApiResponseTrait;

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * API isteklerinde standart hata zarfına yönlendirir; diğer (web)
     * isteklerde Laravel'in varsayılan render davranışını korur.
     */
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            return $this->renderApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * En sık karşılaşılan exception tiplerini (validasyon, "bulunamadı",
     * genel HTTP hataları) anlamlı bir `code` ile standart zarfa çevirir;
     * tanınmayan her şey 500 + genel bir mesajla döner (debug modda gerçek
     * mesaj, production'da genel bir mesaj gösterilir).
     */
    private function renderApiException(Request $request, Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            $message = collect($e->errors())->flatten()->implode(' ');

            return $this->error('VALIDATION_ERROR', $message, 422);
        }

        if ($e instanceof ModelNotFoundException) {
            return $this->error('NOT_FOUND', 'İstenen kaynak bulunamadı', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = $status === 404 ? 'NOT_FOUND' : 'HTTP_ERROR';

            return $this->error($code, $e->getMessage() ?: 'İstek işlenemedi', $status);
        }

        $message = config('app.debug') ? $e->getMessage() : 'Sunucu hatası oluştu';

        return $this->error('SERVER_ERROR', $message, 500);
    }
}
