<?php

namespace App\Domain\Logs\Repositories;

use DateTimeInterface;
use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class LogRetentionRepository
{
    private const LOCK_ID = 781_847_646_676_111;

    private const DELETE_SQL = <<<'SQL'
        WITH expired AS (
            SELECT id
            FROM logs
            WHERE event_timestamp < ?
            ORDER BY event_timestamp ASC, id ASC
            LIMIT ?
            FOR UPDATE SKIP LOCKED
        )
        DELETE FROM logs
        USING expired
        WHERE logs.id = expired.id
        SQL;

    public function __construct(private DatabaseManager $database) {}

    /**
     * @param  callable(): int  $operation
     */
    public function withLock(callable $operation): ?int
    {
        $connection = $this->database->connection();
        $locked = filter_var($connection->selectOne(
            'SELECT pg_try_advisory_lock(?) AS locked',
            [self::LOCK_ID],
        )->locked ?? false, FILTER_VALIDATE_BOOL);

        if (! $locked) {
            return null;
        }

        try {
            return $operation();
        } finally {
            try {
                $connection->selectOne('SELECT pg_advisory_unlock(?)', [self::LOCK_ID]);
            } catch (Throwable) {
                $connection->disconnect();
            }
        }
    }

    public function deleteBatch(DateTimeInterface $cutoff, int $batchSize): int
    {
        return $this->database->connection()->affectingStatement(self::DELETE_SQL, [
            $cutoff->format('Y-m-d H:i:s.uP'),
            $batchSize,
        ]);
    }
}
