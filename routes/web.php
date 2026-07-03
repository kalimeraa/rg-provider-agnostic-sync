<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Sync dashboard doğrudan kök URL'de — case'in istediği "5 dakikada ayağa
// kalkabilmeli" ruhuna uygun, ayrı bir /dashboard path'ine gerek yok.
Route::get('/', function () {
    return view('dashboard');
});
