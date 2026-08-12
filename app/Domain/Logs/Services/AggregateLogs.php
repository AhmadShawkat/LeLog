<?php

namespace App\Domain\Logs\Services;

use App\Domain\Logs\Repositories\LogAggregationRepository;
use App\Domain\Logs\Validation\AggregationQueryValidator;
use App\Lifecycle\ApplicationLifecycle;

final readonly class AggregateLogs
{
    public function __construct(
        private AggregationQueryValidator $validator,
        private LogAggregationRepository $repository,
        private ApplicationLifecycle $lifecycle,
    ) {}

    /**
     * @return array{buckets: list<array{bucket: string, group: ?string, count: int}>}
     */
    public function aggregate(string $queryString): array
    {
        $query = $this->validator->validate($queryString);
        $buckets = $this->lifecycle->run(fn (): array => $this->repository->aggregate($query));

        return ['buckets' => $buckets];
    }
}
