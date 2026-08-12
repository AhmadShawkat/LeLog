<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\Data\AggregationQuery;
use App\Domain\Logs\InvalidLogQuery;

final readonly class AggregationQueryValidator
{
    private const BUCKETS = [
        '1m' => "INTERVAL '1 minute'",
        '5m' => "INTERVAL '5 minutes'",
        '1h' => "INTERVAL '1 hour'",
        '1d' => "INTERVAL '1 day'",
    ];

    private const GROUPS = [
        'service' => 'service',
        'level' => 'level',
    ];

    public function __construct(private LogFilterValidator $filterValidator) {}

    public function validate(string $queryString): AggregationQuery
    {
        $validated = $this->filterValidator->validate($queryString, ['bucket', 'group_by']);
        $parameters = $validated['parameters'];
        $filters = $validated['filters'];

        if ($filters->since === null || $filters->until === null) {
            throw new InvalidLogQuery('The since and until timestamps are required.');
        }

        $bucket = $parameters['bucket'] ?? null;

        if ($bucket === null || ! isset(self::BUCKETS[$bucket])) {
            throw new InvalidLogQuery('The bucket must be one of: 1m, 5m, 1h, 1d.');
        }

        $group = $parameters['group_by'] ?? null;

        if ($group !== null && ! isset(self::GROUPS[$group])) {
            throw new InvalidLogQuery('The group_by value must be service or level.');
        }

        return new AggregationQuery(
            filters: $filters,
            bucketInterval: self::BUCKETS[$bucket],
            groupExpression: $group === null ? 'NULL::text' : self::GROUPS[$group],
        );
    }
}
