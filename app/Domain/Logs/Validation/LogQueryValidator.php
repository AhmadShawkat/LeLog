<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\Data\LogQuery;
use App\Domain\Logs\InvalidLogQuery;
use Illuminate\Contracts\Config\Repository;

final readonly class LogQueryValidator
{
    public function __construct(
        private Repository $config,
        private CursorCodec $cursorCodec,
        private LogFilterValidator $filterValidator,
    ) {}

    public function validate(string $queryString): LogQuery
    {
        $validated = $this->filterValidator->validate($queryString, ['limit', 'cursor']);
        $parameters = $validated['parameters'];

        $defaultLimit = $this->config->get('logs.query.default_limit');
        $maximumLimit = $this->config->get('logs.query.max_limit');

        if (! is_int($defaultLimit) || ! is_int($maximumLimit)) {
            throw new InvalidLogQuery('The query configuration is invalid.');
        }

        $limit = $defaultLimit;

        if (isset($parameters['limit'])) {
            $limit = filter_var($parameters['limit'], FILTER_VALIDATE_INT, ['options' => [
                'min_range' => 1,
                'max_range' => $maximumLimit,
            ]]);

            if ($limit === false || (string) $limit !== $parameters['limit']) {
                throw new InvalidLogQuery("The limit must be an integer between 1 and $maximumLimit.");
            }
        }

        $cursor = isset($parameters['cursor']) ? $this->cursorCodec->decode($parameters['cursor']) : null;

        return new LogQuery(
            filters: $validated['filters'],
            limit: $limit,
            cursorTimestamp: $cursor['timestamp'] ?? null,
            cursorId: $cursor['id'] ?? null,
        );
    }
}
