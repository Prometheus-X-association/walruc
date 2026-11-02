<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\LearningRecordConverter;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\HttpException;
use Piwik\Plugins\Walruc\Http\HttpClientInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterApi;
use Piwik\Plugins\Walruc\SystemSettings;
use Piwik\Plugins\Walruc\Tracker\TrackingData;
use Piwik\Settings\Plugin\SystemSetting;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class ConverterApiTest extends TestCase
{
    private const CONVERT_ENDPOINT = 'https://lrc.example.com/convert';

    private HttpClientInterface&MockObject $httpClient;
    private ConverterApi $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createMock(SystemSettings::class);
        $settings->convertEndpoint = $this->createConfiguredMock(SystemSetting::class, [
            'getValue' => self::CONVERT_ENDPOINT,
        ]);

        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $this->converter = new ConverterApi(
            logger: $this->createStub(LoggerInterface::class),
            settings: $settings,
            httpClient: $this->httpClient,
        );
    }

    public function testShouldSendTrackingDataToConvertEndpoint(): void
    {
        // Arrange
        $this->httpClient->expects($this->once())
            ->method('sendRequest')
            ->with(
                self::CONVERT_ENDPOINT,
                'POST',
                $this->callback(function (string $body) {
                    $data = json_decode($body, true);
                    return $data['input_format'] === 'matomo'
                        && $data['input_trace']['actionDetails']['url'] === 'https://example.com/page';
                }),
                $this->containsIdentical('Content-Type: application/json; charset=utf-8'),
            )
            ->willReturn('{"output_trace": {"version": "1.0.3"}}');

        // Act
        $response = $this->converter->convert($this->createTrackingData());

        // Assert
        self::assertEquals(['version' => '1.0.3'], $response->getTrace());
    }

    public function testShouldWrapHttpErrors(): void
    {
        // Arrange
        $httpException = new HttpException('Unexpected HTTP status: 500');
        $this->httpClient->method('sendRequest')
            ->willThrowException($httpException);
        $trackingData = $this->createTrackingData();

        // Act & Assert
        try {
            $this->converter->convert($trackingData);
            self::fail('ConversionException was not thrown');
        } catch (ConversionException $e) {
            self::assertSame($httpException, $e->getPrevious());
        }
    }

    private function createTrackingData(): TrackingData
    {
        return new TrackingData(
            siteName: 'https://example.com',
            visitIp: '127.0.0.1',
            userId: 'user123',
            timestamp: 1635789600,
            url: 'https://example.com/page',
            title: 'Test Page',
            timeSpent: 30,
        );
    }
}
