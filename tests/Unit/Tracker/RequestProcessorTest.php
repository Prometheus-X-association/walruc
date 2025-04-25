<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\Tracker;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreResponse;
use Piwik\Plugins\Walruc\Tracker\RequestProcessor;
use Piwik\Plugins\Walruc\Tracker\TrackingData;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visit\VisitProperties;

/**
 * Unit tests for RequestProcessor class.
 *
 * These tests verify that the RequestProcessor:
 * - Correctly extracts data from Matomo requests
 * - Properly converts tracking data via LRC
 * - Successfully stores converted data in LRS
 * - Handles errors appropriately
 * - Logs relevant information
 *
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class RequestProcessorTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private ConverterInterface|MockObject $converter;
    private StoreInterface|MockObject $store;
    private RequestProcessor $requestProcessor;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for dependencies
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->converter = $this->createMock(ConverterInterface::class);
        $this->store = $this->createMock(StoreInterface::class);

        $this->siteId = Fixture::createWebsite('2016-01-01 00:00:01', 0, 'TestSite', 'http://example.com');

        // Create processor instance
        $this->requestProcessor = new RequestProcessor(
            logger: $this->logger,
            converter: $this->converter,
            store: $this->store,
        );
    }

    public function testShouldProcessValidRequest(): void
    {
        // Arrange
        $visitProperties = $this->createVisitPropertiesMock();
        $request = $this->createRequestMock();

        // Mock converter and store responses
        $this->converter->expects($this->once())
            ->method('convert')
            ->willReturn($this->createMock(ConverterResponse::class));

        $this->store->expects($this->once())
            ->method('store')
            ->willReturn($this->createMock(StoreResponse::class));

        // Act
        $result = $this->requestProcessor->recordLogs($visitProperties, $request);

        // Assert
        self::assertFalse($result); // Should return false as per Matomo's conventions
    }

    public function testShouldHandleConversionError(): void
    {
        // Arrange
        $visitProperties = $this->createVisitPropertiesMock();
        $request = $this->createRequestMock();

        $this->converter->expects($this->once())
            ->method('convert')
            ->willThrowException(new ConversionException('Conversion failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Conversion failed'),
                $this->arrayHasKey('error'),
            );

        // Act & Assert
        $this->expectException(ConversionException::class);
        $this->requestProcessor->recordLogs($visitProperties, $request);
    }

    public function testShouldHandleStorageError(): void
    {
        // Arrange
        $visitProperties = $this->createVisitPropertiesMock();
        $request = $this->createRequestMock();

        $this->converter->expects($this->once())
            ->method('convert')
            ->willReturn($this->createMock(ConverterResponse::class));

        $this->store->expects($this->once())
            ->method('store')
            ->willThrowException(new StorageException('Storage failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Storage failed'),
                $this->arrayHasKey('error'),
            );

        // Act & Assert
        $this->expectException(StorageException::class);
        $this->requestProcessor->recordLogs($visitProperties, $request);
    }

    public function testShouldExtractCorrectDataFromRequest(): void
    {
        // Arrange
        $visitProperties = $this->createVisitPropertiesMock();
        $request = $this->createRequestMock();

        $this->converter->expects($this->once())
            ->method('convert')
            ->with(
                $this->callback(function (TrackingData $trackingData) {
                    $actualData = $trackingData->toArray();
                    return $actualData['siteName'] === 'https://example.com'
                        && $actualData['visitIp'] === '127.0.0.1'
                        && $actualData['user_id'] === '42'
                        && $actualData['actionDetails']['type'] === 'action'
                        && $actualData['actionDetails']['url'] === 'https://example.com/toto'
                        && $actualData['actionDetails']['title'] === 'Test Page'
                        && $actualData['actionDetails']['timeSpent'] === 12
                        && $actualData['actionDetails']['timestamp'] === 1635789600;
                }),
            );

        // Act
        $this->requestProcessor->recordLogs($visitProperties, $request);
    }

    private function createVisitPropertiesMock(): VisitProperties|MockObject
    {
        $visitProperties = $this->createMock(VisitProperties::class);

        $visitProperties->method('getProperty')
            ->willReturnMap([
                ['idsite', $this->siteId],
                ['idvisit', 123],
                ['idvisitor', 12],
                ['location_browser_lang', 'fr'],
                ['config_browser_name', 'Chrome'],
                ['visit_total_interactions', 5],
                ['location_country', 'FR'],
                ['referer_keyword', 'Toto'],
                ['referer_url', 'http://referer.com'],
                ['time_spent_ref_action', 12],
            ]);

        return $visitProperties;
    }

    private function createRequestMock(): Request|MockObject
    {
        $request = $this->createMock(Request::class);

        $request->method('getParam')
            ->willReturnMap([
                ['url', 'https://example.com/toto'],
                ['action_name', 'Test Page'],
            ]);
        $request->method('getIpString')
            ->willReturn('127.0.0.1');
        $request->method('getCurrentTimestamp')
            ->willReturn(1635789600);
        $request->method('getForcedUserId')
            ->willReturn('42');
        $request->method('getParams')
            ->willReturn([]);

        return $request;
    }
}