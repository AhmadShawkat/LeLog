<?php

namespace App\Console\Commands;

use App\Domain\Logs\Queue\LogIngestionQueue;
use App\Lifecycle\ApplicationLifecycle;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Long-running worker that drains buffered log entries from Redis and
 * bulk-inserts them into the existing `logs` table via the PostgreSQL COPY
 * protocol. Connects directly to PostgreSQL (the `pgsql_health` connection
 * targets postgres:5432, bypassing pgbouncer).
 */
final class FlushLogs extends Command
{
    protected $signature = 'logs:flush';

    protected $description = 'Drain buffered log entries from Redis and bulk-insert them into PostgreSQL via COPY';

    /**
     * Maximum rows drained from the queue per COPY. Bounded so a single COPY
     * stays within memory and the worker checks the shutdown flag frequently.
     */
    private const DRAIN_LIMIT = 25000;

    private const IDLE_SLEEP_MICROSECONDS = 200000;

    private const COPY_COLUMNS = 'event_timestamp, service, level, message, attributes, attributes_text';

    public function handle(RedisFactory $redis, DatabaseManager $database, ApplicationLifecycle $lifecycle): int
    {
        $this->trap([SIGINT, SIGTERM, SIGQUIT], $lifecycle->requestShutdown(...));

        $connection = $redis->connection();

        while ($lifecycle->isAcceptingWork()) {
            try {
                $rows = $connection->rpop(LogIngestionQueue::QUEUE_KEY, self::DRAIN_LIMIT);
            } catch (Throwable $exception) {
                $this->components->error('Failed to read from the ingestion queue: '.$exception->getMessage());
                usleep(self::IDLE_SLEEP_MICROSECONDS);
                $connection = $redis->connection();

                continue;
            }

            if (! is_array($rows) || $rows === []) {
                usleep(self::IDLE_SLEEP_MICROSECONDS);

                continue;
            }

            $lines = [];

            foreach ($rows as $row) {
                $decoded = json_decode((string) $row, true);

                if (! is_array($decoded) || count($decoded) !== 6) {
                    // Discard an unparseable element rather than poisoning the batch.
                    continue;
                }

                $lines[] = implode("\t", [
                    $this->escape((string) $decoded[0]),
                    $this->escape((string) $decoded[1]),
                    $this->escape((string) $decoded[2]),
                    $this->escape((string) $decoded[3]),
                    $this->escape((string) $decoded[4]),
                    $this->escape((string) $decoded[5]),
                ]);
            }

            if ($lines === []) {
                continue;
            }

            try {
                $pdo = $database->connection('pgsql_health')->getPdo();
                $copied = $pdo->pgsqlCopyFromArray('logs', $lines, "\t", '\\N', self::COPY_COLUMNS);

                if ($copied === false) {
                    throw new RuntimeException('COPY reported failure.');
                }
            } catch (Throwable $exception) {
                $this->components->error('COPY failed, requeuing batch: '.$exception->getMessage());
                // Push the drained rows back so nothing is lost, and force a
                // reconnect in case the connection was dropped.
                $connection->rpush(LogIngestionQueue::QUEUE_KEY, ...$rows);
                $database->connection('pgsql_health')->disconnect();
                usleep(self::IDLE_SLEEP_MICROSECONDS);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Escape a text field for the COPY text format. Backslash is escaped first
     * so the null marker (\N) can never be produced accidentally.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\t", "\n", "\r"],
            ['\\\\', '\\t', '\\n', '\\r'],
            $value,
        );
    }
}
