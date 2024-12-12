<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\Http;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\HttpException;
use Piwik\Plugins\Walruc\Http\RetryHttpHandler;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class RetryHttpHandlerTest extends TestCase
{
    private const TEST_INITIAL_DELAY_MS = 10;
    private const TEST_BACKOFF_MULTIPLIER = 1.5;
    private const DEFAULT_MAX_ATTEMPTS = 3;

    private LoggerInterface $logger;
    private RetryHttpHandler $retryable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->retryable = new RetryHttpHandler();
    }

    public function testSuccessWithoutRetry(): void
    {
        // Arrange
        $attempts = 0;
        $operation = static function () use (&$attempts) {
            $attempts++;
            return 'success';
        };

        // Act
        $result = $this->retryable->executeWithRetry(
            operation: $operation,
            logger: $this->logger,
            operationName: 'test',
        );

        // Assert
        self::assertEquals('success', $result);
        self::assertEquals(1, $attempts);
    }

    public function testSuccessAfterOneRetry(): void
    {
        // Arrange
        $attempts = 0;
        $operation = static function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new HttpException('First attempt failed');
            }
            return 'success';
        };

        // Act
        $result = $this->retryable->executeWithRetry(
            operation: $operation,
            logger: $this->logger,
            operationName: 'test',
            initialDelayMs: self::TEST_INITIAL_DELAY_MS,
        );

        // Assert
        self::assertEquals('success', $result);
        self::assertEquals(2, $attempts);
    }

    public function testFailureAfterMaxRetries(): void
    {
        // Arrange
        $attempts = 0;
        $operation = static function () use (&$attempts) {
            $attempts++;
            throw new HttpException('Attempt failed');
        };

        // Assert (expectation)
        $this->expectException(HttpException::class);

        // Act & Assert
        try {
            $this->retryable->executeWithRetry(
                operation: $operation,
                logger: $this->logger,
                operationName: 'test',
                initialDelayMs: self::TEST_INITIAL_DELAY_MS,
            );
        } finally {
            self::assertEquals(self::DEFAULT_MAX_ATTEMPTS, $attempts);
        }
    }

    public function testBackoffDelay(): void
    {
        // Arrange
        $attempts = 0;
        $lastAttemptTime = microtime(true);
        $delays = [];

        $operation = static function () use (&$attempts, &$lastAttemptTime, &$delays) {
            $currentTime = microtime(true);
            if ($attempts > 0) {
                $delays[] = ($currentTime - $lastAttemptTime) * 1000;
            }
            $lastAttemptTime = $currentTime;
            $attempts++;
            throw new HttpException('Attempt failed');
        };

        // Act & Assert
        try {
            $this->retryable->executeWithRetry(
                operation: $operation,
                logger: $this->logger,
                operationName: 'test',
                initialDelayMs: self::TEST_INITIAL_DELAY_MS,
                backoffMultiplier: self::TEST_BACKOFF_MULTIPLIER,
            );
        } catch (HttpException) {
            // Assert
            self::assertCount(2, $delays);

            $expectedSecondDelay = self::TEST_INITIAL_DELAY_MS * self::TEST_BACKOFF_MULTIPLIER;

            self::assertGreaterThanOrEqual(self::TEST_INITIAL_DELAY_MS - 5, $delays[0], 'Premier délai trop court');
            self::assertLessThanOrEqual(self::TEST_INITIAL_DELAY_MS + 5, $delays[0], 'Premier délai trop long');

            self::assertGreaterThanOrEqual($expectedSecondDelay - 5, $delays[1], 'Second délai trop court');
            self::assertLessThanOrEqual($expectedSecondDelay + 5, $delays[1], 'Second délai trop long');
        }
    }

    public function testOriginalExceptionPreserved(): void
    {
        // Arrange
        $expectedMessage = 'Custom error message';
        $operation = static function () use ($expectedMessage) {
            throw new HttpException($expectedMessage);
        };

        // Act & Assert
        try {
            $this->retryable->executeWithRetry(
                operation: $operation,
                logger: $this->logger,
                operationName: 'test',
                initialDelayMs: self::TEST_INITIAL_DELAY_MS,
            );
        } catch (HttpException $e) {
            // Assert
            self::assertEquals($expectedMessage, $e->getMessage());
        }
    }
}