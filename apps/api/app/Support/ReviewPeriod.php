<?php

namespace App\Support;

final readonly class ReviewPeriod
{
    public function __construct(
        public string $type,
        public string $anchor,
        public string $start,
        public string $end,
        public string $timezone,
    ) {}

    /** @return array{type:string,anchor:string,start:string,end:string,timezone:string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'anchor' => $this->anchor,
            'start' => $this->start,
            'end' => $this->end,
            'timezone' => $this->timezone,
        ];
    }
}
