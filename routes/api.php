<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Http\Request;
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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Case'in istediği 7 endpoint — hepsi ApiResponseTrait zarfıyla döner.
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
