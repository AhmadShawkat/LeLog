<?php

namespace App\Domain\Logs\Repositories;

use App\Domain\Logs\Data\ValidatedLogBatch;
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
            CAST(? AS jsonb[])
        )
        SQL;

    public function __construct(
        private DatabaseManager $database,
        private PostgreSqlArrayEncoder $encoder,
    ) {}

    public function insert(ValidatedLogBatch $batch): int
    {
        $count = $batch->acceptedCount();

        foreach ([$batch->services, $batch->levels, $batch->messages, $batch->attributes, $batch->attributesText] as $values) {
            if (count($values) !== $count) {
                throw new LogicException('All ingestion arrays must have identical lengths.');
            }
        }

        if ($count === 0) {
            return 0;
        }

        return $this->database->connection()->affectingStatement(self::INSERT_SQL, [
            $this->encoder->encode($batch->timestamps),
            $this->encoder->encode($batch->services),
            $this->encoder->encode($batch->levels),
            $this->encoder->encode($batch->messages),
            $this->encoder->encode($batch->attributes),
            $this->encoder->encode($batch->attributesText),
        ]);
    }
}
