<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Http;

use Piwik\Plugins\Walruc\Exceptions\HttpException;

interface HttpClientInterface
{
    /**
     * Sends an HTTP request
     *
     * @param string $url The URL to send the request to
     * @param string $method HTTP method (GET, POST, PUT, DELETE, PATCH)
     * @param string $body Request body
     * @param array $headers Request headers
     * @param int $timeout Timeout in seconds
     *
     * @throws HttpException If request fails after all retries
     */
    public function sendRequest(string $url, string $method, string $body, array $headers, int $timeout): string;
}

