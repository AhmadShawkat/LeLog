<?php

namespace App\Domain\Logs\Services;

use App\Domain\Logs\Repositories\LogIngestionRepository;
use App\Domain\Logs\Validation\BatchLogValidator;
use App\Lifecycle\ApplicationLifecycle;
use RuntimeException;

final readonly class IngestLogs
{
    public function __construct(
        private BatchLogValidator $validator,
        private LogIngestionRepository $repository,
        private ApplicationLifecycle $lifecycle,
    ) {}

    /**
     * @return array{accepted: int, rejected: list<array{index: int, reason: string}>}
     */
    public function ingest(string $json): array
    {
        $batch = $this->validator->validate($json);
        $expected = $batch->acceptedCount();

        if ($expected === 0) {
            return ['accepted' => 0, 'rejected' => $batch->rejected];
        }

        $inserted = $this->lifecycle->run(fn (): int => $this->repository->insert($batch));

        if ($inserted !== $expected) {
            throw new RuntimeException('The durable inserted row count did not match the accepted batch count.');
        }

        return ['accepted' => $inserted, 'rejected' => $batch->rejected];
    }
}
