<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class HealthController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'api',
            'request_id' => AssignRequestId::for($request),
            'timestamp' => now('UTC')->toIso8601String(),
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $requestId = AssignRequestId::for($request);
        $checks = [
            'database' => 'pending',
            'redis' => 'pending',
            'object_storage' => 'pending',
        ];
        $objectKey = 'health/readiness-'.Str::uuid()->toString().'.txt';
        $currentCheck = 'database';
        $objectWritten = false;

        try {
            DB::select('select 1');
            $checks['database'] = 'ok';

            $currentCheck = 'redis';
            Redis::connection()->ping();
            $checks['redis'] = 'ok';

            $currentCheck = 'object_storage';
            $payload = 'cproviderprices-readiness';
            $disk = Storage::disk('s3');

            if (! $disk->put($objectKey, $payload)) {
                throw new \RuntimeException('Object storage write failed.');
            }

            $objectWritten = true;

            if ($disk->get($objectKey) !== $payload) {
                throw new \RuntimeException('Object storage verification failed.');
            }

            if (! $disk->delete($objectKey)) {
                throw new \RuntimeException('Object storage cleanup failed.');
            }

            $objectWritten = false;
            $checks['object_storage'] = 'ok';

            return response()->json([
                'status' => 'ready',
                'service' => 'api',
                'request_id' => $requestId,
                'checks' => $checks,
                'timestamp' => now('UTC')->toIso8601String(),
            ]);
        } catch (Throwable) {
            $checks[$currentCheck] = 'failed';

            return response()->json([
                'status' => 'not_ready',
                'service' => 'api',
                'request_id' => $requestId,
                'checks' => $checks,
                'timestamp' => now('UTC')->toIso8601String(),
            ], 503);
        } finally {
            if ($objectWritten) {
                try {
                    Storage::disk('s3')->delete($objectKey);
                } catch (Throwable) {
                    // Readiness cleanup must not hide the original check result.
                }
            }
        }
    }
}
