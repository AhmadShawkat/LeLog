<?php

namespace App\Domain\Logs\Repositories;

use App\Domain\Logs\Validation\PostgreSqlArrayEncoder;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class LogIngestionRepository
{
    private const INSERT_SQL = <<<'SQL'
        INSERT INTO logs (
            event_timestamp,
            service,
            level,
            message,
            attributes,
            attributes_text
        )
        SELECT *
        FROM UNNEST(
            CAST(? AS timestamptz[]),
            CAST(? AS text[]),
            CAST(? AS text[]),
            CAST(? AS text[]),
            CAST(? AS jsonb[]),
            CAST(? AS hstore[])
        )
        SQL;

    public function __construct(
        private DatabaseManager $database,
        private PostgreSqlArrayEncoder $encoder,
    ) {}

    /**
     * @param  array{
     *     timestamps: list<string>, services: list<string>, levels: list<string>, messages: list<string>,
     *     attributes: list<string>, attributes_text: list<string>
     * }  $batch
     * @return array{inserted: int, timings: array{array_encoding_ms: float, connection_wait_ms: float, sql_execution_ms: float}}
     */
    public function insert(array $batch): array
    {
        $count = count($batch['timestamps']);

        foreach ([$batch['services'], $batch['levels'], $batch['messages'], $batch['attributes'], $batch['attributes_text']] as $values) {
            if (count($values) !== $count) {
                throw new LogicException('All ingestion arrays must have identical lengths.');
            }
        }

        if ($count === 0) {
            return [
                'inserted' => 0,
                'timings' => ['array_encoding_ms' => 0.0, 'connection_wait_ms' => 0.0, 'sql_execution_ms' => 0.0],
            ];
        }

        $encodingStarted = hrtime(true);
        $bindings = [
            $this->encoder->encode($batch['timestamps']),
            $this->encoder->encode($batch['services']),
            $this->encoder->encode($batch['levels']),
            $this->encoder->encode($batch['messages']),
            $this->encoder->encode($batch['attributes']),
            $this->encoder->encode($batch['attributes_text']),
        ];
        $arrayEncodingMilliseconds = (hrtime(true) - $encodingStarted) / 1_000_000;

        $connectionStarted = hrtime(true);
        $connection = $this->database->connection();
        $connection->getPdo();
        $connectionWaitMilliseconds = (hrtime(true) - $connectionStarted) / 1_000_000;

        $sqlStarted = hrtime(true);
        $inserted = $connection->affectingStatement(self::INSERT_SQL, $bindings);

        return [
            'inserted' => $inserted,
            'timings' => [
                'array_encoding_ms' => $arrayEncodingMilliseconds,
                'connection_wait_ms' => $connectionWaitMilliseconds,
                'sql_execution_ms' => (hrtime(true) - $sqlStarted) / 1_000_000,
            ],
        ];
    }
}
