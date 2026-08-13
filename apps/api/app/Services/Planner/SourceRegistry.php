<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;

/**
 * The modules that can appear in a day.
 *
 * A later module registers itself here; Planner is not edited to know about it.
 * Order is the display order for entries that tie on time and title.
 */
class SourceRegistry
{
    public function __construct(
        private readonly RoutineOccurrenceSource $routines,
        private readonly HabitOccurrenceSource $habits,
        private readonly StorageItemSource $storage,
        private readonly TimeBlockSource $blocks,
    ) {}

    /**
     * @return list<SchedulableSource>
     */
    public function all(): array
    {
        return [$this->routines, $this->habits, $this->storage, $this->blocks];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (SchedulableSource $source): string => $source->name(), $this->all());
    }
}
