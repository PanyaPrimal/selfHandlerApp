<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Models\RoutineLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RoutineLogController extends Controller
{
    public function upsert(Request $request, Routine $routine, string $date): JsonResponse
    {
        $user = $request->user();
        abort_unless($routine->isOwnedBy($user), 404);

        $logDate = $this->validatedDate($date, $user->calendarTimezone());
        $data = $request->validate([
            'status' => ['required', Rule::in(['done', 'skipped'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $completedAt = $data['status'] === 'done' ? now() : null;
        $log = RoutineLog::query()
            ->ownedBy($user)
            ->firstOrCreate([
                'user_id' => $user->id,
                'routine_id' => $routine->id,
                'log_date' => $logDate,
            ], [
                'status' => $data['status'],
                'note' => $data['note'] ?? null,
                'completed_at' => $completedAt,
            ]);

        if (! $log->wasRecentlyCreated) {
            $log->update([
                'status' => $data['status'],
                'note' => $data['note'] ?? null,
                'completed_at' => $data['status'] === 'done'
                    ? ($log->status === 'done' && $log->completed_at ? $log->completed_at : $completedAt)
                    : null,
            ]);
        }

        return response()->json(['data' => $log]);
    }

    public function clear(Request $request, Routine $routine, string $date): Response
    {
        $user = $request->user();
        abort_unless($routine->isOwnedBy($user), 404);

        RoutineLog::query()
            ->ownedBy($user)
            ->where('routine_id', $routine->id)
            ->where('log_date', $this->validatedDate($date, $user->calendarTimezone()))
            ->delete();

        return response()->noContent();
    }

    private function validatedDate(string $date, string $timezone): string
    {
        $validated = Validator::make(
            ['date' => $date],
            ['date' => ['required', 'date_format:Y-m-d']],
        )->validate();

        return CarbonImmutable::parse($validated['date'], $timezone)->toDateString();
    }
}
