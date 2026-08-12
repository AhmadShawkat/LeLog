<?php

namespace App\Domain\Logs\Data;

final readonly class AggregationQuery
{
    public function __construct(
        public LogFilters $filters,
        public string $bucketInterval,
        public string $groupExpression,
    ) {}
}
