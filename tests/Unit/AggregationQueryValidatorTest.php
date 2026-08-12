<?php

namespace Tests\Unit;

use App\Domain\Logs\InvalidLogQuery;
use App\Domain\Logs\Validation\AggregationQueryValidator;
use App\Domain\Logs\Validation\LogFilterValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AggregationQueryValidatorTest extends TestCase
{
    public function test_it_validates_required_fields_grouping_and_all_shared_filters(): void
    {
        $query = $this->validator()->validate(http_build_query([
            'since' => '2026-08-12T10:00:00+02:00',
            'until' => '2026-08-12T11:00:00Z',
            'bucket' => '5m',
            'group_by' => 'service',
            'service' => 'checkout',
            'level' => 'warn',
            'attr.request_id' => 'abc',
            'q' => 'failed',
        ]));

        self::assertSame('2026-08-12T08:00:00.000000Z', $query->filters->since);
        self::assertSame('2026-08-12T11:00:00.000000Z', $query->filters->until);
        self::assertSame('checkout', $query->filters->service);
        self::assertSame('warn', $query->filters->level);
        self::assertSame(['request_id' => 'abc'], $query->filters->attributes);
        self::assertSame('failed', $query->filters->message);
        self::assertSame("INTERVAL '5 minutes'", $query->bucketInterval);
        self::assertSame('service', $query->groupExpression);
    }

    public function test_it_maps_an_omitted_group_to_sql_null(): void
    {
        $query = $this->validator()->validate('since=2026-08-12T10%3A00%3A00Z&until=2026-08-12T11%3A00%3A00Z&bucket=1h');

        self::assertSame('NULL::text', $query->groupExpression);
    }

    #[DataProvider('invalidQueries')]
    public function test_it_rejects_invalid_aggregation_queries(string $queryString): void
    {
        $this->expectException(InvalidLogQuery::class);

        $this->validator()->validate($queryString);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        $range = 'since=2026-08-12T10%3A00%3A00Z&until=2026-08-12T11%3A00%3A00Z';

        yield 'missing since' => ['until=2026-08-12T11%3A00%3A00Z&bucket=1h'];
        yield 'missing until' => ['since=2026-08-12T10%3A00%3A00Z&bucket=1h'];
        yield 'missing bucket' => [$range];
        yield 'invalid bucket' => ["$range&bucket=2m"];
        yield 'invalid group' => ["$range&bucket=1h&group_by=message"];
        yield 'empty group' => ["$range&bucket=1h&group_by="];
        yield 'reversed range' => ['since=2026-08-12T11%3A00%3A00Z&until=2026-08-12T10%3A00%3A00Z&bucket=1h'];
        yield 'query pagination is unsupported' => ["$range&bucket=1h&limit=10"];
        yield 'group injection' => ["$range&bucket=1h&group_by=service%2C%20message"];
    }

    private function validator(): AggregationQueryValidator
    {
        return new AggregationQueryValidator(new LogFilterValidator);
    }
}
