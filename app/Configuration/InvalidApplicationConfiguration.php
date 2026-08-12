<?php

namespace App\Configuration;

use RuntimeException;

final class InvalidApplicationConfiguration extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public static function fromErrors(array $errors): self
    {
        return new self('Invalid application configuration: '.implode('; ', $errors));
    }
}
