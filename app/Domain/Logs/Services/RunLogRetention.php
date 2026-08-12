<?php

namespace App\Domain\Logs\Services;

use App\Domain\Logs\Repositories\LogRetentionRepository;
use App\Lifecycle\ApplicationLifecycle;
use DateTimeImmutable;
use DateTimeZone;

final readonly class RunLogRetention
{
    public function __construct(
        private LogRetentionRepository $repository,
        private ApplicationLifecycle $lifecycle,
    ) {}

    public function run(): ?int
    {
        if (! $this->lifecycle->isAcceptingWork()) {
            return null;
        }

        $days = (int) config('logs.retention.days');
        $batchSize = (int) config('logs.retention.batch_size');
        $maximumBatches = (int) config('logs.retention.max_batches_per_run');
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-$days days");

        return $this->repository->withLock(function () use ($cutoff, $batchSize, $maximumBatches): int {
            $deleted = 0;

            for ($batch = 0; $batch < $maximumBatches && $this->lifecycle->isAcceptingWork(); $batch++) {
                $count = $this->lifecycle->run(
                    fn (): int => $this->repository->deleteBatch($cutoff, $batchSize),
                );
                $deleted += $count;

                if ($count < $batchSize) {
                    break;
                }
            }

            return $deleted;
        });
    }
}
