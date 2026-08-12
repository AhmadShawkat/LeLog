<?php

namespace Tests\Unit;

use App\Domain\Logs\Validation\PostgreSqlArrayEncoder;
use PHPUnit\Framework\TestCase;

final class PostgreSqlArrayEncoderTest extends TestCase
{
    public function test_it_encodes_postgresql_array_edge_cases_without_confusing_null_and_strings(): void
    {
        $encoded = (new PostgreSqlArrayEncoder)->encode([
            '',
            'NULL',
            null,
            'comma,value',
            '{brace}',
            'say "hello"',
            'back\\slash',
            'unicode ✓',
            '{"key":"comma,value"}',
        ]);

        self::assertSame(
            '{"","NULL",NULL,"comma,value","{brace}","say \\"hello\\"","back\\\\slash","unicode ✓","{\\"key\\":\\"comma,value\\"}"}',
            $encoded,
        );
    }

    public function test_it_encodes_an_empty_array(): void
    {
        self::assertSame('{}', (new PostgreSqlArrayEncoder)->encode([]));
    }
}
