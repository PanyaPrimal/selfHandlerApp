<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceWorkoutProgramExercisesRequest;
use App\Http\Requests\WorkoutProgramMutationRequest;
use App\Http\Resources\WorkoutProgramResource;
use App\Models\PlannedOccurrence;
use App\Models\WorkoutProgram;
use App\Services\WorkoutProgramRecurrence;
use App\Services\WorkoutProgramService;
use App\Services\WorkoutProgressionService;
use App\ValueObjects\WeekdayCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WorkoutProgramController extends Controller
{
    public function __construct(
        private readonly WorkoutProgramRecurrence $recurrence,
        private readonly WorkoutProgramService $programs,
        private readonly WorkoutProgressionService $progression,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'state' => ['sometimes', Rule::in(['active', 'paused', 'archived'])],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if (array_diff(array_keys($request->query()), ['date', 'state']) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
        });
        $data = $validator->validate();
        $today = CarbonImmutable::now($request->user()->calendarTimezone())->toDateString();
        $date = $data['date'] ?? $today;
        $state = $data['state'] ?? 'active';
        $programs = WorkoutProgram::query()->ownedBy($request->user())
            ->when($state === 'active', fn ($query) => $query->where('is_archived', false)->where('is_active', true))
            ->when($state === 'paused', fn ($query) => $query->where('is_archived', false)->where('is_active', false))
            ->when($state === 'archived', fn ($query) => $query->where('is_archived', true))
            ->with($this->relations())->orderBy('name')->orderBy('id')->get();
        $this->decorate($programs, $date);

        return response()->json([
            'date' => $date,
            'today' => $today,
            'data' => WorkoutProgramResource::collection($programs)->resolve($request),
            'options' => $this->options(),
        ]);
    }

    public function store(WorkoutProgramMutationRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $schedule = $this->pullSchedule($data);
        $weekdays = $this->pullWeekdays($data) ?? [];
        $endurance = $data['endurance'] ?? null;
        $timed = $data['timed'] ?? null;
        unset($data['endurance'], $data['timed']);
        $program = DB::transaction(function () use ($user, $data, $schedule, $weekdays, $endurance, $timed): WorkoutProgram {
            $program = WorkoutProgram::create(['user_id' => $user->id, ...$data]);
            $this->recurrence->apply($program, $user, $schedule, $weekdays);
            $this->programs->replaceSubtype($program, $user, $endurance, $timed);

            return $program;
        });

        return $this->one($program, $request, 201);
    }

    public function update(WorkoutProgramMutationRequest $request, WorkoutProgram $program): JsonResponse
    {
        $user = $request->user();
        abort_unless($program->isOwnedBy($user), 404);
        $data = $request->validated();
        $schedule = $this->pullSchedule($data);
        $weekdays = $this->pullWeekdays($data);
        $replaceSubtype = array_key_exists('endurance', $data) || array_key_exists('timed', $data);
        $endurance = $data['endurance'] ?? null;
        $timed = $data['timed'] ?? null;
        unset($data['endurance'], $data['timed']);
        DB::transaction(function () use ($program, $user, $data, $schedule, $weekdays, $replaceSubtype, $endurance, $timed): void {
            $program->applyLifecycle($data);
            $program->save();
            if ($replaceSubtype) {
                $this->programs->replaceSubtype($program, $user, $endurance, $timed);
            }
            $this->recurrence->apply($program, $user, $schedule, $weekdays);
        });

        return $this->one($program, $request);
    }

    public function replaceExercises(
        ReplaceWorkoutProgramExercisesRequest $request,
        WorkoutProgram $program,
    ): JsonResponse {
        $program = $this->programs->replaceExercises($program, $request->user(), $request->validated('exercises'));

        return $this->one($program, $request);
    }

    private function one(WorkoutProgram $program, Request $request, int $status = 200): JsonResponse
    {
        abort_unless($program->isOwnedBy($request->user()), 404);
        $date = CarbonImmutable::now($request->user()->calendarTimezone())->toDateString();
        $program = $program->fresh($this->relations());
        $this->decorate($program->newCollection([$program]), $date);

        return response()->json(['data' => WorkoutProgramResource::make($program)->resolve($request)], $status);
    }

    private function decorate(Collection $programs, string $date): void
    {
        $ruleIds = $programs->pluck('recurringRule.id')->filter();
        $progressions = $this->progression->forPrograms($programs, $date);
        $occurrences = PlannedOccurrence::query()->whereIn('recurring_rule_id', $ruleIds)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })->get()->keyBy('recurring_rule_id');
        foreach ($programs as $program) {
            $program->setAttribute('selected_date_projection', $date);
            $program->setAttribute('occurrence_projection', $occurrences->get($program->recurringRule?->id));
            $program->setAttribute('progression_projection', $progressions[$program->id] ?? []);
        }
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

    private function relations(): array
    {
        return ['recurringRule.ruleWeekdays', 'exercises.exercise', 'enduranceDetail', 'timedDetail'];
    }

    private function options(): array
    {
        return [
            'workout_types' => WorkoutProgram::TYPES,
            'intensities' => WorkoutProgram::INTENSITIES,
            'activities' => ['running', 'cycling', 'walking', 'swimming', 'other'],
            'run_types' => ['easy', 'tempo', 'intervals', 'long'],
            'weekdays' => WeekdayCode::values(),
        ];
    }
}
