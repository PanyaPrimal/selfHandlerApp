<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\Supplement;
use App\Models\SupplementCourse;
use App\Models\User;
use App\ValueObjects\SupplementQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SupplementCourseService
{
    public function __construct(private readonly SupplementCourseRecurrence $recurrence) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): SupplementCourse
    {
        $supplement = Supplement::query()->ownedBy($user)->find($data['supplement_id']);
        abort_unless($supplement, 404);
        if ($supplement->is_archived) {
            throw ValidationException::withMessages([
                'supplement_id' => __('messages.supplement_course_supplement_active'),
            ]);
        }
        $this->assertGoal($user, $data['goal_id']);
        $endsOn = $this->endsOn($data['starts_on'], $data['ends_on'] ?? null, $data['duration_days'] ?? null, $user);
        $dose = $this->dose($data['dose_quantity'], $data['dose_display_unit'], $supplement);

        return DB::transaction(function () use ($data, $dose, $endsOn, $supplement, $user): SupplementCourse {
            $course = SupplementCourse::create([
                'user_id' => $user->id,
                'supplement_id' => $supplement->id,
                'goal_id' => $data['goal_id'],
                'name' => $data['name'],
                'dose_quantity' => $dose,
                'dose_display_unit' => $data['dose_display_unit'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $endsOn,
                'is_active' => $data['is_active'],
            ]);
            $this->recurrence->apply($course, $user, $data['schedule']);

            return $course->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SupplementCourse $course, User $user, array $data): SupplementCourse
    {
        abort_unless($course->isOwnedBy($user), 404);
        $course->loadMissing('supplement');
        if (array_key_exists('goal_id', $data)) {
            $this->assertGoal($user, $data['goal_id']);
        }
        $startsOn = $data['starts_on'] ?? $course->starts_on->format('Y-m-d');
        $endsOn = array_key_exists('ends_on', $data) || array_key_exists('duration_days', $data)
            ? $this->endsOn($startsOn, $data['ends_on'] ?? null, $data['duration_days'] ?? null, $user)
            : $course->ends_on->format('Y-m-d');
        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('messages.supplement_course_bounds')]);
        }
        if (array_key_exists('dose_quantity', $data)) {
            $display = $data['dose_display_unit'] ?? $course->dose_display_unit;
            $data['dose_quantity'] = $this->dose($data['dose_quantity'], $display, $course->supplement);
        }
        if (array_key_exists('dose_display_unit', $data)
            && ! SupplementQuantity::compatible($data['dose_display_unit'], $course->supplement->stock_unit)) {
            throw ValidationException::withMessages([
                'dose_display_unit' => __('messages.supplement_unit_incompatible'),
            ]);
        }

        return DB::transaction(function () use ($course, $data, $startsOn, $endsOn, $user): SupplementCourse {
            $schedule = $data['schedule'] ?? null;
            unset($data['schedule'], $data['duration_days']);
            $course->applyLifecycle([...$data, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
            $course->save();
            $this->recurrence->apply($course, $user, $schedule);

            return $course->refresh();
        });
    }

    private function assertGoal(User $user, ?int $goalId): void
    {
        if ($goalId === null) {
            return;
        }
        abort_unless(Goal::query()->ownedBy($user)->whereKey($goalId)
            ->where('status', 'active')->where('is_archived', false)->exists(), 404);
    }

    private function endsOn(string $startsOn, ?string $endsOn, ?int $duration, User $user): string
    {
        if ($duration !== null) {
            return CarbonImmutable::parse($startsOn, $user->calendarTimezone())
                ->addDays($duration - 1)->toDateString();
        }
        if ($endsOn === null || $endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => __('messages.supplement_course_bounds')]);
        }

        return $endsOn;
    }

    private function dose(string $value, string $displayUnit, Supplement $supplement): string
    {
        try {
            $dose = SupplementQuantity::fromDisplay($value, $displayUnit, $supplement->stock_unit)->canonical();
            if (bccomp($dose, '0', 6) <= 0) {
                throw new InvalidArgumentException;
            }

            return $dose;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'dose_display_unit' => __('messages.supplement_unit_incompatible'),
            ]);
        }
    }
}
