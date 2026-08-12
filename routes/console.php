<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('logs:retain')
    ->everyMinute()
    ->when(static fn (): bool => time() % (int) config('logs.retention.interval_seconds') < 60)
    ->name('logs:retain')
    ->withoutOverlapping(10);
