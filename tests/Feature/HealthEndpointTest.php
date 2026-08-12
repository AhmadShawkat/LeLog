<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        DB::purge();
        Artisan::call('migrate:rollback', ['--force' => true]);

        parent::tearDown();
    }

    public function test_health_is_successful_when_the_database_and_migrations_are_ready(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_is_unavailable_when_migrations_are_pending(): void
    {
        self::assertSame(0, Artisan::call('migrate:rollback', ['--force' => true]));

        $this->getJson('/health')
            ->assertServiceUnavailable()
            ->assertExactJson(['status' => 'unhealthy']);
    }

    public function test_health_is_unavailable_when_postgresql_cannot_be_reached(): void
    {
        $originalPort = config('database.connections.pgsql.port');

        try {
            config()->set('database.connections.pgsql.port', 1);
            DB::purge();

            $this->getJson('/health')
                ->assertServiceUnavailable()
                ->assertExactJson(['status' => 'unhealthy']);
        } finally {
            config()->set('database.connections.pgsql.port', $originalPort);
            DB::purge();
        }
    }
}
