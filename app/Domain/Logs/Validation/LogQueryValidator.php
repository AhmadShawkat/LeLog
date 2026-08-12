<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\Data\LogQuery;
use App\Domain\Logs\InvalidLogQuery;
use App\LogLevel;
use Illuminate\Contracts\Config\Repository;

final readonly class LogQueryValidator
{
    public function __construct(
        private Repository $config,
        private CursorCodec $cursorCodec,
    ) {}

    public function validate(string $queryString): LogQuery
    {
        $parameters = $this->parameters($queryString);
        $known = ['service', 'level', 'since', 'until', 'q', 'limit', 'cursor'];
        $attributes = [];

        foreach ($parameters as $key => $value) {
            if (str_starts_with($key, 'attr.')) {
                $attributeKey = substr($key, 5);

                if ($attributeKey === '') {
                    throw new InvalidLogQuery('Attribute filters must use attr.<key>.');
                }

                $attributes[$attributeKey] = $value;

                continue;
            }

            if (! in_array($key, $known, true)) {
                throw new InvalidLogQuery("Unsupported query parameter: $key.");
            }
        }

        $service = $parameters['service'] ?? null;
        $message = $parameters['q'] ?? null;

        if ($service === '') {
            throw new InvalidLogQuery('The service filter must not be empty.');
        }

        if ($message === '') {
            throw new InvalidLogQuery('The q filter must not be empty.');
        }

        $level = $parameters['level'] ?? null;

        if ($level !== null && LogLevel::tryFrom($level) === null) {
            throw new InvalidLogQuery('The level filter is invalid.');
        }

        $since = $this->timestamp($parameters, 'since');
        $until = $this->timestamp($parameters, 'until');

        if ($since !== null && $until !== null && $since >= $until) {
            throw new InvalidLogQuery('The since timestamp must be earlier than until.');
        }

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
            service: $service,
            level: $level,
            since: $since,
            until: $until,
            attributes: $attributes,
            message: $message,
            limit: $limit,
            cursorTimestamp: $cursor['timestamp'] ?? null,
            cursorId: $cursor['id'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }

        $parameters = [];

        foreach (explode('&', $queryString) as $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');

            if ($rawKey === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $part) === 1) {
                throw new InvalidLogQuery('The query string is malformed.');
            }

            $key = urldecode($rawKey);
            $value = urldecode($rawValue);

            if (preg_match('//u', $key.$value) !== 1 || array_key_exists($key, $parameters)) {
                throw new InvalidLogQuery('Query parameters must be valid and unique.');
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function timestamp(array $parameters, string $key): ?string
    {
        if (! isset($parameters[$key])) {
            return null;
        }

        $timestamp = Iso8601Timestamp::parse($parameters[$key]);

        if ($timestamp === null) {
            throw new InvalidLogQuery("The $key timestamp is invalid.");
        }

        return Iso8601Timestamp::canonical($timestamp);
    }
}
