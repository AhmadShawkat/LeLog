<?php

namespace App\Domain\Logs\Validation;

use App\Domain\Logs\InvalidLogQuery;
use JsonException;

final class CursorCodec
{
    /**
     * @return array{timestamp: string, id: int}
     */
    public function decode(string $cursor): array
    {
        if ($cursor === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            throw new InvalidLogQuery('The cursor is malformed.');
        }

        $padding = (4 - strlen($cursor) % 4) % 4;
        $json = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', $padding), true);

        if ($json === false || $this->base64Url($json) !== $cursor) {
            throw new InvalidLogQuery('The cursor is malformed.');
        }

        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidLogQuery('The cursor is malformed.');
        }

        if (! is_array($payload) || array_keys($payload) !== ['v', 'timestamp', 'id'] || $payload['v'] !== 1) {
            throw new InvalidLogQuery('The cursor is malformed.');
        }

        $timestamp = Iso8601Timestamp::parse($payload['timestamp']);
        $id = filter_var($payload['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($timestamp === null || ! is_string($payload['id']) || $id === false) {
            throw new InvalidLogQuery('The cursor is malformed.');
        }

        $canonicalTimestamp = Iso8601Timestamp::canonical($timestamp);

        if ($payload['timestamp'] !== $canonicalTimestamp || (string) $id !== $payload['id']) {
            throw new InvalidLogQuery('The cursor is malformed.');
        }

        return ['timestamp' => $canonicalTimestamp, 'id' => $id];
    }

    public function encode(string $timestamp, int $id): string
    {
        $parsed = Iso8601Timestamp::parse($timestamp);

        if ($parsed === null || $id < 1) {
            throw new InvalidLogQuery('A cursor could not be created.');
        }

        try {
            $json = json_encode([
                'v' => 1,
                'timestamp' => Iso8601Timestamp::canonical($parsed),
                'id' => (string) $id,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidLogQuery('A cursor could not be created.');
        }

        return $this->base64Url($json);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
