<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHabitRequest;
use App\Http\Requests\UpdateHabitRequest;
use App\Http\Resources\HabitResource;
use App\Models\Habit;
use App\Models\HabitLimitStep;
use App\Models\HabitLog;
use App\Services\HabitLimitService;
use App\Services\HabitProjectionService;
use App\Services\HabitRecurrence;
use App\ValueObjects\WeekdayCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HabitController extends Controller
{
    public function __construct(
        private readonly HabitRecurrence $recurrence,
        private readonly HabitLimitService $limits,
        private readonly HabitProjectionService $projections,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $validator = Validator::make($request->query(), [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'state' => ['sometimes', Rule::in(['active', 'paused', 'archived'])],
        ]);
        $validator->after(function ($validator) use ($request): void {
            foreach (array_diff(array_keys($request->query()), ['date', 'state']) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
        });
        $data = $validator->validate();
        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
        $date = $data['date'] ?? $today;
        $state = $data['state'] ?? 'active';

        $habits = Habit::query()
            ->ownedBy($user)
            ->when($state === 'active', fn ($query) => $query->where('is_archived', false)->where('is_active', true))
            ->when($state === 'paused', fn ($query) => $query->where('is_archived', false)->where('is_active', false))
            ->when($state === 'archived', fn ($query) => $query->where('is_archived', true))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $this->projections->decorate($habits, $user, $date);

        return response()->json([
            'date' => $date,
            'today' => $today,
            'data' => HabitResource::collection($habits)->resolve($request),
            'options' => $this->options(),
        ]);
    }

    public function store(StoreHabitRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $weekdays = $this->pullWeekdays($data) ?? [];
        $schedule = $this->pullSchedule($data);
        $steps = $data['limit_steps'] ?? null;
        unset($data['limit_steps']);

        $habit = DB::transaction(function () use ($data, $schedule, $steps, $user, $weekdays): Habit {
            $habit = Habit::create([...$data, 'user_id' => $user->id]);
            $this->recurrence->apply($habit, $user, $schedule, $weekdays);

            if ($steps !== null) {
                $this->limits->replace($habit, $user, $steps);
            }

            return $habit;
        });

        return $this->one($habit, $request, 201);
    }

    public function update(UpdateHabitRequest $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        abort_unless($habit->isOwnedBy($user), 404);
        $data = $request->validated();
        $weekdays = $this->pullWeekdays($data);
        $schedule = $this->pullSchedule($data);

        DB::transaction(function () use ($data, $habit, $schedule, $user, $weekdays): void {
            $habit->applyLifecycle($data);
            $habit->save();
            $this->recurrence->apply($habit, $user, $schedule, $weekdays);
        });

        return $this->one($habit, $request);
    }

    public function one(Habit $habit, Request $request, int $status = 200, ?string $date = null): JsonResponse
    {
        $user = $request->user();
        abort_unless($habit->isOwnedBy($user), 404);
        $date ??= CarbonImmutable::now($user->calendarTimezone())->toDateString();
        $habit = $habit->fresh();
        $this->projections->decorate($habit->newCollection([$habit]), $user, $date);

        return response()->json(['data' => HabitResource::make($habit)->resolve($request)], $status);
    }

    /** @param array<string, mixed> $data @return list<string>|null */
    private function pullWeekdays(array &$data): ?array
    {
        if (! array_key_exists('weekdays', $data)) {
            return null;
        }

        $weekdays = WeekdayCode::normalizeList($data['weekdays']);
        unset($data['weekdays']);

        return $weekdays;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function pullSchedule(array &$data): array
    {
        $schedule = [];
        foreach (['schedule_type', 'preferred_time', 'starts_on', 'ends_on'] as $field) {
            if (array_key_exists($field, $data)) {
                $schedule[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        return $schedule;
    }

    /** @return array<string, list<string>> */
    private function options(): array
    {
        return [
            'kinds' => Habit::KINDS,
            'modes' => Habit::MODES,
            'outcomes' => HabitLog::OUTCOMES,
            'periods' => HabitLimitStep::PERIODS,
            'weekdays' => WeekdayCode::values(),
        ];
    }
}
