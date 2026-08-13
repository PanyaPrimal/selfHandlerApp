<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\Goal;
use App\Models\TrainingGoalDetail;
use App\Models\User;
use App\Support\PlannerEntry;

class TrainingGoalSource implements SchedulableSource
{
    public function name(): string
    {
        return 'training_goal';
    }

    public function entriesFor(User $user, string $date): array
    {
        return Goal::query()->ownedBy($user)
            ->where('type', Goal::TYPE_TRAINING)
            ->where('status', 'active')
            ->where('is_archived', false)
            ->whereDate('target_date', $date)
            ->whereHas('trainingDetail', fn ($query) => $query->where('kind', TrainingGoalDetail::KIND_RACE))
            ->orderBy('id')->get()
            ->map(fn (Goal $goal): PlannerEntry => new PlannerEntry(
                source: $this->name(),
                sourceId: $goal->id,
                title: $goal->name,
                status: 'planned',
                actions: [],
                meta: [
                    'goal_id' => $goal->id,
                    'target_date' => $date,
                    'action_url' => '/workouts?goal='.$goal->id,
                ],
            ))->all();
    }
}
