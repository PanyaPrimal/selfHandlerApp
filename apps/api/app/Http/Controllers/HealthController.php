<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    private const UNKNOWN_RELEASE = '0000000000000000000000000000000000000000';

    public function __invoke(): JsonResponse
    {
        $configuredRelease = config('app.release');
        $releaseIsValid = is_string($configuredRelease)
            && preg_match('/\A[0-9a-f]{40}\z/', $configuredRelease) === 1;
        $release = $releaseIsValid ? $configuredRelease : self::UNKNOWN_RELEASE;

        try {
            DB::select('SELECT 1');
            $databaseIsReady = true;
        } catch (Throwable) {
            $databaseIsReady = false;
        }

        $ready = $releaseIsValid && $databaseIsReady;

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'release' => $release,
        ], $ready ? 200 : 503);
    }
}
