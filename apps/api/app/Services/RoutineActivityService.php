<?php

namespace App\Services;

use App\Models\Routine;
use App\Models\RoutineActivity;
use App\Models\RoutineActivityLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoutineActivityService
{
    /**
     * @param  list<array<string, mixed>>  $definitions
     * @return Collection<int, RoutineActivity>
     */
    public function replace(Routine $routine, User $user, array $definitions): Collection
    {
        abort_unless($routine->isOwnedBy($user), 404);
        $definitions = $this->validate($definitions);

        return DB::transaction(function () use ($routine, $user, $definitions): Collection {
            $lockedRoutine = Routine::query()->whereKey($routine->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedRoutine->isOwnedBy($user), 404);

            $existing = RoutineActivity::query()
                ->where('routine_id', $lockedRoutine->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $requestedIds = collect($definitions)->pluck('id')->filter()->map(fn ($id): int => (int) $id);

            if ($requestedIds->duplicates()->isNotEmpty()
                || $requestedIds->diff($existing->keys())->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'activities' => __('messages.routine_activity_ids'),
                ]);
            }

            $hasFacts = $lockedRoutine->logs()->exists()
                || RoutineActivityLog::query()
                    ->whereIn('routine_activity_id', $existing->keys())
                    ->exists();

            if ($hasFacts) {
                $sameMembership = $requestedIds->sort()->values()->all() === $existing->keys()->sort()->values()->all();
                $sameTotals = $sameMembership && collect($definitions)->every(function (array $definition) use ($existing): bool {
                    $current = $existing->get((int) $definition['id']);
                    $next = $definition['progress_total'] ?? null;

                    return $this->sameDecimal($current?->progress_total, $next);
                });

                if (! $sameMembership || ! $sameTotals) {
                    throw ValidationException::withMessages([
                        'activities' => __('messages.routine_activity_locked'),
                    ]);
                }
            }

            // Free the unique order slots before applying the exact requested order.
            foreach ($existing->values() as $index => $activity) {
                $activity->update(['sort_order' => 1000000000 + $index]);
            }

            if (! $hasFacts) {
                RoutineActivity::query()
                    ->where('routine_id', $lockedRoutine->id)
                    ->whereNotIn('id', $requestedIds->all() ?: [0])
                    ->delete();
            }

            foreach ($definitions as $definition) {
                $attributes = [
                    'name' => $definition['name'],
                    'sort_order' => $definition['sort_order'],
                    'preferred_time' => $definition['preferred_time'] ?? null,
                    'progress_total' => $definition['progress_total'] ?? null,
                ];

                if (isset($definition['id'])) {
                    $existing->get((int) $definition['id'])->update($attributes);
                } else {
                    RoutineActivity::create([
                        ...$attributes,
                        'user_id' => $user->id,
                        'routine_id' => $lockedRoutine->id,
                    ]);
                }
            }

            return RoutineActivity::query()
                ->where('routine_id', $lockedRoutine->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });
    }

    /** @param list<array<string, mixed>> $definitions
     * @return list<array<string, mixed>>
     */
    private function validate(array $definitions): array
    {
        $validator = Validator::make(['activities' => $definitions], [
            'activities' => ['array', 'max:100'],
            'activities.*.id' => ['sometimes', 'integer', 'min:1'],
            'activities.*.name' => ['required', 'string', 'max:160'],
            'activities.*.sort_order' => ['required', 'integer', 'min:0'],
            'activities.*.preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'activities.*.progress_total' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999.999'],
        ]);
        $validator->after(function ($validator) use ($definitions): void {
            $orders = [];
            $ids = [];
            foreach ($definitions as $index => $definition) {
                if (isset($definition['sort_order']) && in_array((int) $definition['sort_order'], $orders, true)) {
                    $validator->errors()->add("activities.{$index}.sort_order", __('messages.routine_activity_order'));
                }
                $orders[] = (int) ($definition['sort_order'] ?? -1);
                if (isset($definition['id']) && in_array((int) $definition['id'], $ids, true)) {
                    $validator->errors()->add("activities.{$index}.id", __('messages.routine_activity_ids'));
                }
                if (isset($definition['id'])) {
                    $ids[] = (int) $definition['id'];
                }
            }
        });

        return $validator->validate()['activities'];
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return abs((float) $left - (float) $right) < 0.0005;
    }
}
