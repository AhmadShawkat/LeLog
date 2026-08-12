<?php

namespace App\Lifecycle;

use LogicException;

final class ApplicationLifecycle
{
    private bool $shutdownRequested = false;

    private int $activeOperations = 0;

    /**
     * Run bounded work while guaranteeing that the active-operation count is released.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        if ($this->shutdownRequested) {
            throw new LogicException('Application shutdown is already in progress.');
        }

        $this->activeOperations++;

        try {
            return $operation();
        } finally {
            $this->activeOperations--;
        }
    }

    public function requestShutdown(): void
    {
        $this->shutdownRequested = true;
    }

    public function isAcceptingWork(): bool
    {
        return ! $this->shutdownRequested;
    }

    public function activeOperations(): int
    {
        return $this->activeOperations;
    }

    public function isDrained(): bool
    {
        return $this->shutdownRequested && $this->activeOperations === 0;
    }
}
