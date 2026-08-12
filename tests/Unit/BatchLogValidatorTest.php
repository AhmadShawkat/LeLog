<?php

namespace Tests\Unit;

use App\Domain\Logs\InvalidLogBatch;
use App\Domain\Logs\Validation\BatchLogValidator;
use App\Domain\Logs\Validation\LogEntryValidator;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class BatchLogValidatorTest extends TestCase
{
    public function test_it_partially_accepts_a_valid_top_level_batch(): void
    {
        $result = $this->validator()->validate(json_encode([
            'logs' => [
                $this->validEntry(),
                ['timestamp' => 'invalid'],
                $this->validEntry(['level' => 'error', 'message' => 'failed']),
            ],
        ], JSON_THROW_ON_ERROR), new DateTimeImmutable('2026-08-12T12:00:00Z'));

        self::assertSame(2, $result->acceptedCount());
        self::assertSame(['debug', 'error'], $result->levels);
        self::assertSame([['index' => 1, 'reason' => 'timestamp must be a valid ISO 8601 value']], $result->rejected);
    }

    public function test_it_rejects_malformed_or_invalid_top_level_bodies(): void
    {
        $invalidBodies = [
            '{',
            '[]',
            '{}',
            '{"logs":[],"extra":true}',
            '{"logs":[]}',
            '{"logs":{}}',
        ];
        $rejected = 0;

        foreach ($invalidBodies as $json) {
            try {
                $this->validator()->validate($json);
                self::fail("Expected body to be rejected: $json");
            } catch (InvalidLogBatch) {
                $rejected++;
            }
        }

        self::assertCount($rejected, $invalidBodies);
    }

    public function test_it_enforces_the_configured_batch_maximum(): void
    {
        $this->expectException(InvalidLogBatch::class);

        $this->validator(1)->validate(json_encode([
            'logs' => [$this->validEntry(), $this->validEntry()],
        ], JSON_THROW_ON_ERROR));
    }

    private function validator(int $maximum = 1000): BatchLogValidator
    {
        return new BatchLogValidator(new Repository([
            'logs' => ['ingestion' => ['max_batch_size' => $maximum, 'future_tolerance_seconds' => 300]],
        ]), new LogEntryValidator);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validEntry(array $overrides = []): array
    {
        return array_replace([
            'timestamp' => '2026-08-12T12:00:00Z',
            'level' => 'debug',
            'service' => 'api',
            'message' => 'ready',
        ], $overrides);
    }
}
