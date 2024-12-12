<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Exceptions;

final class StorageException extends WalrucException
{
    public static function invalidResponse(?Throwable $previous = null): self
    {
        return new self('Invalid LRS response: missing id', $previous);
    }

    public static function storageFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self("Storage failed: $reason", $previous);
    }
}
