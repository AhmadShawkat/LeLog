<?php

namespace Tests\Unit;

use App\Configuration\ConfigurationValidator;
use App\Configuration\InvalidApplicationConfiguration;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class ConfigurationValidatorTest extends TestCase
{
    public function test_missing_configuration_is_rejected(): void
    {
        $this->expectException(InvalidApplicationConfiguration::class);
        $this->expectExceptionMessage('logs.ingestion.max_batch_size');

        (new ConfigurationValidator(new Repository))->validate();
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        $configuration = $this->validConfiguration();
        $configuration->set('logs.query.default_limit', 101);
        $configuration->set('logs.query.max_limit', 100);

        $this->expectException(InvalidApplicationConfiguration::class);
        $this->expectExceptionMessage('logs.query.default_limit must not exceed logs.query.max_limit');

        (new ConfigurationValidator($configuration))->validate();
    }

    public function test_debug_mode_is_rejected_in_production(): void
    {
        $configuration = $this->validConfiguration();
        $configuration->set('app.env', 'production');
        $configuration->set('app.debug', true);

        $this->expectException(InvalidApplicationConfiguration::class);
        $this->expectExceptionMessage('app.debug must be false in production');

        (new ConfigurationValidator($configuration))->validate();
    }

    public function test_retention_interval_must_be_a_supported_minute_multiple(): void
    {
        $configuration = $this->validConfiguration();
        $configuration->set('logs.retention.interval_seconds', 61);

        $this->expectException(InvalidApplicationConfiguration::class);
        $this->expectExceptionMessage('logs.retention.interval_seconds must be a multiple of 60');

        (new ConfigurationValidator($configuration))->validate();
    }

    private function validConfiguration(): Repository
    {
        return new Repository([
            'app' => [
                'env' => 'testing',
                'debug' => false,
            ],
            'logs' => [
                'ingestion' => [
                    'max_batch_size' => 1000,
                    'future_tolerance_seconds' => 300,
                ],
                'query' => [
                    'default_limit' => 100,
                    'max_limit' => 1000,
                ],
                'retention' => [
                    'days' => 30,
                    'interval_seconds' => 60,
                    'batch_size' => 10000,
                    'max_batches_per_run' => 10,
                ],
                'runtime' => [
                    'database_worker_limit' => 4,
                    'shutdown_timeout_seconds' => 30,
                ],
            ],
        ]);
    }
}
