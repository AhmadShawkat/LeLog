<?php

namespace Tests\Unit;

use App\Lifecycle\ApplicationLifecycle;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ApplicationLifecycleTest extends TestCase
{
    public function test_shutdown_drains_active_work_and_rejects_new_work(): void
    {
        $lifecycle = new ApplicationLifecycle;

        $result = $lifecycle->run(function () use ($lifecycle): string {
            self::assertSame(1, $lifecycle->activeOperations());

            $lifecycle->requestShutdown();

            self::assertFalse($lifecycle->isAcceptingWork());
            self::assertFalse($lifecycle->isDrained());

            return 'finished';
        });

        self::assertSame('finished', $result);
        self::assertTrue($lifecycle->isDrained());

        $this->expectException(LogicException::class);

        $lifecycle->run(static fn (): null => null);
    }
}
