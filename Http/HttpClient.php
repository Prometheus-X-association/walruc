<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Http;

use Piwik\Http;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\HttpException;

class HttpClient implements HttpClientInterface
{
    private const TIMEOUT_SECONDS = 10;

    private LoggerInterface $logger;
    private RetryHandlerInterface $retryHandler;

    public function __construct(LoggerInterface $logger, RetryHandlerInterface $retryHandler)
    {
        $this->logger = $logger;
        $this->retryHandler = $retryHandler;
    }

    public function sendRequest(string $url, string $method, string $body, array $headers, int $timeout = self::TIMEOUT_SECONDS): string
    {
        return $this->retryHandler->executeWithRetry(
            operation: function () use ($url, $method, $body, $headers, $timeout): string {
                $response = Http::sendHttpRequestBy(
                    method: Http::getTransportMethod(),
                    aUrl: $url,
                    timeout: $timeout,
                    httpMethod: $method,
                    requestBody: $body,
                    additionalHeaders: $headers,
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