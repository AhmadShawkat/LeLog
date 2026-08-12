<?php

namespace App\Domain\Logs\Repositories;

use App\Domain\Logs\Data\LogQuery;
use Illuminate\Database\DatabaseManager;
use JsonException;
use RuntimeException;

final readonly class LogQueryRepository
{
    public function __construct(
        private DatabaseManager $database,
        private LogFilterSql $filterSql,
    ) {}

    /**
     * @return list<array{id: int, timestamp: string, received_at: string, service: string, level: string, message: string, attributes: object}>
     */
    public function find(LogQuery $query): array
    {
        ['where' => $where, 'bindings' => $bindings] = $this->filterSql->build($query->filters);

        if ($query->cursorTimestamp !== null && $query->cursorId !== null) {
            $where[] = <<<'SQL'
                (
                    event_timestamp <= CAST(? AS timestamptz)
                    AND (
                        event_timestamp < CAST(? AS timestamptz)
                        OR (event_timestamp = CAST(? AS timestamptz) AND id < CAST(? AS bigint))
                    )
                )
                SQL;
            $bindings[] = $query->cursorTimestamp;
            $bindings[] = $query->cursorTimestamp;
            $bindings[] = $query->cursorTimestamp;
            $bindings[] = $query->cursorId;
        }

        $sql = <<<'SQL'
            SELECT
                id,
                to_char(event_timestamp AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS timestamp,
                to_char(received_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS received_at,
                service,
                level,
                message,
                attributes
            FROM logs
            SQL;

        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $sql .= ' ORDER BY event_timestamp DESC, id DESC LIMIT ?';
        $bindings[] = $query->limit + 1;
        $rows = $this->database->connection()->select($sql, $bindings);

        $logs = [];

        foreach ($rows as $row) {
            $logs[] = $this->mapRow((array) $row);
        }

        return $logs;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int, timestamp: string, received_at: string, service: string, level: string, message: string, attributes: object}
     */
    private function mapRow(array $row): array
    {
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $stringKeys = ['timestamp', 'received_at', 'service', 'level', 'message'];

        if ($id === false) {
            throw new RuntimeException('A stored log ID is invalid.');
        }

        foreach ($stringKeys as $key) {
            if (! is_string($row[$key] ?? null)) {
                throw new RuntimeException("A stored log $key value is invalid.");
            }
        }

        try {
            $attributes = json_decode(is_string($row['attributes'] ?? null) ? $row['attributes'] : '', false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Stored log attributes are invalid.');
        }

        if (! is_object($attributes)) {
            throw new RuntimeException('Stored log attributes are not an object.');
        }

        return [
            'id' => $id,
            'timestamp' => $row['timestamp'],
            'received_at' => $row['received_at'],
            'service' => $row['service'],
            'level' => $row['level'],
            'message' => $row['message'],
            'attributes' => $attributes,
        ];
    }
}
