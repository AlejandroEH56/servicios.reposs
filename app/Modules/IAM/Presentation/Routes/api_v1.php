<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\IAM\Presentation\Http\Controllers\Api\V1\AuthorizationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:120,1'])->prefix('api/v1/iam')->group(function (): void {
    Route::get('roles', [AuthorizationController::class, 'read'])->name('iam.roles');
    Route::get('permissions', [AuthorizationController::class, 'read'])->name('iam.permissions');
    Route::get('identities', [AuthorizationController::class, 'read'])->name('iam.identities');
    Route::get('identities/{identity}/roles', [AuthorizationController::class, 'read'])->whereUlid('identity')->name('iam.assignments');
    Route::post('roles', [AuthorizationController::class, 'write'])->name('iam.roles.create');
    Route::put('roles/{role}/permissions', [AuthorizationController::class, 'write'])->whereUlid('role')->name('iam.permissions.replace');
    Route::post('identities/{identity}/roles/{role}', [AuthorizationController::class, 'write'])->whereUlid(['identity', 'role'])->name('iam.roles.grant');
    Route::delete('identities/{identity}/roles/{role}', [AuthorizationController::class, 'write'])->whereUlid(['identity', 'role'])->name('iam.roles.revoke');
});
