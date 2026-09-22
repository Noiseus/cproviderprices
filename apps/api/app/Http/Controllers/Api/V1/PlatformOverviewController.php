<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlatformOrganizationQueryService;
use Illuminate\Http\JsonResponse;

final class PlatformOverviewController extends Controller
{
    public function show(PlatformOrganizationQueryService $queries): JsonResponse
    {
        return response()->json(['platform_overview' => $queries->overview()]);
    }
}
