<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Http;

use Closure;
use Piwik\Log\LoggerInterface;

interface RetryHandlerInterface
{
    public function executeWithRetry(
        Closure $operation,
        LoggerInterface $logger,
        string $operationName,
        int $maxAttempts = 3,
        int $initialDelayMs = 1000,
        float $backoffMultiplier = 2.0,
    ): mixed;
}