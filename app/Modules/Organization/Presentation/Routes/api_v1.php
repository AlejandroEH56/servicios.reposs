<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\Organization\Presentation\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:120,1'])->prefix('api/v1/organization')->group(function (): void {
    foreach (['employees', 'units', 'levels', 'advisers', 'identity-candidates'] as $collection) {
        $operation = $collection === 'identity-candidates' ? 'candidates' : $collection;
        Route::get($collection, [OrganizationController::class, 'read'])->defaults('operation', $operation)->name('organization.'.$collection);
    }
    Route::get('employees/{employee}', [OrganizationController::class, 'read'])->whereUlid('employee')->defaults('operation', 'employee')->name('organization.employee');
    foreach (['employees' => 'Employee', 'units' => 'Unit', 'levels' => 'Level'] as $collection => $entity) {
        Route::post($collection, [OrganizationController::class, 'write'])->defaults('operation', 'create'.$entity)->name('organization.'.$collection.'.create');
        $parameter = match ($entity) {
            'Employee' => 'employee', 'Unit' => 'unit', default => 'level'
        };
        Route::put($collection.'/{'.$parameter.'}', [OrganizationController::class, 'write'])->whereUlid($parameter)->defaults('operation', 'update'.$entity)->name('organization.'.$collection.'.update');
    }
    Route::post('employees/{employee}/positions', [OrganizationController::class, 'write'])->whereUlid('employee')->defaults('operation', 'assignPosition')->name('organization.positions.create');
    Route::post('employees/{employee}/positions/{position}/finish', [OrganizationController::class, 'write'])->whereUlid(['employee', 'position'])->defaults('operation', 'finishPosition')->name('organization.positions.finish');
    Route::post('employees/{employee}/degrees', [OrganizationController::class, 'write'])->whereUlid('employee')->defaults('operation', 'addDegree')->name('organization.degrees.create');
    Route::delete('employees/{employee}/degrees/{degree}', [OrganizationController::class, 'write'])->whereUlid(['employee', 'degree'])->defaults('operation', 'removeDegree')->name('organization.degrees.remove');
    Route::post('employees/{employee}/profile-sync', [OrganizationController::class, 'write'])->whereUlid('employee')->defaults('operation', 'syncProfile')->name('organization.profile-sync');
});
