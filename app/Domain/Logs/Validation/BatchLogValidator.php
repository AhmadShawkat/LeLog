<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\InvalidLogBatch;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use stdClass;

final readonly class BatchLogValidator
{
    private const ALLOWED_ENTRY_PROPERTIES = [
        'timestamp' => true,
        'level' => true,
        'service' => true,
        'message' => true,
        'attributes' => true,
    ];

    private const ALLOWED_LEVELS = ['debug' => true, 'info' => true, 'warn' => true, 'error' => true];

    public function __construct(private Repository $config) {}

    /**
     * @return array{
     *     timestamps: list<string>, services: list<string>, levels: list<string>, messages: list<string>,
     *     attributes: list<string>, attributes_text: list<string>,
     *     rejected: list<array{index: int, reason: string}>,
     *     timings: array{json_decode_ms: float, validation_transform_ms: float}
     * }
     */
    public function validate(string $json, ?DateTimeImmutable $now = null): array
    {
        $decodeStarted = hrtime(true);

        try {
            $body = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidLogBatch('Malformed JSON.');
        }

        $decodeMilliseconds = (hrtime(true) - $decodeStarted) / 1_000_000;
        $validationStarted = hrtime(true);

        if (! $body instanceof stdClass || array_keys(get_object_vars($body)) !== ['logs']) {
            throw new InvalidLogBatch('The body must contain exactly one property: logs.');
        }

        if (! is_array($body->logs) || $body->logs === []) {
            throw new InvalidLogBatch('The logs property must be a non-empty array.');
        }

        $maximum = $this->config->get('logs.ingestion.max_batch_size');

        if (! is_int($maximum) || count($body->logs) > $maximum) {
            throw new InvalidLogBatch("The logs array must contain at most $maximum entries.");
        }

        $timestamps = [];
        $services = [];
        $levels = [];
        $messages = [];
        $attributes = [];
        $attributesText = [];
        $rejected = [];
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tolerance = $this->config->get('logs.ingestion.future_tolerance_seconds');

        if (! is_int($tolerance)) {
            throw new InvalidLogBatch('The ingestion configuration is invalid.');
        }

        $futureCutoff = $now->modify("+$tolerance seconds");
        $jsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $lastTimestampValue = null;
        $lastTimestamp = null;
        $lastTimestampCanonical = null;

        foreach ($body->logs as $index => $entry) {
            if (! $entry instanceof stdClass) {
                $rejected[] = ['index' => $index, 'reason' => 'entry must be an object'];

                continue;
            }

            $values = get_object_vars($entry);
            $unsupportedProperty = false;

            foreach ($values as $key => $_value) {
                if (! isset(self::ALLOWED_ENTRY_PROPERTIES[$key])) {
                    $unsupportedProperty = true;
                    break;
                }
            }

            if ($unsupportedProperty) {
                $rejected[] = ['index' => $index, 'reason' => 'entry contains an unsupported property'];

                continue;
            }

            $timestampValue = $values['timestamp'] ?? null;

            if (is_string($timestampValue) && $timestampValue === $lastTimestampValue && $lastTimestamp !== null) {
                $timestamp = $lastTimestamp;
                $timestampCanonical = $lastTimestampCanonical;
            } else {
                $timestamp = Iso8601Timestamp::parse($timestampValue);
                $timestampCanonical = $timestamp?->format('Y-m-d\TH:i:s.uP');

                if ($timestamp !== null && is_string($timestampValue)) {
                    $lastTimestampValue = $timestampValue;
                    $lastTimestamp = $timestamp;
                    $lastTimestampCanonical = $timestampCanonical;
                }
            }

            if ($timestamp === null || $timestampCanonical === null) {
                $rejected[] = ['index' => $index, 'reason' => 'timestamp must be a valid ISO 8601 value'];

                continue;
            }

            if ($timestamp > $futureCutoff) {
                $rejected[] = ['index' => $index, 'reason' => 'timestamp must not be more than five minutes in the future'];

                continue;
            }

            $level = $values['level'] ?? null;

            if (! is_string($level) || ! isset(self::ALLOWED_LEVELS[$level])) {
                $rejected[] = ['index' => $index, 'reason' => 'level must be one of debug, info, warn, or error'];

                continue;
            }

            $service = $values['service'] ?? null;

            if (! is_string($service) || $service === '') {
                $rejected[] = ['index' => $index, 'reason' => 'service must be a non-empty string'];

                continue;
            }

            $message = $values['message'] ?? null;

            if (! is_string($message) || $message === '') {
                $rejected[] = ['index' => $index, 'reason' => 'message must be a non-empty string'];

                continue;
            }

            $entryAttributes = $values['attributes'] ?? new stdClass;

            if (! $entryAttributes instanceof stdClass) {
                $rejected[] = ['index' => $index, 'reason' => 'attributes must be a flat object'];

                continue;
            }

            $normalizedAttributes = [];
            $invalidAttribute = null;

            foreach (get_object_vars($entryAttributes) as $key => $value) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                    $invalidAttribute = "attributes.$key must be a string, number, or boolean";
                    break;
                }

                $normalizedAttributes[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }

            if ($invalidAttribute !== null) {
                $rejected[] = ['index' => $index, 'reason' => $invalidAttribute];

                continue;
            }

            try {
                $encodedAttributes = json_encode($entryAttributes, $jsonFlags);
                $encodedAttributesText = $this->encodeHstore($normalizedAttributes);
            } catch (JsonException) {
                $rejected[] = ['index' => $index, 'reason' => 'attributes contain an invalid value'];

                continue;
            }

            $timestamps[] = $timestampCanonical;
            $services[] = $service;
            $levels[] = $level;
            $messages[] = $message;
            $attributes[] = $encodedAttributes;
            $attributesText[] = $encodedAttributesText;
        }

        return [
            'timestamps' => $timestamps,
            'services' => $services,
            'levels' => $levels,
            'messages' => $messages,
            'attributes' => $attributes,
            'attributes_text' => $attributesText,
            'rejected' => $rejected,
            'timings' => [
                'json_decode_ms' => $decodeMilliseconds,
                'validation_transform_ms' => (hrtime(true) - $validationStarted) / 1_000_000,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function encodeHstore(array $attributes): string
    {
        $pairs = [];

        foreach ($attributes as $key => $value) {
            $pairs[] = $this->quoteHstore($key).'=>'.$this->quoteHstore($value);
        }

        return implode(',', $pairs);
    }

    private function quoteHstore(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
