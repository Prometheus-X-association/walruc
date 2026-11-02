<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Exceptions;

use Throwable;

final class ExportFileException extends WalrucException
{
    public static function notFound(string $filePath): self
    {
        return new self("File not found: $filePath");
    }

    public static function invalidContent(string $filePath, Throwable $previous): self
    {
        return new self("Invalid export file $filePath: {$previous->getMessage()}", $previous);
    }

    public static function unreadable(string $filePath): self
    {
        return new self("Failed to read file: $filePath");
    }

    public static function unwritable(string $filePath, ?Throwable $previous = null): self
    {
        return new self("Failed to write file: $filePath", $previous);
    }
}
