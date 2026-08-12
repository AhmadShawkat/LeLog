<?php

namespace App\Domain\Logs\Validation;

use DateTimeImmutable;

final class Iso8601Timestamp
{
    private const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';

    public static function parse(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $format = str_contains($normalized, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $timestamp = DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if ($timestamp === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $timestamp;
    }

    public static function canonical(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
