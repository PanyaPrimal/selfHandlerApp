<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mentor\ChatGptBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatGptController extends Controller
{
    public function __construct(private readonly ChatGptBridge $bridge) {}

    public function status(Request $request): JsonResponse
    {
        if (! $this->bridge->available()) {
            return response()->json(['data' => ['available' => false, 'connected' => false]])->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => ['available' => true, ...$this->bridge->request($request->user()->id, 'status')]])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request): JsonResponse
    {
        abort_unless($request->all() === [], 422);

        return response()->json(['data' => $this->bridge->request($request->user()->id, 'login')])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        abort_unless($request->all() === [], 422);
        $this->bridge->request($request->user()->id, 'logout');
        DB::transaction(function () use ($request): void {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('mentor_preferences')->where('user_id', $request->user()->id)->where('auth_mode', 'chatgpt')
                ->update(['enabled' => false, 'updated_at' => now()]);
        });

        return response()->json(['data' => ['connected' => false]])->header('Cache-Control', 'no-store');
    }

    public function models(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->bridge->request($request->user()->id, 'models')])->header('Cache-Control', 'no-store');
    }
}
