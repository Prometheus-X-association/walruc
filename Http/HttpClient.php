<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Http;

use Piwik\Http;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\HttpException;

class HttpClient implements HttpClientInterface
{
    use RetryableHttpTrait;

    private const TIMEOUT_SECONDS = 10;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function sendRequest(string $url, string $method, string $body, array $headers, int $timeout = self::TIMEOUT_SECONDS): string
    {
        return $this->executeWithRetry(
            operation: function () use ($url, $method, $body, $headers, $timeout): string {
                $response = Http::sendHttpRequestBy(
                    Http::getTransportMethod(),
                    $url,
                    $timeout,
                    $method,
                    $body,
                    $headers,
                );

                if (!$response) {
                    throw new HttpException('Empty response received');
                }

                return $response;
            },
            logger: $this->logger,
            operationName: "HTTP {$method} request to {$url}",
        );
    }
}