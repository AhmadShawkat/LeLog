<?php

namespace App\Configuration;

use Illuminate\Contracts\Config\Repository;

final readonly class ConfigurationValidator
{
    public function __construct(private Repository $config) {}

    public function validate(): void
    {
        $errors = [];

        $this->validateBoolean('app.debug', $errors);
        $this->validateInteger('logs.ingestion.max_batch_size', 1, 1000, $errors);
        $this->validateInteger('logs.ingestion.future_tolerance_seconds', 0, 300, $errors);
        $defaultLimit = $this->validateInteger('logs.query.default_limit', 1, 1000, $errors);
        $maximumLimit = $this->validateInteger('logs.query.max_limit', 1, 1000, $errors);
        $this->validateInteger('logs.retention.days', 1, 3650, $errors);
        $retentionInterval = $this->validateInteger('logs.retention.interval_seconds', 60, 86400, $errors);
        $this->validateInteger('logs.retention.batch_size', 1, 100000, $errors);
        $this->validateInteger('logs.retention.max_batches_per_run', 1, 1000, $errors);
        $this->validateInteger('logs.runtime.database_worker_limit', 1, 4, $errors);
        $this->validateInteger('logs.runtime.shutdown_timeout_seconds', 1, 300, $errors);

        if ($defaultLimit !== null && $maximumLimit !== null && $defaultLimit > $maximumLimit) {
            $errors[] = 'logs.query.default_limit must not exceed logs.query.max_limit';
        }

        if ($retentionInterval !== null && $retentionInterval % 60 !== 0) {
            $errors[] = 'logs.retention.interval_seconds must be a multiple of 60';
        }

        if ($this->config->get('app.env') === 'production' && $this->config->get('app.debug') !== false) {
            $errors[] = 'app.debug must be false in production';
        }

        if ($errors !== []) {
            throw InvalidApplicationConfiguration::fromErrors($errors);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateBoolean(string $key, array &$errors): void
    {
        if (! is_bool($this->config->get($key))) {
            $errors[] = "$key must be a boolean";
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateInteger(string $key, int $minimum, int $maximum, array &$errors): ?int
    {
        $value = $this->config->get($key);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            $errors[] = "$key must be an integer between $minimum and $maximum";

            return null;
        }

        return $value;
    }
}
