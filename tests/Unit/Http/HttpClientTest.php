<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\HttpException;
use Piwik\Plugins\Walruc\Http\HttpClient;
use Piwik\Plugins\Walruc\Http\RetryHandlerInterface;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class HttpClientTest extends TestCase
{
    /**
     * @dataProvider invalidUrlProvider
     */
    public function testShouldRejectInvalidUrlWithoutSendingRequest(string $url): void
    {
        // Arrange
        $retryHandler = $this->createMock(RetryHandlerInterface::class);
        $retryHandler->expects($this->never())->method('executeWithRetry');
        $httpClient = new HttpClient($this->createStub(LoggerInterface::class), $retryHandler);

        // Act & Assert
        try {
            $httpClient->sendRequest($url, 'POST', '{}', []);
            self::fail('HttpException was not thrown');
        } catch (HttpException $e) {
            self::assertFalse($e->isRetryable());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'empty' => [''],
            'missing scheme' => ['lrs.example.com/statements'],
            'missing host' => ['https://'],
        ];
    }
}
