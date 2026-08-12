<?php

namespace App;

enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARN = 'warn';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            LogLevel::DEBUG => 'DEBUG',
            LogLevel::INFO => 'INFO',
            LogLevel::WARN => 'WARN',
            LogLevel::ERROR => 'ERROR',
        };
    }
}
