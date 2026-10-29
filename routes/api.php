<?php

use App\Http\Controllers\Integrations\N8nReminderAuthorizationController;
use App\Http\Middleware\AuthenticateN8nReminderAuthorization;
use Illuminate\Support\Facades\Route;

// Phase 2A-5 n8n -> Laravel claim API. Not used by the selected Phase 2A-6 one-way architecture
// (docs/n8n/phase-2a-6/README.md); it answers 404 unless N8N_REMINDER_AUTHORIZATION_ENABLED=true.

Route::post('/integrations/n8n/reminder-authorizations/claim', N8nReminderAuthorizationController::class)
    ->middleware(['throttle:30,1', AuthenticateN8nReminderAuthorization::class])
    ->name('integrations.n8n.reminder-authorizations.claim');
