<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Auth\MicrosoftAuthController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SharedFileController;
use App\Http\Middleware\RequireActiveIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::get('/portal/{path?}', PortalController::class)->where('path', '.*')->name('portal');
Route::get('/avisos', fn () => redirect('/portal/notices'))->name('notices.strangler');
Route::get('/reposs/avisos', fn () => redirect('/portal/notices'))->name('notices.legacy');
Route::get('/reposs/bolsa-trabajo', fn () => redirect('/portal/notices?type=EMPLEO'))->name('notices.employment');
Route::get('/login', fn (Request $request) => redirect(
    $request->session()->has('errors') ? '/portal/access-denied' : '/portal/login'
))->name('login');

Route::middleware(['guest', 'throttle:30,1'])->group(function () {
    Route::get('/auth/microsoft', [MicrosoftAuthController::class, 'redirect'])->name('microsoft.redirect');
    Route::get('/auth/microsoft/callback', [MicrosoftAuthController::class, 'callback'])->name('microsoft.callback');
    Route::get('/auth/entra/login', [MicrosoftAuthController::class, 'redirect'])->name('entra.login');
    Route::get('/auth/entra/callback', [MicrosoftAuthController::class, 'callback'])->name('entra.callback');
});

Route::middleware(['auth', RequireActiveIdentity::class])->group(function () {
    Route::get('/files/{file}', SharedFileController::class)->whereUlid('file')->name('files.download');
    Route::redirect('/dashboard', '/portal/session')->name('dashboard');
    Route::get('/api/v1/me', MeController::class)->name('identity.me');
});
Route::post('/auth/logout', [MicrosoftAuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::post('/auth/entra/logout', [MicrosoftAuthController::class, 'logout'])->middleware('auth')->name('entra.logout');
