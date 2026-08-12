<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\Data\ValidatedLogEntry;
use App\LogLevel;
use DateTimeImmutable;
use JsonException;
use stdClass;

final class LogEntryValidator
{
    private const TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';

    /**
     * @return ValidatedLogEntry|string A validated entry or its rejection reason.
     */
    public function validate(mixed $entry, DateTimeImmutable $now, int $futureToleranceSeconds): ValidatedLogEntry|string
    {
        if (! $entry instanceof stdClass) {
            return 'entry must be an object';
        }

        $values = get_object_vars($entry);
        $unknown = array_diff(array_keys($values), ['timestamp', 'level', 'service', 'message', 'attributes']);

        if ($unknown !== []) {
            return 'entry contains an unsupported property';
        }

        $timestamp = $this->timestamp($values['timestamp'] ?? null);

        if (is_string($timestamp)) {
            return $timestamp;
        }

        if ($timestamp > $now->modify("+$futureToleranceSeconds seconds")) {
            return 'timestamp must not be more than five minutes in the future';
        }

        $level = $values['level'] ?? null;

        if (! is_string($level) || LogLevel::tryFrom($level) === null) {
            return 'level must be one of debug, info, warn, or error';
        }

        $service = $values['service'] ?? null;

        if (! is_string($service) || $service === '') {
            return 'service must be a non-empty string';
        }

        $message = $values['message'] ?? null;

        if (! is_string($message) || $message === '') {
            return 'message must be a non-empty string';
        }

        $attributes = $values['attributes'] ?? new stdClass;

        if (! $attributes instanceof stdClass) {
            return 'attributes must be a flat object';
        }

        $attributesText = new stdClass;

        foreach (get_object_vars($attributes) as $key => $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                return "attributes.$key must be a string, number, or boolean";
            }

            $attributesText->{$key} = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        try {
            $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

            return new ValidatedLogEntry(
                timestamp: $timestamp->format('Y-m-d\TH:i:s.uP'),
                service: $service,
                level: $level,
                message: $message,
                attributes: json_encode($attributes, $jsonFlags),
                attributesText: json_encode($attributesText, $jsonFlags),
            );
        } catch (JsonException) {
            return 'attributes contain an invalid value';
        }
    }

    private function timestamp(mixed $value): DateTimeImmutable|string
    {
        if (! is_string($value) || preg_match(self::TIMESTAMP_PATTERN, $value) !== 1) {
            return 'timestamp must be a valid ISO 8601 value';
        }

        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $format = str_contains($normalized, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $timestamp = DateTimeImmutable::createFromFormat($format, $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if ($timestamp === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return 'timestamp must be a valid ISO 8601 value';
        }

        return $timestamp;
    }
}
