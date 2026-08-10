<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\ValueObjects\WeekdayCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoutineController extends Controller
{
    private const RELATIONS = ['goals', 'scheduleWeekdays'];

    public function index(Request $request): JsonResponse
    {
        $routines = Routine::query()
            ->ownedBy($request->user())
            ->with(self::RELATIONS)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $routines]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatedData($request);
        $weekdays = $this->pullWeekdays($data);

        $routine = Routine::create([...$data, 'user_id' => $user->id]);

        if ($weekdays) {
            $routine->syncWeekdays($weekdays);
        }

        return response()->json(['data' => $routine->fresh(self::RELATIONS)], 201);
    }

    public function update(Request $request, Routine $routine): JsonResponse
    {
        abort_unless($routine->isOwnedBy($request->user()), 404);

        $data = $this->validatedData($request, partial: true);
        $weekdays = $this->pullWeekdays($data);
        $switchedToDaily = ($data['schedule_type'] ?? null) === 'daily';

        $routine->update($data);

        if ($weekdays !== null) {
            $routine->syncWeekdays($weekdays);
        } elseif ($switchedToDaily) {
            $routine->syncWeekdays([]);
        }

        return response()->json(['data' => $routine->fresh(self::RELATIONS)]);
    }

    public function destroy(Request $request, Routine $routine): JsonResponse
    {
        abort_unless($routine->isOwnedBy($request->user()), 404);

        $routine->update(['is_active' => false]);

        return response()->json(status: 204);
    }

    /**
     * Take the weekday list out of the validated payload.
     *
     * Weekdays live in their own table, so they are stored through the routine
     * rather than mass-assigned. `null` means "the request said nothing".
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private function pullWeekdays(array &$data): ?array
    {
        if (! array_key_exists('weekdays', $data)) {
            return null;
        }

        $weekdays = WeekdayCode::normalizeList($data['weekdays'] ?? []);
        unset($data['weekdays']);

        return $weekdays;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'kind' => ['sometimes', Rule::in(['routine', 'sleep', 'habit'])],
            'schedule_type' => ['sometimes', Rule::in(['daily', 'weekdays'])],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => [Rule::in(WeekdayCode::values())],
            'preferred_time' => ['nullable', 'date_format:H:i'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
    }
}
