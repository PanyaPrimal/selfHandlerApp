<?php

namespace App\Support;

/**
 * One thing on one day, as reported by the module that owns it.
 *
 * A projection, never a record. Planner assembles a day out of these on every
 * read and stores none of them: the moment it kept a copy, that copy would start
 * drifting from the module the user actually edits.
 */
final class PlannerEntry
{
    /**
     * @param  list<string>  $actions  planner actions this entry supports
     * @param  array<string, mixed>  $meta  small source-specific detail for the interface
     */
    public function __construct(
        public readonly string $source,
        public readonly int $sourceId,
        public readonly string $title,
        public readonly ?string $time = null,
        public readonly string $status = 'planned',
        public readonly array $actions = [],
        public readonly array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'title' => $this->title,
            'time' => $this->time,
            'status' => $this->status,
            'actions' => $this->actions,
            'meta' => $this->meta,
        ];
    }

    /**
     * Total order: timed entries first by time, then untimed, both by title and
     * finally by source and id so two reads can never disagree.
     */
    public function sortKey(): string
    {
        return sprintf(
            '%s|%s|%s|%010d',
            $this->time === null ? '1' : '0',
            $this->time ?? '',
            mb_strtolower($this->title),
            $this->sourceId,
        );
    }
}
