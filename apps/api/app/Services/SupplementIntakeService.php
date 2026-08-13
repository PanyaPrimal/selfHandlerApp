<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SupplementCourse;
use App\Models\SupplementIntake;
use App\Models\User;
use App\ValueObjects\SupplementQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SupplementIntakeService
{
    public function __construct(private readonly OccurrenceFactSynchronizer $occurrences) {}

    /** @param array<string, mixed> $data @return array{occurrence:PlannedOccurrence,created:bool} */
    public function upsert(PlannedOccurrence $occurrence, User $user, array $data): array
    {
        abort_unless($occurrence->isOwnedBy($user), 404);

        return DB::transaction(function () use ($occurrence, $user, $data): array {
            $occurrence = PlannedOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()
                ->with('recurringRule')->firstOrFail();
            abort_unless($occurrence->recurringRule?->owner_type === RecurringRule::OWNER_SUPPLEMENT_COURSE, 404);
            $course = SupplementCourse::query()->ownedBy($user)
                ->with('supplement')->find($occurrence->recurringRule->owner_id);
            abort_unless($course, 404);
            $effective = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
            $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
            if ($effective > $today) {
                throw ValidationException::withMessages([
                    'taken_time' => __('messages.supplement_intake_future'),
                ]);
            }

            $taken = $data['outcome'] === SupplementIntake::OUTCOME_TAKEN;
            $displayUnit = $data['dose_display_unit'] ?? $course->dose_display_unit;
            $dose = (string) $course->dose_quantity;
            if ($taken && $data['dose_quantity'] !== null) {
                try {
                    $dose = SupplementQuantity::fromDisplay(
                        $data['dose_quantity'], $displayUnit, $course->supplement->stock_unit,
                    )->canonical();
                    if (bccomp($dose, '0', 6) <= 0) {
                        throw new InvalidArgumentException;
                    }
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages([
                        'dose_display_unit' => __('messages.supplement_unit_incompatible'),
                    ]);
                }
            }
            $takenAt = $taken
                ? $this->parseTakenAt($effective, $data['taken_time'], $user->calendarTimezone())
                : null;

            $intake = SupplementIntake::query()->updateOrCreate([
                'user_id' => $user->id,
                'supplement_course_id' => $course->id,
                'planned_on' => $occurrence->occurrence_date->format('Y-m-d'),
                'slot' => $occurrence->slot,
            ], [
                'supplement_id' => $course->supplement_id,
                'effective_on' => $effective,
                'outcome' => $data['outcome'],
                'dose_quantity' => $dose,
                'dose_display_unit' => $displayUnit,
                'supplement_name' => $course->supplement->name,
                'taken_at' => $takenAt,
                'note' => $data['note'],
            ]);
            $created = $intake->wasRecentlyCreated;
            $this->occurrences->syncFromSupplementIntake($intake);

            return [
                'occurrence' => $occurrence->fresh(['recurringRule', 'supplementIntake']),
                'created' => $created,
            ];
        });
    }

    public function clear(PlannedOccurrence $occurrence, User $user): void
    {
        abort_unless($occurrence->isOwnedBy($user), 404);
        DB::transaction(function () use ($occurrence, $user): void {
            $occurrence = PlannedOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()
                ->with('recurringRule')->firstOrFail();
            abort_unless($occurrence->recurringRule?->owner_type === RecurringRule::OWNER_SUPPLEMENT_COURSE, 404);
            abort_unless((int) SupplementCourse::query()->ownedBy($user)
                ->whereKey($occurrence->recurringRule->owner_id)->value('id') > 0, 404);
            $intakeId = $occurrence->supplement_intake_id;
            $this->occurrences->clearForSupplementOccurrence($occurrence);
            if ($intakeId !== null) {
                SupplementIntake::query()->ownedBy($user)->whereKey($intakeId)->delete();
            }
        });
    }

    private function parseTakenAt(string $date, string $time, string $timezone): CarbonImmutable
    {
        $wall = "{$date} {$time}";
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, $timezone);
        if (! $parsed || $parsed->format('Y-m-d H:i') !== $wall) {
            throw ValidationException::withMessages([
                'taken_time' => __('messages.supplement_intake_time_nonexistent'),
            ]);
        }
        if ($parsed->greaterThan(CarbonImmutable::now($timezone))) {
            throw ValidationException::withMessages([
                'taken_time' => __('messages.supplement_intake_future'),
            ]);
        }

        return $parsed->utc();
    }
}
