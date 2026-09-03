<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'api',
            'timestamp' => now('UTC')->toIso8601String(),
        ]);
    }

    public function readiness(): JsonResponse
    {
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
            Storage::disk('s3')->put($objectKey, $payload);
            $objectWritten = true;

            if (Storage::disk('s3')->get($objectKey) !== $payload) {
                throw new \RuntimeException('Object storage verification failed.');
            }

            $checks['object_storage'] = 'ok';

            return response()->json([
                'status' => 'ready',
                'service' => 'api',
                'checks' => $checks,
                'timestamp' => now('UTC')->toIso8601String(),
            ]);
        } catch (Throwable) {
            $checks[$currentCheck] = 'failed';

            return response()->json([
                'status' => 'not_ready',
                'service' => 'api',
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
