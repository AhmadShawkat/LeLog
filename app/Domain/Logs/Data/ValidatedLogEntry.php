<?php

namespace App\Domain\Logs\Data;

final readonly class ValidatedLogEntry
{
    public function __construct(
        public string $timestamp,
        public string $service,
        public string $level,
        public string $message,
        public string $attributes,
        public string $attributesText,
    ) {}
}
