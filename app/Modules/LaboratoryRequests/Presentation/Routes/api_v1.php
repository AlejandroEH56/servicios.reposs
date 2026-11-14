<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\LaboratoryRequests\Presentation\Http\Controllers\Api\V1\LaboratoryRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:120,1'])->prefix('api/v1/laboratory-requests')->name('laboratory-requests.')->group(function (): void {
    Route::post('availability-quotes', [LaboratoryRequestController::class, 'quote'])->name('quote');
    Route::get('/', [LaboratoryRequestController::class, 'read'])->name('index');
    Route::post('/', [LaboratoryRequestController::class, 'save'])->name('create');
    Route::get('{record}', [LaboratoryRequestController::class, 'read'])->whereUlid('record')->name('show');
    Route::patch('{record}', [LaboratoryRequestController::class, 'save'])->whereUlid('record')->name('update');
    Route::post('{record}/submit', [LaboratoryRequestController::class, 'submit'])->whereUlid('record')->name('submit');
});
