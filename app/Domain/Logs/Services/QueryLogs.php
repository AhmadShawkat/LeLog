<?php

namespace App\Domain\Logs\Services;

use App\Domain\Logs\Repositories\LogQueryRepository;
use App\Domain\Logs\Validation\CursorCodec;
use App\Domain\Logs\Validation\LogQueryValidator;
use App\Lifecycle\ApplicationLifecycle;

final readonly class QueryLogs
{
    public function __construct(
        private LogQueryValidator $validator,
        private LogQueryRepository $repository,
        private CursorCodec $cursorCodec,
        private ApplicationLifecycle $lifecycle,
    ) {}

    /**
     * @return array{logs: list<array{id: int, timestamp: string, received_at: string, service: string, level: string, message: string, attributes: object}>, next_cursor: ?string}
     */
    public function query(string $queryString): array
    {
        $query = $this->validator->validate($queryString);
        $logs = $this->lifecycle->run(fn (): array => $this->repository->find($query));
        $hasNextPage = count($logs) > $query->limit;

        if ($hasNextPage) {
            array_pop($logs);
        }

        $nextCursor = $hasNextPage
            ? $this->cursorCodec->encode($logs[$query->limit - 1]['timestamp'], $logs[$query->limit - 1]['id'])
            : null;

        return ['logs' => $logs, 'next_cursor' => $nextCursor];
    }
}
