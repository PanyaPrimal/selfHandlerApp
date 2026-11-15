<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Checklist structure for Storage items: depth, manual order and the cascade
 * that a checkbox applies to a group.
 *
 * Siblings are the children of one parent, or the top-level items of one
 * project (or of no project). Order is `position`, then creation order, so
 * rows that never had a position keep their old sequence.
 */
class ItemTree
{
    /** Number of levels from the top down to this item, the item included. */
    public function depth(Item $item): int
    {
        $depth = 1;
        $parentId = $item->parent_id;

        while ($parentId !== null && $depth <= Item::MAX_DEPTH) {
            $depth++;
            $parentId = Item::query()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }

    /** Levels below this item: 0 for a leaf. */
    public function height(Item $item): int
    {
        $height = 0;
        $level = [$item->id];

        while ($level !== [] && $height <= Item::MAX_DEPTH) {
            $level = Item::query()->whereIn('parent_id', $level)->pluck('id')->all();
            if ($level !== []) {
                $height++;
            }
        }

        return $height;
    }

    /**
     * Every item below this one, nearest first.
     *
     * @return Collection<int, Item>
     */
    public function descendants(Item $item): Collection
    {
        $found = collect();
        $level = [$item->id];

        for ($step = 0; $level !== [] && $step < Item::MAX_DEPTH; $step++) {
            $rows = Item::query()->whereIn('parent_id', $level)->get();
            $found = $found->concat($rows);
            $level = $rows->pluck('id')->all();
        }

        return $found;
    }

    /**
     * Ancestors from the direct parent upwards.
     *
     * @return list<Item>
     */
    public function ancestors(Item $item): array
    {
        $found = [];
        $parentId = $item->parent_id;

        while ($parentId !== null && count($found) < Item::MAX_DEPTH) {
            $parent = Item::query()->find($parentId);
            if (! $parent) {
                break;
            }
            $found[] = $parent;
            $parentId = $parent->parent_id;
        }

        return $found;
    }

    public function isDescendant(Item $candidate, Item $of): bool
    {
        return $this->descendants($of)->contains('id', $candidate->id);
    }

    /**
     * Put the item at a 0-based index among its siblings and renumber them.
     * A null index places it after the last sibling.
     */
    public function place(Item $item, ?int $index): void
    {
        $siblings = $this->siblings($item)->where('id', '!=', $item->id)
            ->orderBy('position')->orderBy('id')->pluck('id')->all();
        $index = $index === null ? count($siblings) : max(0, min($index, count($siblings)));
        array_splice($siblings, $index, 0, [$item->id]);

        foreach ($siblings as $position => $id) {
            Item::query()->whereKey($id)->where('position', '!=', $position)->update(['position' => $position]);
        }

        $item->position = $index;
        $item->syncOriginalAttribute('position');
    }

    /** Children follow their top-level item into another list. */
    public function moveSubtreeToProject(Item $item): void
    {
        $ids = $this->descendants($item)->pluck('id')->all();

        if ($ids !== []) {
            Item::query()->whereIn('id', $ids)->update(['project_id' => $item->project_id]);
        }
    }

    /**
     * A checkbox on a group: checking closes every open task below it and
     * then any parent whose children are all closed; unchecking reopens the
     * group below it and every closed parent above it.
     */
    public function cascade(Item $item): void
    {
        if ($item->status === Item::STATUS_DONE) {
            foreach ($this->descendants($item) as $row) {
                if ($row->type === Item::TYPE_TASK && $row->isOpen()) {
                    $row->applyStatus(Item::STATUS_DONE);
                    $row->save();
                }
            }

            foreach ($this->ancestors($item) as $parent) {
                $open = $parent->children()->whereIn('status', Item::OPEN_STATUSES)->exists();
                if ($open || ! $parent->isOpen() || $parent->type === Item::TYPE_PURCHASE) {
                    break;
                }
                $parent->applyStatus(Item::STATUS_DONE);
                $parent->save();
            }

            return;
        }

        if (! $item->isOpen()) {
            return;
        }

        foreach ($this->descendants($item) as $row) {
            if ($row->status === Item::STATUS_DONE) {
                $row->applyStatus(Item::STATUS_ACTIVE);
                $row->save();
            }
        }

        foreach ($this->ancestors($item) as $parent) {
            if ($parent->status === Item::STATUS_DONE) {
                $parent->applyStatus(Item::STATUS_ACTIVE);
                $parent->save();
            }
        }
    }

    /** @return Builder<Item> */
    private function siblings(Item $item): Builder
    {
        $query = Item::query()->where('user_id', $item->user_id);

        return $item->parent_id !== null
            ? $query->where('parent_id', $item->parent_id)
            : $query->whereNull('parent_id')->when(
                $item->project_id === null,
                fn (Builder $rows) => $rows->whereNull('project_id'),
                fn (Builder $rows) => $rows->where('project_id', $item->project_id),
            );
    }
}
