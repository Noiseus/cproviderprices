<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\EntitlementService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class SubscriptionStatusController extends Controller
{
    public function show(TenantContext $context, EntitlementService $entitlements): JsonResponse
    {
        return response()->json([
            'subscription_status' => $entitlements->statusPayload($context->organization),
        ]);
    }
}
