<?php

use App\Http\Middleware\RequireActiveIdentity;
use App\Modules\Residencies\Presentation\Http\Controllers\Api\V1\DocumentsController;
use App\Modules\Residencies\Presentation\Http\Controllers\Api\V1\EvidenceController;
use App\Modules\Residencies\Presentation\Http\Controllers\Api\V1\ProgressController;
use App\Modules\Residencies\Presentation\Http\Controllers\Api\V1\ResidencyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:10,1'])->post('api/v1/residencies/files/staged', [EvidenceController::class, 'write'])->defaults('operation', 'stage')->name('residencies.evidence.stage');
Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:10,1'])->post('api/v1/residencies/template-resources/staged', [DocumentsController::class, 'write'])->defaults('operation', 'stage-resource')->name('residencies.templates.stage');

Route::middleware(['web', 'auth', RequireActiveIdentity::class, 'throttle:120,1'])->prefix('api/v1/residencies')->name('residencies.')->group(function (): void {
    foreach (['report-plan' => 'plan', 'release' => 'release', 'release/history' => 'release-history'] as $path => $operation) {
        Route::get('projects/{record}/'.$path, [ProgressController::class, 'read'])->whereUlid('record')->defaults('operation', $operation)->name('completion.'.$operation);
    }
    foreach (['report-plan' => 'plan-initialize', 'approve' => 'project-approve', 'reports' => 'report-submit', 'reports/reviews' => 'report-review', 'release/resolve' => 'release-resolve', 'release/reopen' => 'release-reopen'] as $path => $operation) {
        Route::post('projects/{record}/'.$path, [ProgressController::class, 'write'])->whereUlid('record')->defaults('operation', $operation)->name('completion.'.$operation);
    }
    Route::get('reports/review-queue', [ProgressController::class, 'read'])->defaults('operation', 'report-queue')->name('completion.report-queue');
    Route::post('evidence/{record}/reopen', [ProgressController::class, 'write'])->whereUlid('record')->defaults('operation', 'evidence-reopen')->name('completion.evidence-reopen');
    Route::get('evidence/{record}/reopen-history', [ProgressController::class, 'read'])->whereUlid('record')->defaults('operation', 'reopening-history')->name('completion.reopening-history');
    Route::get('document-signers', [DocumentsController::class, 'read'])->defaults('operation', 'signers')->name('documents.signers');
    Route::get('document-templates', [DocumentsController::class, 'read'])->defaults('operation', 'templates')->name('documents.templates');
    Route::get('document-templates/{record}', [DocumentsController::class, 'read'])->whereUlid('record')->defaults('operation', 'templates')->name('documents.template');
    Route::post('document-templates', [DocumentsController::class, 'write'])->defaults('operation', 'template-version')->name('documents.template-version');
    Route::get('projects/{record}/documents', [DocumentsController::class, 'read'])->whereUlid('record')->defaults('operation', 'project-documents')->name('documents.list');
    Route::post('projects/{record}/documents', [DocumentsController::class, 'write'])->whereUlid('record')->defaults('operation', 'issue')->name('documents.issue');
    Route::get('issued-documents/{record}', [DocumentsController::class, 'read'])->whereUlid('record')->defaults('operation', 'document')->name('documents.get');
    Route::get('issued-documents/{record}/download', [DocumentsController::class, 'download'])->whereUlid('record')->name('documents.download');
    Route::post('issued-documents/{record}/annul', [DocumentsController::class, 'write'])->whereUlid('record')->defaults('operation', 'annul')->name('documents.annul');
    Route::post('issued-documents/{record}/retry', [DocumentsController::class, 'write'])->whereUlid('record')->defaults('operation', 'retry')->name('documents.retry');
    Route::get('requirements', [EvidenceController::class, 'read'])->defaults('operation', 'definitions')->name('requirements.list');
    Route::get('requirements/{record}', [EvidenceController::class, 'read'])->whereUlid('record')->defaults('operation', 'definitions')->name('requirements.get');
    Route::post('requirements', [EvidenceController::class, 'write'])->defaults('operation', 'define')->name('requirements.create');
    Route::patch('requirements/{record}', [EvidenceController::class, 'write'])->whereUlid('record')->defaults('operation', 'define')->name('requirements.update');
    Route::get('files/{record}/requirements', [EvidenceController::class, 'read'])->whereUlid('record')->defaults('operation', 'requirements')->name('files.requirements');
    Route::post('evidence', [EvidenceController::class, 'write'])->defaults('operation', 'submit')->name('evidence.submit');
    Route::get('review-queue', [EvidenceController::class, 'read'])->defaults('operation', 'queue')->name('evidence.queue');
    Route::get('evidence/{record}', [EvidenceController::class, 'read'])->whereUlid('record')->defaults('operation', 'evidence')->name('evidence.get');
    Route::get('evidence/{record}/history', [EvidenceController::class, 'read'])->whereUlid('record')->defaults('operation', 'history')->name('evidence.history');
    Route::get('evidence/{record}/download', [EvidenceController::class, 'download'])->whereUlid('record')->name('evidence.download');
    Route::post('evidence/{record}/reviews', [EvidenceController::class, 'write'])->whereUlid('record')->defaults('operation', 'review')->name('evidence.review');
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
