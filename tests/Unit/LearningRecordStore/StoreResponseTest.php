<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\LearningRecordStore;

use JsonException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreResponse;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class StoreResponseTest extends TestCase
{
    public function testShouldParseStatementId(): void
    {
        // Act
        $response = StoreResponse::fromJson('["3f2c8a1e-6b1d-4c2a-9e0f-1a2b3c4d5e6f"]');

        // Assert
        self::assertEquals('3f2c8a1e-6b1d-4c2a-9e0f-1a2b3c4d5e6f', $response->getUuid());
    }

    public function testShouldRejectStatementIdThatIsNotAString(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Invalid LRS response: missing id');

        StoreResponse::fromJson('[42]');
    }

    public function testShouldRejectInvalidJson(): void
    {
        try {
            StoreResponse::fromJson('{invalid');
            self::fail('StorageException was not thrown');
        } catch (StorageException $e) {
            self::assertEquals('Storage failed: Invalid JSON response', $e->getMessage());
            self::assertInstanceOf(JsonException::class, $e->getPrevious());
        }
    }

    public function testShouldRejectResponseWithoutStatementId(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Invalid LRS response: missing id');

        StoreResponse::fromJson('{"error": "Unauthorized"}');
    }
}
