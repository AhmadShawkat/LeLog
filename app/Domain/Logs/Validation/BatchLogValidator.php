<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\Data\ValidatedLogBatch;
use App\Domain\Logs\Data\ValidatedLogEntry;
use App\Domain\Logs\InvalidLogBatch;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use stdClass;

final readonly class BatchLogValidator
{
    public function __construct(
        private Repository $config,
        private LogEntryValidator $entryValidator,
    ) {}

    public function validate(string $json, ?DateTimeImmutable $now = null): ValidatedLogBatch
    {
        try {
            $body = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidLogBatch('Malformed JSON.');
        }

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

        foreach ($body->logs as $index => $entry) {
            $validated = $this->entryValidator->validate($entry, $now, $tolerance);

            if (! $validated instanceof ValidatedLogEntry) {
                $rejected[] = ['index' => $index, 'reason' => $validated];

                continue;
            }

            $timestamps[] = $validated->timestamp;
            $services[] = $validated->service;
            $levels[] = $validated->level;
            $messages[] = $validated->message;
            $attributes[] = $validated->attributes;
            $attributesText[] = $validated->attributesText;
        }

        return new ValidatedLogBatch(
            $timestamps,
            $services,
            $levels,
            $messages,
            $attributes,
            $attributesText,
            $rejected,
        );
    }
}
