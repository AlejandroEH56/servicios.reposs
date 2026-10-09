<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Auth\MicrosoftAuthController;
use App\Http\Controllers\SharedFileController;
use App\Http\Middleware\RequireActiveIdentity;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::view('/login', 'auth.login')->name('login');

Route::middleware(['guest', 'throttle:30,1'])->group(function () {
    Route::get('/auth/microsoft', [MicrosoftAuthController::class, 'redirect'])->name('microsoft.redirect');
    Route::get('/auth/microsoft/callback', [MicrosoftAuthController::class, 'callback'])->name('microsoft.callback');
    Route::get('/auth/entra/login', [MicrosoftAuthController::class, 'redirect'])->name('entra.login');
    Route::get('/auth/entra/callback', [MicrosoftAuthController::class, 'callback'])->name('entra.callback');
});

Route::middleware(['auth', RequireActiveIdentity::class])->group(function () {
    Route::get('/files/{file}', SharedFileController::class)->whereUlid('file')->name('files.download');
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/api/v1/me', MeController::class)->name('identity.me');
});
Route::post('/auth/logout', [MicrosoftAuthController::class, 'logout'])->middleware('auth')->name('logout');
