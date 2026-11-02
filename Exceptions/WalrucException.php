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
    private ?bool $retryable = null;
    private bool $configurationError = false;

    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the failed operation may succeed if it is attempted again later.
     * Unless set, it is inherited from the previous plugin exception: other failures are permanent.
     */
    public function isRetryable(): bool
    {
        if ($this->retryable !== null) {
            return $this->retryable;
        }

        $previous = $this->getPrevious();
        return $previous instanceof self && $previous->isRetryable();
    }

    /**
     * Whether the failure comes from the configuration, so that every other request would fail the same way
     */
    public function isConfigurationError(): bool
    {
        $previous = $this->getPrevious();
        return $this->configurationError || ($previous instanceof self && $previous->isConfigurationError());
    }

    protected function setRetryable(bool $retryable): void
    {
        $this->retryable = $retryable;
    }

    protected function markAsConfigurationError(): void
    {
        $this->configurationError = true;
        $this->retryable = false;
    }
}
