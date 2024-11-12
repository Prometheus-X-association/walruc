<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Http;

use Closure;
use Exception;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\HttpException;

trait RetryableHttpTrait
{
    /**
     * Executes a given operation with retry logic, applying an exponentially increasing delay between attempts.
     *
     * @param Closure $operation The operation to be executed. It should be provided as a closure with no parameters.
     * @param LoggerInterface $logger The logger instance used to record warnings and errors.
     * @param string $operationName A descriptive name for the operation, used in logging messages.
     * @param int $maxAttempts Maximum number of attempts before throwing the final exception. Default is 3.
     * @param int $initialDelayMs The initial delay in milliseconds before retrying. Default is 1000ms (1 second).
     * @param float $backoffMultiplier The multiplier for exponential backoff. Default is 2.0, doubling the delay each retry.
     *
     * @return mixed                    The result of the operation if it succeeds.
     *
     * @throws HttpException            Throws the last exception encountered if all attempts fail.
     */
    private function executeWithRetry(
        Closure $operation,
        LoggerInterface $logger,
        string $operationName,
        int $maxAttempts = 3,
        int $initialDelayMs = 1000,
        float $backoffMultiplier = 2.0,
    ): mixed {
        $attempt = 1;

        do {
            try {
                return $operation();
            } catch (Exception $e) {
                if ($attempt >= $maxAttempts) {
                    $logger->error(
                        sprintf('%s failed permanently after %d attempts', $operationName, $maxAttempts),
                        [
                            'last_error' => $e->getMessage(),
                            'operation' => $operationName,
                        ],
                    );
                    throw $e;
                }

                $delayMs = $initialDelayMs * pow($backoffMultiplier, $attempt - 1);
                $logger->warning(
                    sprintf('%s failed, retrying in %dms', $operationName, $delayMs),
                    [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'operation' => $operationName,
                    ],
                );

                usleep((int)$delayMs * 1000);
                $attempt++;
            }
        } while ($attempt <= $maxAttempts);

        return null;
    }
}