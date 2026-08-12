<?php

namespace Tests\Unit;

use App\Domain\Logs\InvalidLogQuery;
use App\Domain\Logs\Validation\CursorCodec;
use App\Domain\Logs\Validation\LogQueryValidator;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LogQueryValidatorTest extends TestCase
{
    public function test_it_validates_all_freely_combinable_filters(): void
    {
        $cursor = (new CursorCodec)->encode('2026-08-12T12:00:00.000000Z', 42);
        $query = $this->validator()->validate(http_build_query([
            'service' => 'checkout',
            'level' => 'warn',
            'since' => '2026-08-12T10:00:00+02:00',
            'until' => '2026-08-12T11:00:00Z',
            'attr.request_id' => 'abc',
            'attr.attempt' => '2',
            'q' => 'failed',
            'limit' => '25',
            'cursor' => $cursor,
        ]));

        self::assertSame('checkout', $query->service);
        self::assertSame('warn', $query->level);
        self::assertSame('2026-08-12T08:00:00.000000Z', $query->since);
        self::assertSame('2026-08-12T11:00:00.000000Z', $query->until);
        self::assertSame(['request_id' => 'abc', 'attempt' => '2'], $query->attributes);
        self::assertSame('failed', $query->message);
        self::assertSame(25, $query->limit);
        self::assertSame('2026-08-12T12:00:00.000000Z', $query->cursorTimestamp);
        self::assertSame(42, $query->cursorId);
    }

    public function test_it_uses_the_configured_default_limit(): void
    {
        self::assertSame(100, $this->validator()->validate('')->limit);
    }

    #[DataProvider('invalidQueries')]
    public function test_it_rejects_invalid_queries(string $queryString): void
    {
        $this->expectException(InvalidLogQuery::class);

        $this->validator()->validate($queryString);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'unsupported parameter' => ['unknown=true'];
        yield 'duplicate parameter' => ['service=a&service=b'];
        yield 'empty service' => ['service='];
        yield 'empty query' => ['q='];
        yield 'invalid level' => ['level=fatal'];
        yield 'invalid since' => ['since=tomorrow'];
        yield 'invalid until' => ['until=tomorrow'];
        yield 'reversed range' => ['since=2026-08-12T12%3A00%3A00Z&until=2026-08-12T12%3A00%3A00Z'];
        yield 'zero limit' => ['limit=0'];
        yield 'too large limit' => ['limit=1001'];
        yield 'noncanonical integer' => ['limit=01'];
        yield 'malformed attribute' => ['attr.=value'];
        yield 'malformed cursor' => ['cursor=no'];
        yield 'malformed percent encoding' => ['service=%ZZ'];
    }

    private function validator(): LogQueryValidator
    {
        return new LogQueryValidator(new Repository([
            'logs' => ['query' => ['default_limit' => 100, 'max_limit' => 1000]],
        ]), new CursorCodec);
    }
}
