<?php

namespace Tests\Unit;

use App\Domain\Logs\Validation\BatchLogValidator;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use stdClass;

final class BatchLogValidatorEntryTest extends TestCase
{
    private BatchLogValidator $validator;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->validator = new BatchLogValidator(new Repository([
            'logs' => ['ingestion' => ['max_batch_size' => 1000, 'future_tolerance_seconds' => 300]],
        ]));
        $this->now = new DateTimeImmutable('2026-08-12T12:00:00+00:00');
    }

    public function test_it_validates_and_normalizes_one_entry(): void
    {
        $entry = $this->object([
            'timestamp' => '2026-08-12T11:59:59.123456Z',
            'level' => 'info',
            'service' => 'checkout',
            'message' => 'paid ✓',
            'attributes' => $this->object([
                'request_id' => 'abc',
                'attempt' => 2,
                'ratio' => 1.5,
                'cached' => false,
            ]),
        ]);

        $result = $this->validator->validate(json_encode(['logs' => [$entry]], JSON_THROW_ON_ERROR), $this->now);

        self::assertSame(['2026-08-12T11:59:59.123456+00:00'], $result['timestamps']);
        self::assertSame(['{"request_id":"abc","attempt":2,"ratio":1.5,"cached":false}'], $result['attributes']);
        self::assertSame(['{"request_id":"abc","attempt":"2","ratio":"1.5","cached":"false"}'], $result['attributes_text']);
    }

    public function test_attributes_are_optional_and_stay_json_objects(): void
    {
        $result = $this->validator->validate(
            json_encode(['logs' => [$this->validEntry()]], JSON_THROW_ON_ERROR),
            $this->now,
        );

        self::assertSame(['{}'], $result['attributes']);
        self::assertSame(['{}'], $result['attributes_text']);
    }

    public function test_each_invalid_entry_returns_one_reason_without_throwing(): void
    {
        $cases = [
            'not an object' => [],
            'bad timestamp' => $this->validEntry(['timestamp' => 'tomorrow']),
            'future timestamp' => $this->validEntry(['timestamp' => '2026-08-12T12:05:01Z']),
            'bad level' => $this->validEntry(['level' => 'notice']),
            'empty service' => $this->validEntry(['service' => '']),
            'empty message' => $this->validEntry(['message' => '']),
            'attributes array' => $this->validEntry(['attributes' => ['not', 'an', 'object']]),
            'nested attribute' => $this->validEntry(['attributes' => $this->object(['nested' => new stdClass])]),
            'null attribute' => $this->validEntry(['attributes' => $this->object(['nullable' => null])]),
            'unknown property' => $this->validEntry(['extra' => true]),
        ];

        foreach ($cases as $name => $entry) {
            $result = $this->validator->validate(
                json_encode(['logs' => [$entry]], JSON_THROW_ON_ERROR),
                $this->now,
            );

            self::assertCount(1, $result['rejected'], "The $name case should be rejected.");
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function validEntry(array $overrides = []): stdClass
    {
        return $this->object(array_replace([
            'timestamp' => '2026-08-12T12:00:00Z',
            'level' => 'debug',
            'service' => 'api',
            'message' => 'ready',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function object(array $values): stdClass
    {
        $object = new stdClass;

        foreach ($values as $key => $value) {
            $object->{$key} = $value;
        }

        return $object;
    }
}
