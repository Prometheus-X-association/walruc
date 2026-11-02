<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Exceptions;

use Throwable;

final class HttpException extends WalrucException
{
    /**
     * Statuses meaning that the endpoint or the credentials are wrong
     */
    private const CONFIGURATION_ERROR_STATUSES = [401, 403, 404, 405];

    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, $previous);
        // Network failures and empty responses are usually temporary
        $this->setRetryable(true);
    }

    public static function requestFailed(Throwable $previous): self
    {
        return new self('HTTP request failed: ' . $previous->getMessage(), $previous);
    }

    public static function noTransport(): self
    {
        $exception = new self('No HTTP transport available (curl, fopen or socket)');
        $exception->markAsConfigurationError();

        return $exception;
    }

    public static function invalidUrl(string $url): self
    {
        $exception = new self("Invalid URL: '$url'");
        $exception->markAsConfigurationError();

        return $exception;
    }

    public static function unexpectedStatus(int $status): self
    {
        $exception = new self("Unexpected HTTP status: $status");
        if (in_array($status, self::CONFIGURATION_ERROR_STATUSES, true)) {
            $exception->markAsConfigurationError();
        } else {
            // Client errors are permanent, except timeouts and rate limiting.
            // Status 0 means that no response was received.
            $exception->setRetryable($status === 0 || $status >= 500 || in_array($status, [408, 429], true));
        }

        return $exception;
    }
}
