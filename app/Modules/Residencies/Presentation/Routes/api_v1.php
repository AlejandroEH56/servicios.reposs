<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\Residencies\Presentation\Http\Controllers\Api\V1\ResidencyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:120,1'])->prefix('api/v1/residencies')->name('residencies.')->group(function (): void {
    foreach (['me', 'identity-candidates', 'adviser-candidates'] as $kind) {
        Route::get($kind, [ResidencyController::class, 'read'])->defaults('kind', $kind)->name($kind);
    }
    foreach (['modalities', 'sectors', 'business-lines', 'programs', 'periods', 'residents', 'companies', 'projects'] as $kind) {
        $path = in_array($kind, ['modalities', 'sectors', 'business-lines'], true) ? 'catalogs/'.$kind : $kind;
        Route::get($path, [ResidencyController::class, 'read'])->defaults('kind', $kind)->name($kind.'.list');
        Route::get($path.'/{record}', [ResidencyController::class, 'read'])->whereUlid('record')->defaults('kind', $kind)->name($kind.'.get');
        Route::post($path, [ResidencyController::class, 'write'])->defaults('kind', $kind)->name($kind.'.create');
        Route::patch($path.'/{record}', [ResidencyController::class, 'write'])->whereUlid('record')->defaults('kind', $kind)->name($kind.'.update');
    }
    Route::get('companies/{parent}/contacts', [ResidencyController::class, 'read'])->whereUlid('parent')->defaults('kind', 'contacts')->name('contacts.list');
    Route::post('companies/{parent}/contacts', [ResidencyController::class, 'write'])->whereUlid('parent')->defaults('kind', 'contacts')->name('contacts.create');
    Route::patch('companies/{parent}/contacts/{record}', [ResidencyController::class, 'write'])->whereUlid('parent')->whereUlid('record')->defaults('kind', 'contacts')->name('contacts.update');
    foreach (['internal-advisers', 'external-advisers'] as $kind) {
        Route::post('projects/{parent}/'.$kind, [ResidencyController::class, 'write'])->whereUlid('parent')->defaults('kind', $kind)->name($kind.'.create');
        Route::patch('projects/{parent}/'.$kind.'/{record}', [ResidencyController::class, 'write'])->whereUlid('parent')->whereUlid('record')->defaults('kind', $kind)->name($kind.'.end');
    }
});
