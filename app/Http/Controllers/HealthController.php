<?php

namespace App\Http\Controllers;

use App\Lifecycle\ApplicationLifecycle;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function show(ApplicationLifecycle $lifecycle, Migrator $migrator): JsonResponse
    {
        try {
            if (! $lifecycle->isAcceptingWork()) {
                return $this->unhealthy();
            }

            $migrationFiles = $migrator->getMigrationFiles(database_path('migrations'));
            $connection = DB::connection('pgsql_health');
            $ranMigrations = array_map(
                static fn (object $row): string => (string) $row->migration,
                $connection->select('SELECT migration FROM migrations'),
            );

            if (array_diff(array_keys($migrationFiles), $ranMigrations) !== []) {
                return $this->unhealthy();
            }

            $connection->selectOne('SELECT 1');

            return response()->json(['status' => 'ok']);
        } catch (Throwable) {
            return $this->unhealthy();
        }
    }

    private function unhealthy(): JsonResponse
    {
        return response()->json(['status' => 'unhealthy'], 503);
    }
}
