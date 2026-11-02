<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Http;

use Piwik\Http;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\HttpException;

class HttpClient implements HttpClientInterface
{
    private LoggerInterface $logger;
    private RetryHandlerInterface $retryHandler;
    private int $timeout;
    private int $maxAttempts;
    private int $retryDelayMs;

    /**
     * @param int $timeout Timeout of each attempt, in seconds
     * @param int $maxAttempts Maximum number of attempts, including the first one
     * @param int $retryDelayMs Delay before the first retry, doubled for each following retry
     */
    public function __construct(
        LoggerInterface $logger,
        RetryHandlerInterface $retryHandler,
        int $timeout = 10,
        int $maxAttempts = 3,
        int $retryDelayMs = 1000,
    ) {
        $this->logger = $logger;
        $this->retryHandler = $retryHandler;
        // A timeout of 0 would disable the timeout of the transport
        $this->timeout = max(1, $timeout);
        $this->maxAttempts = max(1, $maxAttempts);
        $this->retryDelayMs = max(0, $retryDelayMs);
    }

    /**
     * @param list<string> $headers
     */
    public function sendRequest(string $url, string $method, string $body, array $headers): string
    {
        // A misconfigured endpoint would fail on every attempt
        $urlParts = parse_url($url);
        if (!isset($urlParts['scheme'], $urlParts['host'])) {
            throw HttpException::invalidUrl($url);
        }

        return $this->retryHandler->executeWithRetry(
            operation: function () use ($url, $method, $body, $headers): string {
                $transportMethod = Http::getTransportMethod();
                if ($transportMethod === null) {
                    throw HttpException::noTransport();
                }

                try {
                    $response = Http::sendHttpRequestBy(
                        method: $transportMethod,
                        aUrl: $url,
                        timeout: $this->timeout,
                        getExtendedInfo: true,
                        httpMethod: $method,
                        requestBody: $body,
                        additionalHeaders: $headers,
                    );
                } catch (\Exception $e) {
                    // Network errors (timeout, connection refused...) are thrown by Matomo as generic exceptions
                    throw HttpException::requestFailed($e);
                }

                $status = isset($response['status']) ? (int)$response['status'] : null;
                $data = $response['data'] ?? '';
                if ($status !== null && ($status < 200 || $status >= 300)) {
                    $this->logger->debug('Unexpected HTTP status: response', [
                        'url' => $url,
                        'status' => $status,
                        'body' => $data,
                    ]);
                    throw HttpException::unexpectedStatus($status);
                }

                if (!is_string($data) || trim($data) === '') {
                    throw new HttpException('Empty response received');
                }

                return $data;
            },
            logger: $this->logger,
            operationName: "HTTP {$method} request to {$url}",
            maxAttempts: $this->maxAttempts,
            initialDelayMs: $this->retryDelayMs,
        );
    }
}
