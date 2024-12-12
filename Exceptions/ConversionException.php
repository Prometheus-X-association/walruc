<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Exceptions;

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