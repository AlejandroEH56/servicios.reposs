<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\Publications\Presentation\Http\Controllers\Api\V1\NoticeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:120,1'])->prefix('api/v1/public/notices')->group(function (): void {
    Route::get('/', [NoticeController::class, 'read'])->defaults('public', true)->name('publications.public.index');
    Route::get('{notice}', [NoticeController::class, 'read'])->whereUlid('notice')->defaults('public', true)->name('publications.public.show');
    Route::get('{notice}/images/{file}', [NoticeController::class, 'download'])->whereUlid(['notice', 'file'])->name('publications.public.image');
});
Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:60,1'])->prefix('api/v1')->group(function (): void {
    Route::get('publications/notices', [NoticeController::class, 'read'])->name('publications.index');
    Route::get('publications/notices/{notice}', [NoticeController::class, 'read'])->whereUlid('notice')->name('publications.show');
    Route::post('publications/notices', [NoticeController::class, 'write'])->defaults('operation', 'create')->name('publications.create');
    Route::patch('publications/notices/{notice}', [NoticeController::class, 'write'])->whereUlid('notice')->defaults('operation', 'update')->name('publications.update');
    foreach (['publish', 'withdraw'] as $operation) {
        Route::post('publications/notices/{notice}/'.$operation, [NoticeController::class, 'write'])->whereUlid('notice')->defaults('operation', $operation)->name('publications.'.$operation);
    }
    Route::post('files/staged', [NoticeController::class, 'write'])->middleware('throttle:10,1')->defaults('operation', 'stage')->name('publications.images.stage');
    Route::get('files/{file}/download', [NoticeController::class, 'download'])->whereUlid('file')->name('publications.images.download');
});
