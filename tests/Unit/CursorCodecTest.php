<?php

namespace Tests\Unit;

use App\Domain\Logs\InvalidLogQuery;
use App\Domain\Logs\Validation\CursorCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CursorCodecTest extends TestCase
{
    public function test_it_round_trips_a_canonical_versioned_cursor(): void
    {
        $codec = new CursorCodec;
        $cursor = $codec->encode('2026-08-12T12:00:00.123456+00:00', 9223372036854775807);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $cursor);
        self::assertSame([
            'timestamp' => '2026-08-12T12:00:00.123456Z',
            'id' => 9223372036854775807,
        ], $codec->decode($cursor));
    }

    #[DataProvider('invalidCursors')]
    public function test_it_strictly_rejects_malformed_or_noncanonical_cursors(string $cursor): void
    {
        $this->expectException(InvalidLogQuery::class);

        (new CursorCodec)->decode($cursor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'invalid alphabet' => ['***'];
        yield 'invalid json' => [self::encode('no')];
        yield 'wrong version' => [self::encode('{"v":2,"timestamp":"2026-08-12T12:00:00.000000Z","id":"1"}')];
        yield 'extra property' => [self::encode('{"v":1,"timestamp":"2026-08-12T12:00:00.000000Z","id":"1","x":true}')];
        yield 'noncanonical timestamp' => [self::encode('{"v":1,"timestamp":"2026-08-12T12:00:00Z","id":"1"}')];
        yield 'zero id' => [self::encode('{"v":1,"timestamp":"2026-08-12T12:00:00.000000Z","id":"0"}')];
        yield 'numeric id' => [self::encode('{"v":1,"timestamp":"2026-08-12T12:00:00.000000Z","id":1}')];
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
