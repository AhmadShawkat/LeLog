<?php

namespace App\Console\Commands;

use App\Domain\Logs\Services\RunLogRetention;
use App\Lifecycle\ApplicationLifecycle;
use Illuminate\Console\Command;

final class RunLogRetentionCommand extends Command
{
    protected $signature = 'logs:retain';

    protected $description = 'Delete a bounded number of expired logs';

    public function handle(RunLogRetention $retention, ApplicationLifecycle $lifecycle): int
    {
        $this->trap(fn (): array => [SIGINT, SIGTERM, SIGQUIT], $lifecycle->requestShutdown(...));

        $deleted = $retention->run();

        if ($deleted === null) {
            $this->components->info('Retention skipped because another run is active or shutdown has started.');

            return self::SUCCESS;
        }

        $this->components->info("Deleted $deleted expired logs.");

        return self::SUCCESS;
    }
}
