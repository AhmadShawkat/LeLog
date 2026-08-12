<?php

namespace App\Domain\Logs\Data;

final readonly class LogQuery
{
    public function __construct(
        public LogFilters $filters,
        public int $limit,
        public ?string $cursorTimestamp,
        public ?int $cursorId,
    ) {}
}
