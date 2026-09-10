<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class PlatformContextController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['scope' => 'platform']);
    }
}
