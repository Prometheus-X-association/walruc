<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\Tracker;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Walruc\Tracker\TrackingData;

/**
 * Unit tests for TrackingData class.
 *
 * These tests ensure that the TrackingData DTO (Data Transfer Object):
 * - Properly formats tracking data
 * - Validates input data (IP addresses, URLs, timestamps)
 * - Handles null values and defaults
 * - Supports special characters and Unicode
 *
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class TrackingDataTest extends TestCase
{
    public function testShouldReturnCorrectFormat(): void
    {
        // Arrange
        $data = new TrackingData(
            siteName: 'Test Site',
            visitIp: '127.0.0.1',
            userId: 'user123',
            timestamp: 1635789600,
            url: 'https://example.com',
            title: 'Test Page',
            timeSpent: 30,
            extraData: [
                'browserName' => 'Chrome',
                'countryCode' => 'FR',
            ],
        );

        // Act
        $result = $data->toArray();

        // Assert
        self::assertIsArray($result);
        self::assertEquals('Test Site', $result['siteName']);
        self::assertEquals('127.0.0.1', $result['visitIp']);
        self::assertEquals('user123', $result['user_id']);

        self::assertArrayHasKey('actionDetails', $result);
        self::assertEquals('action', $result['actionDetails']['type']);
        self::assertEquals(1635789600, $result['actionDetails']['timestamp']);
        self::assertEquals('https://example.com', $result['actionDetails']['url']);
        self::assertEquals('Test Page', $result['actionDetails']['title']);
        self::assertEquals(30, $result['actionDetails']['timeSpent']);

        self::assertEquals('Chrome', $result['browserName']);
        self::assertEquals('FR', $result['countryCode']);
    }

    public function testShouldHandleNullValues(): void
    {
        // Arrange
        $data = new TrackingData(
            siteName: null,
            visitIp: null,
            userId: null,
            timestamp: null,
            url: null,
            title: null,
            timeSpent: null,
            extraData: [],
        );

        // Act
        $result = $data->toArray();

        // Assert
        self::assertIsArray($result);
        foreach (['siteName', 'visitIp', 'user_id'] as $key) {
            self::assertArrayHasKey($key, $result);
            self::assertNull($result[$key]);
        }

        self::assertArrayHasKey('actionDetails', $result);

        foreach (['url', 'title', 'timestamp'] as $key) {
            self::assertArrayHasKey($key, $result['actionDetails']);
            self::assertNull($result['actionDetails'][$key]);
        }

        self::assertEquals('action', $result['actionDetails']['type']);
        self::assertEquals(0, $result['actionDetails']['timeSpent']);
    }

    public function testShouldRejectNegativeTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timestamp must be positive');

        new TrackingData(
            siteName: 'Test Site',
            visitIp: '127.0.0.1',
            userId: 'user123',
            timestamp: -1, // Error
            url: 'https://example.com',
            title: 'Test Page',
            timeSpent: 30,
        );
    }

    public function testShouldRejectNegativeTimeSpent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Time spent must be positive');

        new TrackingData(
            siteName: 'Test Site',
            visitIp: '127.0.0.1',
            userId: 'user123',
            timestamp: 1635789600,
            url: 'https://example.com',
            title: 'Test Page',
            timeSpent: -10, // Error
        );
    }

    public function testShouldRejectInvalidIpAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IP address format');

        new TrackingData(
            siteName: 'Test Site',
            visitIp: '256.256.256.256', // Error
            userId: 'user123',
            timestamp: 1635789600,
            url: 'https://example.com',
            title: 'Test Page',
            timeSpent: 30,
        );
    }

    public function testShouldRejectInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL format');

        new TrackingData(
            siteName: 'Test Site',
            visitIp: '127.0.0.1',
            userId: 'user123',
            timestamp: 1635789600,
            url: 'not_a_valid_url', // Error
            title: 'Test Page',
            timeSpent: 30,
        );
    }

    public function testShouldAcceptValidIpv6Address(): void
    {
        // Arrange
        $data = new TrackingData(
            siteName: 'Test Site',
            visitIp: '2001:0db8:85a3:0000:0000:8a2e:0370:7334', // IPv6 IP
            userId: 'user123',
            timestamp: 1635789600,
            url: 'https://example.com',
            title: 'Test Page',
            timeSpent: 30,

        );

        // Act
        $result = $data->toArray();

        // Assert
        self::assertEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $result['visitIp']);
    }

    public function testShouldHandleSpecialCharacters(): void
    {
        // Arrange
        $data = new TrackingData(
            siteName: 'Test Site éèà',
            visitIp: '127.0.0.1',
            userId: 'user_Москва',
            timestamp: PHP_INT_MAX,
            url: 'https://example.com/?q=特殊字符',
            title: 'Page with Special Characters 😊',
            timeSpent: 0,
            extraData: [],
        );

        // Act
        $result = $data->toArray();

        // Assert
        self::assertEquals('Test Site éèà', $result['siteName']);
        self::assertEquals('user_Москва', $result['user_id']);
        self::assertEquals('https://example.com/?q=特殊字符', $result['actionDetails']['url']);
        self::assertEquals('Page with Special Characters 😊', $result['actionDetails']['title']);
        self::assertEquals(PHP_INT_MAX, $result['actionDetails']['timestamp']);
    }

    /**
     * @dataProvider validUrlProvider
     */
    public function testShouldAcceptVariousValidUrls(string $url): void
    {
        $data = new TrackingData(
            siteName: 'Test Site',
            visitIp: '127.0.0.1',
            userId: 'user123',
            timestamp: 1635789600,
            url: $url,
            title: 'Test Page',
            timeSpent: 30,
        );

        $result = $data->toArray();
        self::assertEquals($url, $result['actionDetails']['url']);
    }

    public function validUrlProvider(): array
    {
        return [
            'standard http' => ['http://example.com'],
            'https with path' => ['https://example.com/path/to/page'],
            'with query params' => ['https://example.com?param=value&other=123'],
            'with anchor' => ['https://example.com#section'],
            'with port' => ['https://example.com:8080'],
            'complex url' => ['https://sub.example.com:8080/path?param=value#section'],
            'unicode domain' => ['https://例子.test'],
            'percent encoding' => ['https://example.com/path%20with%20spaces'],
        ];
    }


}