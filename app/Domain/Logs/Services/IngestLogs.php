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
     * @return array{
     *     accepted: int,
     *     rejected: list<array{index: int, reason: string}>,
     *     timings: array{
     *         json_decode_ms: float, validation_transform_ms: float, array_encoding_ms: float,
     *         connection_wait_ms: float, sql_execution_ms: float
     *     }
     * }
     */
    public function ingest(string $json): array
    {
        $batch = $this->validator->validate($json);
        $expected = count($batch['timestamps']);

        if ($expected === 0) {
            return [
                'accepted' => 0,
                'rejected' => $batch['rejected'],
                'timings' => $batch['timings'] + [
                    'array_encoding_ms' => 0.0,
                    'connection_wait_ms' => 0.0,
                    'sql_execution_ms' => 0.0,
                ],
            ];
        }

        $persistence = $this->lifecycle->run(fn (): array => $this->repository->insert($batch));
        $inserted = $persistence['inserted'];

        if ($inserted !== $expected) {
            throw new RuntimeException('The durable inserted row count did not match the accepted batch count.');
        }

        return [
            'accepted' => $inserted,
            'rejected' => $batch['rejected'],
            'timings' => $batch['timings'] + $persistence['timings'],
        ];
    }
}
