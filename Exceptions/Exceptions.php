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

final class ConversionException extends WalrucException
{
    public static function invalidResponse(?Throwable $previous = null): self
    {
        return new self('Invalid LRC response: missing output_trace', $previous);
    }

    public static function conversionFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self("Conversion failed: $reason", $previous);
    }
}

final class StorageException extends WalrucException
{
    public static function invalidResponse(?Throwable $previous = null): self
    {
        return new self('Invalid LMS response: missing id', $previous);
    }

    public static function storageFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self("Storage failed: $reason", $previous);
    }
}
