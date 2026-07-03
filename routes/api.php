<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Laravel iskeletinden gelen `auth:sanctum` + `/user` route'u BİLEREK
// kaldırıldı: bu proje hiçbir yerde Sanctum token'ı üretmiyor/tüketmiyor
// (case'in API'si tamamen açık) — kullanılmayan bir route, sadece
// Authenticate/RedirectIfAuthenticated middleware'lerini anlamsız yere
// "canlı" (route tablosunda erişilebilir) gösterirdi.

// Case'in istediği 7 endpoint + dashboard'un "Logları Sil" butonu için
// eklenen 1 ekstra endpoint — hepsi ApiResponseTrait zarfıyla döner.
Route::prefix('sync')->group(function () {
    Route::post('/trigger', [SyncController::class, 'trigger']);
    Route::get('/status', [SyncController::class, 'status']);
    Route::get('/history', [SyncController::class, 'history']);
    Route::delete('/history', [SyncController::class, 'clearHistory']);
    Route::get('/failed-jobs', [SyncController::class, 'failedJobs']);
    // {jobId} = failed_jobs.uuid (numeric id DEĞİL — bkz. SyncController::retry() PHPDoc'u).
    Route::post('/retry/{jobId}', [SyncController::class, 'retry'])->whereUuid('jobId');
});

Route::get('/products', [ProductController::class, 'index']);
Route::get('/health', HealthController::class);
