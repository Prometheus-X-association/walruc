<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Exceptions;

use Exception;
use Throwable;

/**
 * Base exception
 */
abstract class WalrucException extends Exception
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

