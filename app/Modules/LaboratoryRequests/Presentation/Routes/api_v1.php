<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\LaboratoryRequests\Presentation\Http\Controllers\Api\V1\LaboratoryDecisionController;
use App\Modules\LaboratoryRequests\Presentation\Http\Controllers\Api\V1\LaboratoryRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:120,1'])->prefix('api/v1/laboratory-requests')->name('laboratory-requests.')->group(function (): void {
    Route::post('availability-quotes', [LaboratoryRequestController::class, 'quote'])->name('quote');
    Route::get('review-queue', [LaboratoryDecisionController::class, 'queue'])->name('queue');
    Route::get('workflow', [LaboratoryDecisionController::class, 'workflow'])->name('workflow-index');
    Route::get('workflow/{record}', [LaboratoryDecisionController::class, 'workflow'])->whereUlid('record')->name('workflow-show');
    Route::get('calendar', [LaboratoryDecisionController::class, 'calendar'])->name('calendar');
    Route::get('notifications', [LaboratoryDecisionController::class, 'notifications'])->name('notifications');
    Route::post('{record}/decisions', [LaboratoryDecisionController::class, 'decide'])->whereUlid('record')->name('decide');
    Route::post('{record}/cancel', [LaboratoryDecisionController::class, 'cancel'])->whereUlid('record')->name('cancel');
    Route::get('{record}/decisions', [LaboratoryDecisionController::class, 'history'])->whereUlid('record')->name('history');
    Route::get('{record}/receipt', [LaboratoryDecisionController::class, 'receipt'])->whereUlid('record')->name('receipt');
    Route::get('{record}/receipt/file', [LaboratoryDecisionController::class, 'receiptFile'])->whereUlid('record')->name('receipt-file');
    Route::get('/', [LaboratoryRequestController::class, 'read'])->name('index');
    Route::post('/', [LaboratoryRequestController::class, 'save'])->name('create');
    Route::get('{record}', [LaboratoryRequestController::class, 'read'])->whereUlid('record')->name('show');
    Route::patch('{record}', [LaboratoryRequestController::class, 'save'])->whereUlid('record')->name('update');
    Route::post('{record}/submit', [LaboratoryRequestController::class, 'submit'])->whereUlid('record')->name('submit');
});
