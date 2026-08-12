<?php

namespace App\Domain\Logs\Data;

final readonly class ValidatedLogBatch
{
    /**
     * @param  list<string>  $timestamps
     * @param  list<string>  $services
     * @param  list<string>  $levels
     * @param  list<string>  $messages
     * @param  list<string>  $attributes
     * @param  list<string>  $attributesText
     * @param  list<array{index: int, reason: string}>  $rejected
     */
    public function __construct(
        public array $timestamps,
        public array $services,
        public array $levels,
        public array $messages,
        public array $attributes,
        public array $attributesText,
        public array $rejected,
    ) {}

    public function acceptedCount(): int
    {
        return count($this->timestamps);
    }
}
