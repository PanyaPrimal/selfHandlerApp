<?php

namespace App\Data\Calendar;

final class CalendarDescriptor
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $timezone,
        public readonly bool $writable,
        public readonly bool $default,
    ) {}

    /** @return array{id:string,name:string,timezone:?string,writable:bool,is_default:bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'writable' => $this->writable,
            'is_default' => $this->default,
        ];
    }
}
