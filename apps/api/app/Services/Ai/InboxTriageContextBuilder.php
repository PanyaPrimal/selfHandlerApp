<?php

namespace App\Services\Ai;

use App\Models\Item;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;

class InboxTriageContextBuilder
{
    /** @return array<string,mixed> */
    public function build(User $user, Item $item): array
    {
        $profile = $user->ensureProfile();
        $today = CarbonImmutable::now($profile->timezone)->format('Y-m-d');

        return [
            'item' => [
                'title' => $item->title,
                'description' => $item->description,
            ],
            'projects' => Project::query()->ownedBy($user)->where('is_archived', false)
                ->orderBy('name')->limit((int) config('ai.context_project_limit', 100))
                ->get(['id', 'name'])->map->only(['id', 'name'])->values()->all(),
            'existing_tags' => Tag::query()->ownedBy($user)->orderBy('name')
                ->limit((int) config('ai.context_tag_limit', 100))->pluck('name')->all(),
            'allowed_types' => Item::TYPES,
            'allowed_priorities' => Item::PRIORITIES,
            'calendar' => [
                'today' => $today,
                'timezone' => $profile->timezone,
            ],
            'presentation' => [
                'locale' => $profile->locale,
                'tone' => $profile->recommendation_tone,
            ],
        ];
    }
}
