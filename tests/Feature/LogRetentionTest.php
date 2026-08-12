<?php

namespace Tests\Feature;

use App\Domain\Logs\Services\RunLogRetention;
use App\Lifecycle\ApplicationLifecycle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

final class LogRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        config()->set('logs.retention.days', 30);
        config()->set('logs.retention.batch_size', 2);
        config()->set('logs.retention.max_batches_per_run', 2);
    }

    public function test_retention_is_ordered_bounded_and_keeps_unexpired_logs(): void
    {
        $this->insertLog('2026-01-01 00:00:00+00', 'oldest');
        $this->insertLog('2026-01-02 00:00:00+00', 'older');
        $this->insertLog('2026-01-03 00:00:00+00', 'old');
        $this->insertLog('2026-01-04 00:00:00+00', 'last-deleted');
        $this->insertLog('2026-01-05 00:00:00+00', 'bounded');
        $this->insertLog(now()->subDay()->toIso8601String(), 'current');

        self::assertSame(4, app(RunLogRetention::class)->run());
        self::assertSame(['bounded', 'current'], DB::table('logs')->orderBy('id')->pluck('message')->all());
    }

    public function test_database_lock_prevents_cross_process_overlap(): void
    {
        $connection = config('database.connections.pgsql');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            $connection['username'],
            $connection['password'],
        );
        $pdo->query('SELECT pg_advisory_lock(781847646676111)');

        try {
            self::assertNull(app(RunLogRetention::class)->run());
        } finally {
            $pdo->query('SELECT pg_advisory_unlock(781847646676111)');
        }
    }

    public function test_shutdown_stops_new_batches_after_active_work_drains(): void
    {
        $lifecycle = app(ApplicationLifecycle::class);
        $lifecycle->requestShutdown();

        self::assertNull(app(RunLogRetention::class)->run());
        self::assertTrue($lifecycle->isDrained());
    }

    private function insertLog(string $timestamp, string $message): void
    {
        DB::insert(<<<'SQL'
            INSERT INTO logs (event_timestamp, service, level, message)
            VALUES (?, 'retention-test', 'info', ?)
            SQL, [$timestamp, $message]);
    }
}
