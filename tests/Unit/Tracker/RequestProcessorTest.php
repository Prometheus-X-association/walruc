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
    private LoggerInterface&MockObject $logger;
    private ConverterInterface&MockObject $converter;
    private StoreInterface&MockObject $store;
    private RequestProcessor $requestProcessor;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for dependencies
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->converter = $this->createMock(ConverterInterface::class);
        $this->store = $this->createMock(StoreInterface::class);

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

        // Act & Assert: the error is logged (see expectation above) and not rethrown
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

        // Act & Assert: the error is logged (see expectation above) and not rethrown
        $this->requestProcessor->recordLogs($visitProperties, $request);
    }

    public function testShouldNotPropagateUnexpectedErrors(): void
    {
        // Arrange
        $visitProperties = $this->createVisitPropertiesMock();
        $request = $this->createRequestMock();

        $this->converter->expects($this->once())
            ->method('convert')
            ->willThrowException(new \TypeError('Unexpected type'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Tracking data export failed',
                $this->callback(fn (array $context) => $context['exception'] === \TypeError::class
                    && $context['error'] === 'Unexpected type'),
            );

        // Act & Assert: the error is logged and does not break the Matomo tracker
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

    public function testShouldNotExportTokenAuth(): void
    {
        // Arrange
        $visitProperties = $this->createVisitPropertiesMock();
        $request = $this->createRequestMock(['token_auth' => 'secret', 'url' => 'https://example.com/toto', 'res' => '1920x1080']);

        $this->converter->expects($this->once())
            ->method('convert')
            ->with(
                $this->callback(function (TrackingData $trackingData) {
                    $actualData = $trackingData->toArray();
                    return !array_key_exists('token_auth', $actualData)
                        && $actualData['res'] === '1920x1080'
                        && !array_key_exists('url', $actualData);
                }),
            );

        // Act
        $this->requestProcessor->recordLogs($visitProperties, $request);
    }

    public function testShouldExportBinaryPropertiesAsText(): void
    {
        // Arrange
        $visitProperties = $this->createMock(VisitProperties::class);
        $visitProperties->method('getProperty')
            ->willReturnMap([
                ['idvisitor', hex2bin('5d2b1f9a0c3e7b41')],
            ]);
        $visitProperties->method('getProperties')
            ->willReturn([
                'config_id' => hex2bin('a1b2c3d4e5f60718'),
                'location_ip' => inet_pton('192.168.1.10'),
            ]);
        $request = $this->createRequestMock();

        $this->converter->expects($this->once())
            ->method('convert')
            ->with(
                $this->callback(function (TrackingData $trackingData) {
                    $actualData = $trackingData->toArray();
                    return $actualData['visitorId'] === '5d2b1f9a0c3e7b41'
                        && $actualData['config_id'] === 'a1b2c3d4e5f60718'
                        && $actualData['location_ip'] === '192.168.1.10';
                }),
            );

        // Act
        $this->requestProcessor->recordLogs($visitProperties, $request);
    }

    private function createVisitPropertiesMock(): VisitProperties&MockObject
    {
        $visitProperties = $this->createMock(VisitProperties::class);

        $visitProperties->method('getProperty')
            ->willReturnMap([
                ['idsite', 1],
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

    /**
     * @param array<string, mixed> $params Tracking request parameters
     */
    private function createRequestMock(array $params = []): Request&MockObject
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
            ->willReturn($params);

        return $request;
    }
}
