<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\Exceptions;

use JsonException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\HttpException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use RuntimeException;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class WalrucExceptionTest extends TestCase
{
    /**
     * @dataProvider httpStatusProvider
     */
    public function testHttpStatusClassification(int $status, bool $isRetryable, bool $isConfigurationError): void
    {
        $exception = HttpException::unexpectedStatus($status);

        self::assertSame($isRetryable, $exception->isRetryable(), 'retryable');
        self::assertSame($isConfigurationError, $exception->isConfigurationError(), 'configuration error');
    }

    /**
     * @return array<string, array{int, bool, bool}>
     */
    public static function httpStatusProvider(): array
    {
        return [
            'no response' => [0, true, false],
            'redirection' => [302, false, false],
            'bad request' => [400, false, false],
            'unauthorized' => [401, false, true],
            'forbidden' => [403, false, true],
            'not found' => [404, false, true],
            'method not allowed' => [405, false, true],
            'request timeout' => [408, true, false],
            'unprocessable content' => [422, false, false],
            'too many requests' => [429, true, false],
            'internal server error' => [500, true, false],
            'service unavailable' => [503, true, false],
        ];
    }

    public function testNetworkFailureIsRetryable(): void
    {
        $exception = HttpException::requestFailed(new RuntimeException('Connection refused'));

        self::assertTrue($exception->isRetryable());
        self::assertFalse($exception->isConfigurationError());
    }

    public function testMissingTransportIsConfigurationError(): void
    {
        $exception = HttpException::noTransport();

        self::assertFalse($exception->isRetryable());
        self::assertTrue($exception->isConfigurationError());
    }

    public function testFailureInheritsHttpCauseClassification(): void
    {
        $temporary = ConversionException::conversionFailed('Conversion API returned an error', HttpException::unexpectedStatus(503));
        $configuration = StorageException::storageFailed('API returned error status', HttpException::unexpectedStatus(401));

        self::assertTrue($temporary->isRetryable());
        self::assertFalse($temporary->isConfigurationError());
        self::assertFalse($configuration->isRetryable());
        self::assertTrue($configuration->isConfigurationError());
    }

    public function testInvalidResponseContentIsPermanent(): void
    {
        $missingTrace = ConversionException::invalidResponse();
        $invalidJson = StorageException::storageFailed('Invalid JSON response', new JsonException('Syntax error'));

        self::assertFalse($missingTrace->isRetryable());
        self::assertFalse($missingTrace->isConfigurationError());
        self::assertFalse($invalidJson->isRetryable());
        self::assertFalse($invalidJson->isConfigurationError());
    }
}
