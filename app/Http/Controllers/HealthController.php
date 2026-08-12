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
            if (! $lifecycle->isAcceptingWork() || ! $migrator->repositoryExists()) {
                return $this->unhealthy();
            }

            $migrationFiles = $migrator->getMigrationFiles(database_path('migrations'));
            $ranMigrations = $migrator->getRepository()->getRan();

            if (array_diff(array_keys($migrationFiles), $ranMigrations) !== []) {
                return $this->unhealthy();
            }

            DB::selectOne('SELECT 1');

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
