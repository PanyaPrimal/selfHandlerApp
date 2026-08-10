<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\ValueObjects\WeekdayCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

class RoutineController extends Controller
{
    private const RELATIONS = ['goals', 'scheduleWeekdays'];

    public function index(Request $request): JsonResponse
    {
        $routines = Routine::query()
            ->ownedBy($request->user())
            ->where('is_archived', $request->boolean('archived'))
            ->with(self::RELATIONS)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $routines]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatedData($request);
        $weekdays = $this->pullWeekdays($data);

        $routine = DB::transaction(function () use ($data, $user, $weekdays): Routine {
            $routine = Routine::create([
                ...$data,
                'user_id' => $user->id,
                'archived_at' => ($data['is_archived'] ?? false) ? now() : null,
            ]);

            $routine->syncWeekdays($weekdays ?? []);

            return $routine;
        });

        return response()->json(['data' => $routine->fresh(self::RELATIONS)], 201);
    }

    public function update(Request $request, Routine $routine): JsonResponse
    {
        abort_unless($routine->isOwnedBy($request->user()), 404);

        $data = $this->validatedData($request, partial: true, routine: $routine);
        $weekdays = $this->pullWeekdays($data);
        $switchedToDaily = ($data['schedule_type'] ?? null) === 'daily';

        if (array_key_exists('is_archived', $data) && $data['is_archived'] !== $routine->is_archived) {
            $data['archived_at'] = $data['is_archived'] ? now() : null;
        }

        DB::transaction(function () use ($data, $routine, $switchedToDaily, $weekdays): void {
            $routine->update($data);

            if ($weekdays !== null) {
                $routine->syncWeekdays($weekdays);
            } elseif ($switchedToDaily) {
                $routine->syncWeekdays([]);
            }
        });

        return response()->json(['data' => $routine->fresh(self::RELATIONS)]);
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
    private function validatedData(
        Request $request,
        bool $partial = false,
        ?Routine $routine = null,
    ): array {
        $required = $partial ? 'sometimes' : 'required';
        $effectiveScheduleType = $request->input('schedule_type', $routine?->schedule_type);
        $requiresWeekdays = ! $partial
            ? $effectiveScheduleType === 'weekdays'
            : $request->has('schedule_type')
                && $effectiveScheduleType === 'weekdays'
                && $routine?->schedule_type !== 'weekdays';

        $validator = Validator::make($request->all(), [
            'name' => [$required, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => ['sometimes', Rule::in(['routine', 'sleep', 'habit'])],
            'schedule_type' => [$required, Rule::in(['daily', 'weekdays'])],
            'weekdays' => [Rule::requiredIf($requiresWeekdays), 'array', 'min:1'],
            'weekdays.*' => ['distinct', Rule::in(WeekdayCode::values())],
            'preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $validator->after(function (LaravelValidator $validator) use (
            $effectiveScheduleType,
            $partial,
            $request,
            $routine,
        ): void {
            if ($request->has('weekdays') && $effectiveScheduleType !== 'weekdays') {
                $validator->errors()->add('weekdays', 'Weekdays are only allowed for a weekday schedule.');
            }

            $startsOn = $request->exists('starts_on')
                ? $request->input('starts_on')
                : $routine?->starts_on?->format('Y-m-d');
            $endsOn = $request->exists('ends_on')
                ? $request->input('ends_on')
                : $routine?->ends_on?->format('Y-m-d');

            if (
                is_string($startsOn)
                && is_string($endsOn)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn)
                && $endsOn < $startsOn
            ) {
                $field = $request->exists('ends_on') ? 'ends_on' : 'starts_on';
                $validator->errors()->add($field, 'The end date must be on or after the start date.');
            }

            if (! $partial || ! $routine?->logs()->exists()) {
                return;
            }

            if (
                $request->exists('schedule_type')
                && $request->input('schedule_type') !== $routine->schedule_type
            ) {
                $this->addScheduleLockedError($validator, 'schedule_type');
            }

            if ($request->exists('weekdays')) {
                $requestedWeekdays = WeekdayCode::normalizeList($request->input('weekdays', []));

                if ($requestedWeekdays !== $routine->weekdays) {
                    $this->addScheduleLockedError($validator, 'weekdays');
                }
            }

            if (
                $request->exists('starts_on')
                && $request->input('starts_on') !== $routine->starts_on?->format('Y-m-d')
            ) {
                $this->addScheduleLockedError($validator, 'starts_on');
            }
        });

        $data = $validator->validate();

        if ($partial && $data === []) {
            throw ValidationException::withMessages([
                'request' => 'Provide at least one routine field to update.',
            ]);
        }

        return $data;
    }

    private function addScheduleLockedError(LaravelValidator $validator, string $field): void
    {
        $validator->errors()->add(
            $field,
            'The schedule cannot change after history exists. Archive this routine and create a replacement.',
        );
    }
}
