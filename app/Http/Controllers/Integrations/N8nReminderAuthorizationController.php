<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\ClaimN8nReminderRequest;
use App\Services\Integrations\N8n\ReminderAuthorizationException;
use App\Services\Integrations\N8n\ReminderDeliveryAuthorizer;
use Illuminate\Http\JsonResponse;

class N8nReminderAuthorizationController extends Controller
{
    public function __invoke(
        ClaimN8nReminderRequest $request,
        ReminderDeliveryAuthorizer $authorizer,
    ): JsonResponse {
        try {
            $claim = $authorizer->claim($request->claimData());
        } catch (ReminderAuthorizationException $exception) {
            return response()->json([
                'result' => 'denied',
                'reason' => $exception->reason,
            ], $exception->httpStatus);
        }

        return response()->json(['result' => 'authorized'] + $claim, 201);
    }
}
