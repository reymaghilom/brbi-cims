<?php

use App\Http\Controllers\ActivityNoteController;
use App\Http\Controllers\Admin\AdminSectionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordResetController;
use App\Http\Controllers\Admin\UserStatusController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RequiredPasswordChangeController;
use App\Http\Controllers\CiActivityController;
use App\Http\Controllers\CibiReportController;
use App\Http\Controllers\ClientFolderAccessController;
use App\Http\Controllers\ClientFolderController;
use App\Http\Controllers\ClientFolderLiveSearchController;
use App\Http\Controllers\ClientFolderModulePlaceholderController;
use App\Http\Controllers\ClientFolderNameController;
use App\Http\Controllers\ClientFolderRecycleController;
use App\Http\Controllers\ClientFolderSuggestionController;
use App\Http\Controllers\ClientInformationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GeneratedReportController;
use App\Http\Controllers\IncomeSourceController;
use App\Http\Controllers\MediaReferenceController;
use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\RecycleBinPurgeController;
use App\Http\Controllers\RecycleBinRestoreController;
use App\Http\Controllers\ResidenceBusinessReportController;
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

        Route::view('/ci-activities', 'module-placeholder', ['title' => 'CI Activities'])->name('ci-activities.index');
        Route::view('/reports', 'module-placeholder', ['title' => 'Reports'])->name('reports.index');
        Route::get('/photos-videos', [MediaReferenceController::class, 'globalIndex'])->name('media.index');
        Route::view('/telegram-history', 'module-placeholder', ['title' => 'Telegram History'])->name('telegram.index');
        Route::view('/google-drive', 'module-placeholder', ['title' => 'Google Drive'])->name('drive.index');
        Route::get('/recycle-bin', RecycleBinController::class)->name('recycle-bin.index');
        Route::patch('/recycle-bin/{clientFolder}/restore', [RecycleBinRestoreController::class, 'update'])
            ->withTrashed()
            ->name('recycle-bin.restore');
        Route::delete('/recycle-bin/{clientFolder}', [RecycleBinPurgeController::class, 'destroy'])
            ->withTrashed()
            ->name('recycle-bin.destroy');

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
        Route::delete('/client-folders/{clientFolder}', [ClientFolderRecycleController::class, 'destroy'])->name('client-folders.destroy');
        Route::get('/client-folders/{clientFolder}/client-information', [ClientInformationController::class, 'edit'])
            ->name('client-folders.client-information.edit');
        Route::put('/client-folders/{clientFolder}/client-information', [ClientInformationController::class, 'update'])
            ->name('client-folders.client-information.update');
        Route::get('/client-folders/{clientFolder}/activities', [CiActivityController::class, 'index'])
            ->name('client-folders.activities.index');
        Route::get('/client-folders/{clientFolder}/activities/{ciActivity}/edit', [CiActivityController::class, 'edit'])
            ->scopeBindings()
            ->name('client-folders.activities.edit');
        Route::put('/client-folders/{clientFolder}/activities/{ciActivity}', [CiActivityController::class, 'update'])
            ->scopeBindings()
            ->name('client-folders.activities.update');
        Route::post('/client-folders/{clientFolder}/activities/{ciActivity}/notes', [ActivityNoteController::class, 'store'])
            ->scopeBindings()
            ->name('client-folders.activities.notes.store');
        Route::get('/client-folders/{clientFolder}/cibi-report', [CibiReportController::class, 'edit'])
            ->name('client-folders.cibi-report.edit');
        Route::put('/client-folders/{clientFolder}/cibi-report', [CibiReportController::class, 'update'])
            ->name('client-folders.cibi-report.update');
        Route::post('/client-folders/{clientFolder}/cibi-report/export-pdf', [GeneratedReportController::class, 'exportCibiPdf'])
            ->name('client-folders.cibi-report.export-pdf');
        Route::post('/client-folders/{clientFolder}/cibi-report/export-excel', [GeneratedReportController::class, 'exportCibiExcel'])
            ->name('client-folders.cibi-report.export-excel');
        Route::get('/client-folders/{clientFolder}/income-sources', [IncomeSourceController::class, 'launch'])->name('client-folders.income-sources.index');
        Route::get('/client-folders/{clientFolder}/income-sources/manage', [IncomeSourceController::class, 'index'])->name('client-folders.income-sources.manage');
        Route::get('/client-folders/{clientFolder}/income-sources/new', [IncomeSourceController::class, 'selectTemplate'])->name('client-folders.income-sources.select-template');
        Route::get('/client-folders/{clientFolder}/income-sources/create', [IncomeSourceController::class, 'create'])->name('client-folders.income-sources.create');
        Route::post('/client-folders/{clientFolder}/income-sources', [IncomeSourceController::class, 'store'])->name('client-folders.income-sources.store');
        Route::get('/client-folders/{clientFolder}/income-sources/{incomeSource}', [IncomeSourceController::class, 'show'])->scopeBindings()->name('client-folders.income-sources.show');
        Route::get('/client-folders/{clientFolder}/income-sources/{incomeSource}/edit', [IncomeSourceController::class, 'edit'])->scopeBindings()->name('client-folders.income-sources.edit');
        Route::post('/client-folders/{clientFolder}/income-sources/{incomeSource}/businesses', [IncomeSourceController::class, 'addBusiness'])->scopeBindings()->name('client-folders.income-sources.businesses.store');
        Route::put('/client-folders/{clientFolder}/income-sources/{incomeSource}/general', [IncomeSourceController::class, 'updateGeneral'])->scopeBindings()->name('client-folders.income-sources.general.update');
        Route::put('/client-folders/{clientFolder}/income-sources/{incomeSource}/business', [IncomeSourceController::class, 'updateBusiness'])->scopeBindings()->name('client-folders.income-sources.business.update');
        Route::delete('/client-folders/{clientFolder}/income-sources/{incomeSource}', [IncomeSourceController::class, 'destroy'])->scopeBindings()->name('client-folders.income-sources.destroy');
        Route::get('/client-folders/{clientFolder}/residence-business-report', [ResidenceBusinessReportController::class, 'edit'])->name('client-folders.residence-business.edit');
        Route::put('/client-folders/{clientFolder}/residence-business-report', [ResidenceBusinessReportController::class, 'update'])->name('client-folders.residence-business.update');
        Route::get('/client-folders/{clientFolder}/residence-business-report/preview', [ResidenceBusinessReportController::class, 'preview'])->name('client-folders.residence-business.preview');
        Route::get('/client-folders/{clientFolder}/generated-reports', [GeneratedReportController::class, 'index'])->name('client-folders.generated-reports.index');
        Route::get('/client-folders/{clientFolder}/generated-reports/preview', [GeneratedReportController::class, 'preview'])->name('client-folders.generated-reports.preview');
        Route::post('/client-folders/{clientFolder}/generated-reports', [GeneratedReportController::class, 'store'])->name('client-folders.generated-reports.store');
        Route::post('/client-folders/{clientFolder}/generated-reports/{generatedReport}/regenerate', [GeneratedReportController::class, 'regenerate'])->scopeBindings()->name('client-folders.generated-reports.regenerate');
        Route::get('/client-folders/{clientFolder}/generated-reports/{generatedReport}/download', [GeneratedReportController::class, 'download'])->scopeBindings()->name('client-folders.generated-reports.download');
        Route::get('/client-folders/{clientFolder}/media', [MediaReferenceController::class, 'index'])->name('client-folders.media.index');
        Route::post('/client-folders/{clientFolder}/media', [MediaReferenceController::class, 'store'])->name('client-folders.media.store');
        Route::patch('/client-folders/{clientFolder}/media/{mediaReference}', [MediaReferenceController::class, 'update'])->scopeBindings()->name('client-folders.media.update');
        Route::delete('/client-folders/{clientFolder}/media/{mediaReference}', [MediaReferenceController::class, 'destroy'])->scopeBindings()->name('client-folders.media.destroy');
        Route::get('/client-folders/{clientFolder}/media/{mediaReference}/content', [MediaReferenceController::class, 'content'])->scopeBindings()->name('client-folders.media.content');
        Route::get('/client-folders/{clientFolder}/media/{mediaReference}/download', [MediaReferenceController::class, 'download'])->scopeBindings()->name('client-folders.media.download');
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
            Route::get('audit-logs', [AdminSectionController::class, 'auditLogs'])->name('audit-logs.index');
            Route::get('ui-foundation', [AdminSectionController::class, 'uiFoundation'])->name('ui-foundation.show');
        });
    });
});
