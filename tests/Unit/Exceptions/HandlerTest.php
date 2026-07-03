<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\Handler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * `api/*` altındaki HER hatanın (validasyon, "bulunamadı", genel HTTP hataları,
 * beklenmeyen sunucu hataları dahil) case'in standart
 * `{success:false, error:{code,message}}` zarfına çevrildiğini, web
 * isteklerinde ise Laravel'in varsayılan davranışının korunduğunu doğrular.
 * `render()` doğrudan çağrılır (gerçek bir HTTP round-trip'e gerek yok) —
 * döndürdüğü ham `JsonResponse`, Laravel'in test assertion'larını
 * kullanabilmek için `TestResponse`'a sarılır.
 */
class HandlerTest extends TestCase
{
    private function handler(): Handler
    {
        return new Handler($this->app);
    }

    private function apiRequest(): Request
    {
        return Request::create('/api/sync/status', 'GET');
    }

    private function render(Request $request, \Throwable $e): TestResponse
    {
        return TestResponse::fromBaseResponse($this->handler()->render($request, $e));
    }

    /**
     * `ValidationException`, 422 + tüm hata mesajlarının tek bir string'de
     * birleştirildiği bir `VALIDATION_ERROR` koduna çevrilmeli.
     *
     * @covers \App\Exceptions\Handler::render
     * @covers \App\Exceptions\Handler::renderApiException
     */
    #[Test]
    public function validationExceptionOnApiRouteReturns422WithFlattenedMessage(): void
    {
        $exception = ValidationException::withMessages(['provider' => ['provider alanı zorunludur.']]);

        $response = $this->render($this->apiRequest(), $exception);

        $response->assertStatus(422);
        $this->assertSame('VALIDATION_ERROR', $response->json('error.code'));
        $this->assertSame('provider alanı zorunludur.', $response->json('error.message'));
    }

    /**
     * `ModelNotFoundException`, 404 + `NOT_FOUND` koduna çevrilmeli.
     *
     * @covers \App\Exceptions\Handler::render
     * @covers \App\Exceptions\Handler::renderApiException
     */
    #[Test]
    public function modelNotFoundExceptionOnApiRouteReturns404WithNotFoundCode(): void
    {
        $response = $this->render($this->apiRequest(), new ModelNotFoundException());

        $response->assertStatus(404);
        $this->assertSame('NOT_FOUND', $response->json('error.code'));
    }

    /**
     * `NotFoundHttpException` (ör. tanımsız bir route) de aynı `NOT_FOUND`
     * koduna çevrilmeli — `ModelNotFoundException`'la aynı kullanıcı deneyimi.
     *
     * @covers \App\Exceptions\Handler::render
     * @covers \App\Exceptions\Handler::renderApiException
     */
    #[Test]
    public function httpNotFoundExceptionOnApiRouteReturns404WithNotFoundCode(): void
    {
        $response = $this->render($this->apiRequest(), new NotFoundHttpException('Route bulunamadı'));

        $response->assertStatus(404);
        $this->assertSame('NOT_FOUND', $response->json('error.code'));
    }

    /**
     * 404 DIŞINDAKİ bir `HttpExceptionInterface` (ör. 405 Method Not Allowed),
     * kendi status code'unu koruyarak genel bir `HTTP_ERROR` koduna çevrilmeli.
     *
     * @covers \App\Exceptions\Handler::render
     * @covers \App\Exceptions\Handler::renderApiException
     */
    #[Test]
    public function nonNotFoundHttpExceptionsPreserveStatusCodeWithGenericHttpErrorCode(): void
    {
        $response = $this->render($this->apiRequest(), new HttpException(405, 'Method Not Allowed'));

        $response->assertStatus(405);
        $this->assertSame('HTTP_ERROR', $response->json('error.code'));
        $this->assertSame('Method Not Allowed', $response->json('error.message'));
    }

    /**
     * Mesajı boş bir `HttpException`'da, boş string yerine genel bir
     * "İstek işlenemedi" mesajı gösterilmeli.
     *
     * @covers \App\Exceptions\Handler::renderApiException
     */
    #[Test]
    public function blankMessageHttpExceptionFallsBackToGenericMessage(): void
    {
        $response = $this->render($this->apiRequest(), new HttpException(403, ''));

        $response->assertStatus(403);
        $this->assertSame('İstek işlenemedi', $response->json('error.message'));
    }

    /**
     * Tanınmayan (hiçbir özel `instanceof` dalına uymayan) bir exception
     * her zaman 500 + `SERVER_ERROR` döner; debug modda gerçek mesaj görünür.
     *
     * @covers \App\Exceptions\Handler::renderApiException
     */
    #[Test]
    public function unhandledExceptionOnApiRouteReturns500WithDebugMessageWhenDebugEnabled(): void
    {
        config(['app.debug' => true]);

        $response = $this->render($this->apiRequest(), new RuntimeException('kritik hata'));

        $response->assertStatus(500);
        $this->assertSame('SERVER_ERROR', $response->json('error.code'));
        $this->assertSame('kritik hata', $response->json('error.message'));
    }

    /**
     * Aynı senaryo `app.debug=false` iken: gerçek exception mesajı DIŞARI
     * SIZMAMALI, yerine genel bir "Sunucu hatası oluştu" mesajı dönmeli.
     *
     * @covers \App\Exceptions\Handler::renderApiException
     */
    #[Test]
    public function unhandledExceptionOnApiRouteHidesRealMessageWhenDebugDisabled(): void
    {
        config(['app.debug' => false]);

        $response = $this->render($this->apiRequest(), new RuntimeException('sızdırılmaması gereken detay'));

        $response->assertStatus(500);
        $this->assertSame('Sunucu hatası oluştu', $response->json('error.message'));
        $this->assertStringNotContainsString('sızdırılmaması gereken detay', $response->getContent());
    }

    /**
     * `api/*` DIŞINDAKİ bir istek (ör. dashboard/web route'u), case'in
     * `{success,error}` zarfını KULLANMAMALI — Laravel'in varsayılan
     * render davranışına (`parent::render()`) düşmeli.
     *
     * @covers \App\Exceptions\Handler::render
     */
    #[Test]
    public function nonApiRequestsFallBackToDefaultLaravelRendering(): void
    {
        $webRequest = Request::create('/dashboard-does-not-exist', 'GET');

        $response = $this->render($webRequest, new NotFoundHttpException());

        $this->assertStringNotContainsString('"success":false', $response->getContent());
    }
}
