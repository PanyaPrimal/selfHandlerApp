<?php

namespace App\Services\Ai;

use App\Exceptions\AiAssistantException;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;

class StorageInboxTriageTool
{
    /** @param array<string,mixed> $proposal */
    public function execute(User $user, Item $item, array $proposal): Item
    {
        if (! $item->isOwnedBy($user) || $item->status !== Item::STATUS_INBOX) {
            throw AiAssistantException::confirmationStale();
        }
        $item->fill([
            'type' => $proposal['type'],
            'project_id' => $proposal['project_id'],
            'priority' => $proposal['priority'],
            'due_on' => $proposal['due_on'],
        ]);
        $item->applyStatus(Item::STATUS_ACTIVE);
        $item->save();
        $ids = [];
        foreach ($proposal['tags'] as $name) {
            $tag = Tag::query()->firstOrCreate(['user_id' => $user->id, 'name' => $name]);
            $ids[$tag->id] = ['user_id' => $user->id];
        }
        $item->tags()->sync($ids);

        return $item->fresh(['tags', 'children.tags']);
    }
}
