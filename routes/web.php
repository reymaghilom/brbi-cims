<?php

use App\Http\Controllers\ActivityDefinitionController;
use App\Http\Controllers\ActivityNoteController;
use App\Http\Controllers\Admin\AdminSectionController;
use App\Http\Controllers\Admin\EvidenceStorageSettingController;
use App\Http\Controllers\Admin\ResetOperationalDataController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordResetController;
use App\Http\Controllers\Admin\UserStatusController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RequiredPasswordChangeController;
use App\Http\Controllers\BusinessCheckController;
use App\Http\Controllers\CiActivityAssetTargetController;
use App\Http\Controllers\CiActivityBankTargetController;
use App\Http\Controllers\CiActivityController;
use App\Http\Controllers\CiActivityNotificationFeedController;
use App\Http\Controllers\CiActivityNotificationReadController;
use App\Http\Controllers\CibiReportController;
use App\Http\Controllers\CibiSignatoryReassignmentController;
use App\Http\Controllers\ClientFolderAccessController;
use App\Http\Controllers\ClientFolderController;
use App\Http\Controllers\ClientFolderDeleteController;
use App\Http\Controllers\ClientFolderLiveSearchController;
use App\Http\Controllers\ClientFolderModulePlaceholderController;
use App\Http\Controllers\ClientFolderNameController;
use App\Http\Controllers\ClientFolderSuggestionController;
use App\Http\Controllers\ClientInformationController;
use App\Http\Controllers\CoMakerController;
use App\Http\Controllers\CustomBusinessCategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EditingPresenceController;
use App\Http\Controllers\GeneratedReportController;
use App\Http\Controllers\GlobalCiActivityController;
use App\Http\Controllers\IncomeSourceController;
use App\Http\Controllers\MediaReferenceController;
use App\Http\Controllers\ReportClientSuggestionController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ResidenceBusinessCheckReportController;
use App\Http\Controllers\ResidenceBusinessReportController;
use App\Http\Controllers\ResidenceCheckController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'auth.session.current'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/password/change-required', [RequiredPasswordChangeController::class, 'edit'])->name('password.change-required.edit');
    Route::put('/password/change-required', [RequiredPasswordChangeController::class, 'update'])->name('password.change-required.update');

    Route::middleware('password.changed')->group(function (): void {
        Route::get('/', DashboardController::class)->name('home');
        Route::post('/notifications/ci-activities/{notification}/read', CiActivityNotificationReadController::class)
            ->name('notifications.ci-activities.read');
        Route::get('/notifications/ci-activities/feed', CiActivityNotificationFeedController::class)
            ->name('notifications.ci-activities.feed');

        Route::post('/editing-presence/heartbeat', [EditingPresenceController::class, 'heartbeat'])->name('editing-presence.heartbeat');
        Route::post('/editing-presence/release', [EditingPresenceController::class, 'release'])->name('editing-presence.release');

        Route::get('/ci-activities', GlobalCiActivityController::class)->name('ci-activities.index');
        Route::get('/reports', ReportsController::class)->name('reports.index');
        Route::get('/reports/client-suggestions', ReportClientSuggestionController::class)
            ->middleware('throttle:60,1')
            ->name('reports.client-suggestions');
        Route::get('/client-folders', [ClientFolderAccessController::class, 'index'])->name('client-folders.index');
        Route::get('/client-folders/live-search', ClientFolderLiveSearchController::class)
            ->middleware('throttle:120,1')
            ->name('client-folders.live-search');
        Route::get('/client-folders/suggestions', ClientFolderSuggestionController::class)
            ->middleware('throttle:60,1')
            ->name('client-folders.suggestions');
        Route::get('/client-folders/create', [ClientFolderController::class, 'create'])->name('client-folders.create');
        Route::post('/client-folders', [ClientFolderController::class, 'store'])->name('client-folders.store');
        Route::get('/client-folders/{clientFolder}/edit-name', [ClientFolderNameController::class, 'edit'])->name('client-folders.edit-name');
        Route::patch('/client-folders/{clientFolder}/name', [ClientFolderNameController::class, 'update'])->name('client-folders.update-name');
        Route::delete('/client-folders/{clientFolder}', [ClientFolderDeleteController::class, 'destroy'])->name('client-folders.destroy');
        Route::get('/client-folders/{clientFolder}/client-information', [ClientInformationController::class, 'edit'])
            ->name('client-folders.client-information.edit');
        Route::put('/client-folders/{clientFolder}/client-information', [ClientInformationController::class, 'update'])
            ->name('client-folders.client-information.update');
        Route::post('/client-folders/{clientFolder}/co-maker', [CoMakerController::class, 'store'])
            ->name('client-folders.co-maker.store');
        Route::delete('/client-folders/{clientFolder}/co-maker/{coMaker}', [CoMakerController::class, 'destroy'])
            ->scopeBindings()
            ->name('client-folders.co-maker.destroy');
        Route::get('/client-folders/{clientFolder}/activities', [CiActivityController::class, 'index'])
            ->name('client-folders.activities.index');
        Route::post('/client-folders/{clientFolder}/activities', [CiActivityController::class, 'store'])
            ->name('client-folders.activities.store');
        Route::get('/client-folders/{clientFolder}/activities/{ciActivity}/bank-coop', [CiActivityBankTargetController::class, 'show'])
            ->scopeBindings()
            ->name('client-folders.activities.bank-coop.show');
        Route::post('/client-folders/{clientFolder}/activities/{ciActivity}/bank-targets', [CiActivityBankTargetController::class, 'store'])
            ->scopeBindings()
            ->name('client-folders.activities.bank-targets.store');
        Route::put('/client-folders/{clientFolder}/activities/{ciActivity}/bank-targets/{bankTarget}', [CiActivityBankTargetController::class, 'update'])
            ->scopeBindings()
            ->name('client-folders.activities.bank-targets.update');
        Route::patch('/client-folders/{clientFolder}/activities/{ciActivity}/bank-targets/complete-many', [CiActivityBankTargetController::class, 'completeMany'])
            ->scopeBindings()
            ->name('client-folders.activities.bank-targets.complete-many');
        Route::patch('/client-folders/{clientFolder}/activities/{ciActivity}/bank-targets/{bankTarget}/complete', [CiActivityBankTargetController::class, 'complete'])
            ->scopeBindings()
            ->name('client-folders.activities.bank-targets.complete');
        Route::delete('/client-folders/{clientFolder}/activities/{ciActivity}/bank-targets/{bankTarget}', [CiActivityBankTargetController::class, 'destroy'])
            ->scopeBindings()
            ->name('client-folders.activities.bank-targets.destroy');
        Route::get('/client-folders/{clientFolder}/activities/{ciActivity}/asset-check', [CiActivityAssetTargetController::class, 'show'])
            ->scopeBindings()
            ->name('client-folders.activities.asset-check.show');
        Route::get('/client-folders/{clientFolder}/activities/{ciActivity}/default-check', [CiActivityController::class, 'showDefaultCheck'])
            ->whereNumber('clientFolder')
            ->whereNumber('ciActivity')
            ->name('client-folders.activities.default-check.show');
        Route::get('/client-folders/{clientFolder}/activities/{ciActivity}/custom-check', [CiActivityController::class, 'showCustomCheck'])
            ->whereNumber('clientFolder')
            ->whereNumber('ciActivity')
            ->name('client-folders.activities.custom-check.show');
        Route::post('/client-folders/{clientFolder}/activities/{ciActivity}/asset-targets', [CiActivityAssetTargetController::class, 'store'])
            ->scopeBindings()
            ->name('client-folders.activities.asset-targets.store');
        Route::put('/client-folders/{clientFolder}/activities/{ciActivity}/asset-targets/{assetTarget}', [CiActivityAssetTargetController::class, 'update'])
            ->scopeBindings()
            ->name('client-folders.activities.asset-targets.update');
        // Static "complete-many" must be registered before the {assetTarget} wildcard below, the
        // same ordering the bank-targets routes already use.
        Route::patch('/client-folders/{clientFolder}/activities/{ciActivity}/asset-targets/complete-many', [CiActivityAssetTargetController::class, 'completeMany'])
            ->scopeBindings()
            ->name('client-folders.activities.asset-targets.complete-many');
        Route::patch('/client-folders/{clientFolder}/activities/{ciActivity}/asset-targets/{assetTarget}/complete', [CiActivityAssetTargetController::class, 'complete'])
            ->scopeBindings()
            ->name('client-folders.activities.asset-targets.complete');
        Route::delete('/client-folders/{clientFolder}/activities/{ciActivity}/asset-targets/{assetTarget}', [CiActivityAssetTargetController::class, 'destroy'])
            ->scopeBindings()
            ->name('client-folders.activities.asset-targets.destroy');
        Route::delete('/client-folders/{clientFolder}/activity-definitions/{activityDefinition}', [CiActivityController::class, 'deactivateDefinition'])
            ->name('client-folders.activity-definitions.deactivate');
        Route::put('/client-folders/{clientFolder}/activity-definitions/{activityDefinition}', [ActivityDefinitionController::class, 'update'])
            ->name('client-folders.activity-definitions.update');
        Route::patch('/client-folders/{clientFolder}/activity-definitions/{activityDefinition}/activation', [ActivityDefinitionController::class, 'activation'])
            ->name('client-folders.activity-definitions.activation');
        Route::delete('/client-folders/{clientFolder}/activity-definitions/{activityDefinition}/permanent', [ActivityDefinitionController::class, 'destroy'])
            ->name('client-folders.activity-definitions.destroy');
        // Custom "Other Business / Source of Income" checkbox options. Deliberately NOT nested under
        // an income source: these are reusable catalog definitions shared by every Business Report,
        // not rows belonging to one folder — the {clientFolder} segment is only the authorization
        // and audit context the encoding form already carries.
        Route::post('/client-folders/{clientFolder}/custom-business-categories', [CustomBusinessCategoryController::class, 'store'])
            ->name('client-folders.custom-business-categories.store');
        Route::put('/client-folders/{clientFolder}/custom-business-categories/{customBusinessCategory}', [CustomBusinessCategoryController::class, 'update'])
            ->name('client-folders.custom-business-categories.update');
        Route::delete('/client-folders/{clientFolder}/custom-business-categories/{customBusinessCategory}', [CustomBusinessCategoryController::class, 'destroy'])
            ->name('client-folders.custom-business-categories.destroy');
        Route::get('/client-folders/{clientFolder}/activities/{ciActivity}/edit', [CiActivityController::class, 'edit'])
            ->scopeBindings()
            ->name('client-folders.activities.edit');
        Route::put('/client-folders/{clientFolder}/activities/{ciActivity}', [CiActivityController::class, 'update'])
            ->scopeBindings()
            ->name('client-folders.activities.update');
        Route::patch('/client-folders/{clientFolder}/activities/{ciActivity}/submission', [CiActivityController::class, 'submit'])
            ->scopeBindings()
            ->name('client-folders.activities.submit');
        Route::patch('/client-folders/{clientFolder}/activities/submit-batch', [CiActivityController::class, 'submitBatch'])
            ->name('client-folders.activities.submit-batch');
        Route::get('/client-folders/{clientFolder}/activities/{ciActivity}/proof/{mediaReference}/content', [MediaReferenceController::class, 'activityContent'])
            ->scopeBindings()
            ->name('client-folders.activities.proof.content');
        Route::post('/client-folders/{clientFolder}/activities/{ciActivity}/proof', [CiActivityController::class, 'storeProof'])
            ->scopeBindings()
            ->name('client-folders.activities.proof.store');
        Route::put('/client-folders/{clientFolder}/activities/{ciActivity}/proof/{mediaReference}', [CiActivityController::class, 'replaceProof'])
            ->scopeBindings()
            ->name('client-folders.activities.proof.replace');
        Route::delete('/client-folders/{clientFolder}/activities/{ciActivity}/proof/{mediaReference}', [CiActivityController::class, 'removeProof'])
            ->scopeBindings()
            ->name('client-folders.activities.proof.destroy');
        Route::delete('/client-folders/{clientFolder}/activities/{ciActivity}', [CiActivityController::class, 'destroy'])
            ->scopeBindings()
            ->name('client-folders.activities.destroy');
        Route::delete('/client-folders/{clientFolder}/activities', [CiActivityController::class, 'bulkDestroy'])
            ->name('client-folders.activities.bulk-destroy');
        Route::post('/client-folders/{clientFolder}/activities/{ciActivity}/notes', [ActivityNoteController::class, 'store'])
            ->scopeBindings()
            ->name('client-folders.activities.notes.store');
        Route::get('/client-folders/{clientFolder}/cibi-report', [CibiReportController::class, 'edit'])
            ->name('client-folders.cibi-report.edit');
        Route::put('/client-folders/{clientFolder}/cibi-report', [CibiReportController::class, 'update'])
            ->name('client-folders.cibi-report.update');
        // A single record's PDF download is a link in this application — the Business Report's
        // own export-pdf is already GET — so the CI/BI one answers GET too. It was POST-only,
        // which meant the URL 404/405'd the moment it was reached as a link (a new tab, an
        // "open in new tab", a retry of the address) instead of as a form submission. POST is
        // kept alongside it so the existing Global Reports download form keeps working unchanged.
        Route::match(['GET', 'POST'], '/client-folders/{clientFolder}/cibi-report/export-pdf', [GeneratedReportController::class, 'exportCibiPdf'])
            ->name('client-folders.cibi-report.export-pdf');
        Route::post('/client-folders/{clientFolder}/cibi-report/export-excel', [GeneratedReportController::class, 'exportCibiExcel'])
            ->name('client-folders.cibi-report.export-excel');
        Route::post('/client-folders/{clientFolder}/cibi-report/{cibiReport}/reassign-signatory', [CibiSignatoryReassignmentController::class, 'store'])
            ->scopeBindings()
            ->name('client-folders.cibi-report.reassign-signatory');
        Route::get('/client-folders/{clientFolder}/cibi-report/{cibiReport}/history', [CibiSignatoryReassignmentController::class, 'history'])
            ->scopeBindings()
            ->name('client-folders.cibi-report.history');
        Route::get('/client-folders/{clientFolder}/income-sources', [IncomeSourceController::class, 'launch'])->name('client-folders.income-sources.index');
        Route::get('/client-folders/{clientFolder}/income-sources/manage', [IncomeSourceController::class, 'index'])->name('client-folders.income-sources.manage');
        Route::delete('/client-folders/{clientFolder}/income-sources/business-reports/destroy-selected', [IncomeSourceController::class, 'destroySelectedBusinessReports'])->name('client-folders.income-sources.business-report.destroy-selected');
        Route::get('/client-folders/{clientFolder}/income-sources/new', [IncomeSourceController::class, 'selectTemplate'])->name('client-folders.income-sources.select-template');
        Route::get('/client-folders/{clientFolder}/income-sources/create', [IncomeSourceController::class, 'create'])->name('client-folders.income-sources.create');
        Route::post('/client-folders/{clientFolder}/income-sources', [IncomeSourceController::class, 'store'])->name('client-folders.income-sources.store');
        Route::get('/client-folders/{clientFolder}/income-sources/{incomeSource}', [IncomeSourceController::class, 'show'])->scopeBindings()->name('client-folders.income-sources.show');
        Route::get('/client-folders/{clientFolder}/income-sources/{incomeSource}/edit', [IncomeSourceController::class, 'edit'])->scopeBindings()->name('client-folders.income-sources.edit');
        Route::post('/client-folders/{clientFolder}/income-sources/{incomeSource}/businesses', [IncomeSourceController::class, 'addBusiness'])->scopeBindings()->name('client-folders.income-sources.businesses.store');
        Route::put('/client-folders/{clientFolder}/income-sources/{incomeSource}/general', [IncomeSourceController::class, 'updateGeneral'])->scopeBindings()->name('client-folders.income-sources.general.update');
        Route::put('/client-folders/{clientFolder}/income-sources/{incomeSource}/business', [IncomeSourceController::class, 'updateBusiness'])->scopeBindings()->name('client-folders.income-sources.business.update');
        Route::put('/client-folders/{clientFolder}/income-sources/{incomeSource}/contributors', [IncomeSourceController::class, 'updateContributors'])->scopeBindings()->name('client-folders.income-sources.contributors.update');
        // Registered before the {incomeSource}/export-* routes below: those wildcard routes
        // share the exact same two-segment shape (X/export-pdf, X/export-excel), so if a
        // "batch/export-pdf" route were registered after them, Laravel's router — which tries
        // routes in registration order, not "static beats wildcard" — would match the earlier
        // {incomeSource} route first, with $incomeSource literally bound to the string "batch"
        // (and 404 on route-model binding) instead of ever reaching the batch handler.
        Route::post('/client-folders/{clientFolder}/income-sources/batch/print', [GeneratedReportController::class, 'batchPreview'])->name('client-folders.income-sources.batch-print');
        Route::post('/client-folders/{clientFolder}/income-sources/batch/export-pdf', [GeneratedReportController::class, 'batchExportPdf'])->name('client-folders.income-sources.batch-export-pdf');
        Route::post('/client-folders/{clientFolder}/income-sources/batch/export-excel', [GeneratedReportController::class, 'batchExportExcel'])->name('client-folders.income-sources.batch-export-excel');
        Route::get('/client-folders/{clientFolder}/income-sources/{incomeSource}/export-pdf', [GeneratedReportController::class, 'exportBusinessPdf'])->scopeBindings()->name('client-folders.income-sources.export-pdf');
        Route::post('/client-folders/{clientFolder}/income-sources/{incomeSource}/export-excel', [GeneratedReportController::class, 'exportBusinessExcel'])->scopeBindings()->name('client-folders.income-sources.export-excel');
        Route::delete('/client-folders/{clientFolder}/income-sources/{incomeSource}', [IncomeSourceController::class, 'destroy'])->scopeBindings()->name('client-folders.income-sources.destroy');
        Route::delete('/client-folders/{clientFolder}/income-sources/{incomeSource}/business-report', [IncomeSourceController::class, 'destroyBusinessReport'])->scopeBindings()->name('client-folders.income-sources.business-report.destroy');
        Route::get('/client-folders/{clientFolder}/residence-business-report', [ResidenceBusinessReportController::class, 'edit'])->name('client-folders.residence-business.edit');
        Route::get('/client-folders/{clientFolder}/residence-business-report/preview', [ResidenceBusinessReportController::class, 'preview'])->name('client-folders.residence-business.preview');
        // Registered before the {residenceCheck}/{businessCheck} wildcard routes below for the
        // same reason as the income-sources batch routes above: static "batch" segments must be
        // matched before a wildcard route with the same shape claims "batch" as the record id.
        Route::post('/client-folders/{clientFolder}/residence-business-checks/batch/print', [ResidenceBusinessCheckReportController::class, 'batchPreview'])->name('client-folders.residence-business-checks.batch-print');
        Route::post('/client-folders/{clientFolder}/residence-business-checks/batch/export-pdf', [ResidenceBusinessCheckReportController::class, 'batchExportPdf'])->name('client-folders.residence-business-checks.batch-export-pdf');
        Route::post('/client-folders/{clientFolder}/residence-business-checks/batch/export-docx', [ResidenceBusinessCheckReportController::class, 'batchExportDocx'])->name('client-folders.residence-business-checks.batch-export-docx');
        Route::post('/client-folders/{clientFolder}/residence-business-checks/batch/delete', [ResidenceBusinessReportController::class, 'batchDelete'])->name('client-folders.residence-business-checks.batch-delete');
        Route::get('/client-folders/{clientFolder}/residence-checks/create', [ResidenceCheckController::class, 'create'])->name('client-folders.residence-checks.create');
        Route::get('/client-folders/{clientFolder}/residence-checks/{residenceCheck}/edit', [ResidenceCheckController::class, 'edit'])->scopeBindings()->name('client-folders.residence-checks.edit');
        Route::get('/client-folders/{clientFolder}/residence-checks/{residenceCheck}/photos/{photo}', [ResidenceCheckController::class, 'photo'])->scopeBindings()->name('client-folders.residence-checks.photo');
        Route::get('/client-folders/{clientFolder}/residence-checks/{residenceCheck}/map-screenshot', [ResidenceCheckController::class, 'mapScreenshot'])->scopeBindings()->name('client-folders.residence-checks.map-screenshot');
        Route::post('/client-folders/{clientFolder}/residence-checks', [ResidenceCheckController::class, 'store'])->name('client-folders.residence-checks.store');
        Route::put('/client-folders/{clientFolder}/residence-checks/{residenceCheck}/contributors', [ResidenceCheckController::class, 'updateContributors'])->scopeBindings()->name('client-folders.residence-checks.contributors.update');
        Route::delete('/client-folders/{clientFolder}/residence-checks/{residenceCheck}', [ResidenceCheckController::class, 'destroy'])->scopeBindings()->name('client-folders.residence-checks.destroy');
        Route::get('/client-folders/{clientFolder}/business-checks/create', [BusinessCheckController::class, 'create'])->name('client-folders.business-checks.create');
        Route::get('/client-folders/{clientFolder}/business-checks/{businessCheck}/edit', [BusinessCheckController::class, 'edit'])->scopeBindings()->name('client-folders.business-checks.edit');
        Route::get('/client-folders/{clientFolder}/business-checks/{businessCheck}/photos/{photo}', [BusinessCheckController::class, 'photo'])->scopeBindings()->name('client-folders.business-checks.photo');
        Route::get('/client-folders/{clientFolder}/business-checks/{businessCheck}/map-screenshot', [BusinessCheckController::class, 'mapScreenshot'])->scopeBindings()->name('client-folders.business-checks.map-screenshot');
        Route::post('/client-folders/{clientFolder}/business-checks', [BusinessCheckController::class, 'store'])->name('client-folders.business-checks.store');
        Route::put('/client-folders/{clientFolder}/business-checks/{businessCheck}/contributors', [BusinessCheckController::class, 'updateContributors'])->scopeBindings()->name('client-folders.business-checks.contributors.update');
        Route::delete('/client-folders/{clientFolder}/business-checks/{businessCheck}', [BusinessCheckController::class, 'destroy'])->scopeBindings()->name('client-folders.business-checks.destroy');
        Route::get('/client-folders/{clientFolder}/generated-reports', [GeneratedReportController::class, 'index'])->name('client-folders.generated-reports.index');
        Route::get('/client-folders/{clientFolder}/generated-reports/preview', [GeneratedReportController::class, 'preview'])->name('client-folders.generated-reports.preview');
        Route::post('/client-folders/{clientFolder}/generated-reports', [GeneratedReportController::class, 'store'])->name('client-folders.generated-reports.store');
        Route::post('/client-folders/{clientFolder}/generated-reports/{generatedReport}/regenerate', [GeneratedReportController::class, 'regenerate'])->scopeBindings()->name('client-folders.generated-reports.regenerate');
        Route::get('/client-folders/{clientFolder}/generated-reports/{generatedReport}/download', [GeneratedReportController::class, 'download'])->scopeBindings()->name('client-folders.generated-reports.download');
        Route::get('/client-folders/{clientFolder}', [ClientFolderAccessController::class, 'show'])
            ->name('client-folders.show');
        Route::get('/client-folders/{clientFolder}/modules/{module}', ClientFolderModulePlaceholderController::class)
            ->whereIn('module', [
                'google-drive', 'telegram-history', 'attachments',
            ])
            ->name('client-folders.modules.show');

        Route::middleware('role:administrator')->prefix('admin')->name('admin.')->group(function (): void {
            Route::resource('users', UserController::class)->except(['show', 'destroy']);
            Route::patch('users/{user}/status', [UserStatusController::class, 'update'])->name('users.status.update');
            Route::post('users/{user}/reset-password', [UserPasswordResetController::class, 'store'])->name('users.password.reset');
            Route::get('settings', [AdminSectionController::class, 'settings'])->name('settings.index');
            Route::post('settings/evidence-storage', [EvidenceStorageSettingController::class, 'update'])->name('settings.evidence-storage.update');
            // Destructive, so POST only and never reachable by GET; the controller redirects
            // afterwards so a refresh cannot repeat it.
            Route::post('settings/reset-operational-data', [ResetOperationalDataController::class, 'store'])->name('settings.reset-operational-data');
            Route::get('audit-logs', [AdminSectionController::class, 'auditLogs'])->name('audit-logs.index');
            Route::get('ui-foundation', [AdminSectionController::class, 'uiFoundation'])->name('ui-foundation.show');
        });
    });
});
