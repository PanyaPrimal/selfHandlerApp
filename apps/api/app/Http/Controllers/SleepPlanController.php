<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSleepPlanRequest;
use App\Http\Requests\UpdateSleepPlanRequest;
use App\Http\Resources\SleepPlanResource;
use App\Models\SleepPlan;
use App\Services\SleepPlanRecurrence;
use App\Services\SleepWorkspaceService;
use App\ValueObjects\WeekdayCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SleepPlanController extends Controller
{
    public function __construct(
        private readonly SleepPlanRecurrence $recurrence,
        private readonly SleepWorkspaceService $workspace,
    ) {}

    public function store(StoreSleepPlanRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $schedule = $this->pullSchedule($data);
        $weekdays = $this->pullWeekdays($data) ?? [];

        $plan = DB::transaction(function () use ($data, $schedule, $weekdays, $user): SleepPlan {
            $plan = SleepPlan::create([...$data, 'user_id' => $user->id]);
            $this->recurrence->apply($plan, $user, $schedule, $weekdays);

            return $plan;
        });

        return response()->json(['data' => $this->resource($plan, $user)], 201);
    }

    public function update(UpdateSleepPlanRequest $request, SleepPlan $sleepPlan): JsonResponse
    {
        $user = $request->user();
        abort_unless($sleepPlan->isOwnedBy($user), 404);
        $data = $request->validated();
        $schedule = $this->pullSchedule($data);
        $weekdays = $this->pullWeekdays($data);

        DB::transaction(function () use ($data, $schedule, $weekdays, $sleepPlan, $user): void {
            if (($data['is_archived'] ?? null) === true && ! $sleepPlan->is_archived) {
                $data['archived_at'] = now();
                $data['is_active'] = false;
            } elseif (($data['is_archived'] ?? null) === false && $sleepPlan->is_archived) {
                $data['archived_at'] = null;
            }

            $sleepPlan->update($data);
            $this->recurrence->apply($sleepPlan, $user, $schedule, $weekdays);
        });

        return response()->json(['data' => $this->resource($sleepPlan, $user)]);
    }

    private function resource(SleepPlan $plan, $user): array
    {
        $plan = $plan->fresh('recurringRule.ruleWeekdays');
        $date = CarbonImmutable::now($user->calendarTimezone())->toDateString();
        $this->workspace->attachSelectedNights(collect([$plan]), $user, $date);

        return (new SleepPlanResource($plan))->resolve();
    }

    /** @param array<string, mixed> $data */
    private function pullSchedule(array &$data): array
    {
        $schedule = [];
        foreach (['schedule_type', 'planned_bed_time', 'starts_on', 'ends_on'] as $field) {
            if (array_key_exists($field, $data)) {
                $schedule[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        return $schedule;
    }

    /** @param array<string, mixed> $data */
    private function pullWeekdays(array &$data): ?array
    {
        if (! array_key_exists('weekdays', $data)) {
            return null;
        }
        $weekdays = WeekdayCode::normalizeList($data['weekdays']);
        unset($data['weekdays']);

        return $weekdays;
    }
}
