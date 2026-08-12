<?php

namespace App\Domain\Logs\Queue;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use JsonException;
use RuntimeException;

/**
 * Buffers already-validated log batches onto a Redis list so the ingestion
 * request can return as soon as the batch is durably queued, decoupling the
 * PHP-FPM request from the PostgreSQL write. Draining is handled by the
 * out-of-band `logs:flush` worker.
 */
final readonly class LogIngestionQueue
{
    public const QUEUE_KEY = 'ingest:queue';

    public function __construct(private RedisFactory $redis) {}

    /**
     * Serialize each validated entry and LPUSH the batch onto the queue.
     *
     * Returns the same {inserted, timings} contract as the synchronous
     * repository so the ingest service and Server-Timing header are unchanged.
     *
     * @param  array{
     *     timestamps: list<string>, services: list<string>, levels: list<string>, messages: list<string>,
     *     attributes: list<string>, attributes_text: list<string>
     * }  $batch
     * @return array{inserted: int, timings: array{array_encoding_ms: float, connection_wait_ms: float, sql_execution_ms: float}}
     */
    public function enqueue(array $batch): array
    {
        $count = count($batch['timestamps']);

        $encodingStarted = hrtime(true);
        $elements = [];

        for ($index = 0; $index < $count; $index++) {
            try {
                $elements[] = json_encode([
                    $batch['timestamps'][$index],
                    $batch['services'][$index],
                    $batch['levels'][$index],
                    $batch['messages'][$index],
                    $batch['attributes'][$index],
                    $batch['attributes_text'][$index],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $exception) {
                throw new RuntimeException('Failed to serialize a log entry for the ingestion queue.', 0, $exception);
            }
        }

        $arrayEncodingMilliseconds = (hrtime(true) - $encodingStarted) / 1_000_000;

        $connectionStarted = hrtime(true);
        $connection = $this->redis->connection();
        $connectionWaitMilliseconds = (hrtime(true) - $connectionStarted) / 1_000_000;

        $pushStarted = hrtime(true);
        $length = $connection->lpush(self::QUEUE_KEY, ...$elements);

        if (! is_int($length) || $length < $count) {
            throw new RuntimeException('The log batch was not fully enqueued for durable ingestion.');
        }

        return [
            'inserted' => $count,
            'timings' => [
                'array_encoding_ms' => $arrayEncodingMilliseconds,
                'connection_wait_ms' => $connectionWaitMilliseconds,
                'sql_execution_ms' => (hrtime(true) - $pushStarted) / 1_000_000,
            ],
        ];
    }
}
