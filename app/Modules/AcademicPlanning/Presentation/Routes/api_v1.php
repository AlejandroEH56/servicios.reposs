<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\AcademicPlanning\Presentation\Http\Controllers\Api\V1\PlanningController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:120,1'])->prefix('api/v1/academic-planning')->group(function (): void {
    Route::pattern('catalog', 'careers|specialties|subjects|curricula|groups|holiday-types');
    Route::get('responsibles', [PlanningController::class, 'responsibles'])->name('planning.responsibles');
    foreach (['catalogs/{catalog}' => null, 'semesters' => 'semesters', 'laboratories' => 'laboratories', 'holidays' => 'holidays'] as $path => $kind) {
        Route::get($path, [PlanningController::class, 'read'])->defaults('kind', $kind)->name('planning.'.($kind ?? 'catalogs').'.index');
        Route::get($path.'/{record}', [PlanningController::class, 'read'])->whereUlid('record')->defaults('kind', $kind)->name('planning.'.($kind ?? 'catalogs').'.show');
        Route::post($path, [PlanningController::class, 'write'])->defaults('kind', $kind)->defaults('operation', 'create')->name('planning.'.($kind ?? 'catalogs').'.create');
        Route::patch($path.'/{record}', [PlanningController::class, 'write'])->whereUlid('record')->defaults('kind', $kind)->defaults('operation', 'update')->name('planning.'.($kind ?? 'catalogs').'.update');
    }
    foreach (['activate', 'close', 'archive'] as $action) {
        Route::post('semesters/{record}/'.$action, [PlanningController::class, 'write'])->whereUlid('record')->defaults('kind', 'semesters')->defaults('operation', $action)->name('planning.semesters.'.$action);
    }
});
