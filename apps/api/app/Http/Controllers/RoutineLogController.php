<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Models\RoutineLog;
use App\Support\CurrentUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoutineLogController extends Controller
{
    public function upsert(Request $request, Routine $routine, string $date, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        abort_unless($routine->user_id === $user->id, 404);

        $logDate = CarbonImmutable::parse($date)->toDateString();
        $data = $request->validate([
            'status' => ['required', Rule::in(['done', 'skipped'])],
            'note' => ['nullable', 'string'],
        ]);

        $log = RoutineLog::query()
            ->where('user_id', $user->id)
            ->where('routine_id', $routine->id)
            ->whereDate('log_date', $logDate)
            ->first();

        if ($log) {
            $log->update([
                'status' => $data['status'],
                'note' => $data['note'] ?? null,
                'completed_at' => $data['status'] === 'done' ? now() : null,
            ]);
        } else {
            $log = RoutineLog::create([
                'user_id' => $user->id,
                'routine_id' => $routine->id,
                'log_date' => $logDate,
                'status' => $data['status'],
                'note' => $data['note'] ?? null,
                'completed_at' => $data['status'] === 'done' ? now() : null,
            ]);
        }

        return response()->json(['data' => $log]);
    }
}
