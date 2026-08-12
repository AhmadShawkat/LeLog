<?php

namespace App\Domain\Logs\Repositories;

use App\Domain\Logs\Data\AggregationQuery;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class LogAggregationRepository
{
    private const ORIGIN = "TIMESTAMPTZ '2001-01-01 00:00:00+00'";

    public function __construct(
        private DatabaseManager $database,
        private LogFilterSql $filterSql,
    ) {}

    /**
     * @return list<array{bucket: string, group: ?string, count: int}>
     */
    public function aggregate(AggregationQuery $query): array
    {
        ['where' => $where, 'bindings' => $bindings] = $this->filterSql->build($query->filters);
        $bucket = "date_bin({$query->bucketInterval}, event_timestamp, ".self::ORIGIN.')';
        $group = $query->groupExpression;
        $sql = <<<SQL
            SELECT
                to_char(bucket_start AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS bucket,
                group_value,
                count
            FROM (
                SELECT
                    $bucket AS bucket_start,
                    $group AS group_value,
                    COUNT(*) AS count
                FROM logs
            SQL;

        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $sql .= ' GROUP BY 1, 2) AS aggregated ORDER BY bucket_start ASC, group_value ASC NULLS FIRST';
        $rows = $this->database->connection()->select($sql, $bindings);
        $buckets = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $count = filter_var($row['count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_string($row['bucket'] ?? null) || $count === false) {
                throw new RuntimeException('A stored aggregation result is invalid.');
            }

            if (($row['group_value'] ?? null) !== null && ! is_string($row['group_value'])) {
                throw new RuntimeException('A stored aggregation group is invalid.');
            }

            $buckets[] = [
                'bucket' => $row['bucket'],
                'group' => $row['group_value'] ?? null,
                'count' => $count,
            ];
        }

        return $buckets;
    }
}
