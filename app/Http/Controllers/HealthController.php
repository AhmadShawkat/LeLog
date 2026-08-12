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
            $ranMigrations = [];

            foreach ($connection->select('SELECT migration FROM migrations') as $row) {
                $migration = ((array) $row)['migration'] ?? null;

                if (! is_string($migration)) {
                    return $this->unhealthy();
                }

                $ranMigrations[] = $migration;
            }

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
