<?php

namespace App\Domain\Logs\Validation;

final class PostgreSqlArrayEncoder
{
    /**
     * @param  list<string|null>  $values
     */
    public function encode(array $values): string
    {
        $encoded = [];

        foreach ($values as $value) {
            $encoded[] = $value === null
                ? 'NULL'
                : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return '{'.implode(',', $encoded).'}';
    }
}
