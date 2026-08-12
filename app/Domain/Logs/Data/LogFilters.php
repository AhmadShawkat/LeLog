<?php

namespace App\Domain\Logs\Data;

final readonly class LogFilters
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public ?string $service,
        public ?string $level,
        public ?string $since,
        public ?string $until,
        public array $attributes,
        public ?string $message,
    ) {}
}
