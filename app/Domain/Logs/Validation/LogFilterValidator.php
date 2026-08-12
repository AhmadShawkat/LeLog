<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\Data\LogFilters;
use App\Domain\Logs\InvalidLogQuery;
use App\LogLevel;

final class LogFilterValidator
{
    /**
     * @param  list<string>  $additionalParameters
     * @return array{filters: LogFilters, parameters: array<string, string>}
     */
    public function validate(string $queryString, array $additionalParameters = []): array
    {
        $parameters = $this->parameters($queryString);
        $known = array_merge(['service', 'level', 'since', 'until', 'q'], $additionalParameters);
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

        return [
            'filters' => new LogFilters($service, $level, $since, $until, $attributes, $message),
            'parameters' => $parameters,
        ];
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
