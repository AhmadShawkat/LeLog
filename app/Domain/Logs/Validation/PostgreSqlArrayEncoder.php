<?php

namespace App\Domain\Logs\Validation;

final class PostgreSqlArrayEncoder
{
    /**
     * @param  list<string|null>  $values
     */
    public function encode(array $values): string
    {
        $encoded = array_map(
            static fn (?string $value): string => $value === null
                ? 'NULL'
                : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
            $values,
        );

        return '{'.implode(',', $encoded).'}';
    }
}
