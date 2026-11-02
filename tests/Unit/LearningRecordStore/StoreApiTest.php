<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\LearningRecordStore;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\HttpException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\Http\HttpClientInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreApi;
use Piwik\Plugins\Walruc\SystemSettings;
use Piwik\Settings\Plugin\SystemSetting;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class StoreApiTest extends TestCase
{
    private const STORE_ENDPOINT = 'https://lrs.example.com/xAPI/statements';
    private const STORE_API_KEY = 'dXNlcjpwYXNzd29yZA==';

    private HttpClientInterface&MockObject $httpClient;
    private StoreApi $store;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = $this->createMock(SystemSettings::class);
        $settings->storeEndpoint = $this->createConfiguredMock(SystemSetting::class, [
            'getValue' => self::STORE_ENDPOINT,
        ]);
        $settings->storeApiKey = $this->createConfiguredMock(SystemSetting::class, [
            'getValue' => self::STORE_API_KEY,
        ]);

        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $this->store = new StoreApi(
            logger: $this->createStub(LoggerInterface::class),
            settings: $settings,
            httpClient: $this->httpClient,
        );
    }

    public function testShouldSendTraceToStoreEndpoint(): void
    {
        // Arrange
        $trace = new ConverterResponse(['version' => '1.0.0', 'verb' => ['id' => 'viewed']]);

        $this->httpClient->expects($this->once())
            ->method('sendRequest')
            ->with(
                self::STORE_ENDPOINT,
                'POST',
                json_encode($trace->getTrace()),
                $this->callback(function (array $headers) {
                    return in_array('Authorization: Basic ' . self::STORE_API_KEY, $headers, true)
                        && in_array('X-Experience-API-Version: 1.0.0', $headers, true);
                }),
            )
            ->willReturn('["3f2c8a1e-6b1d-4c2a-9e0f-1a2b3c4d5e6f"]');

        // Act
        $response = $this->store->store($trace);

        // Assert
        self::assertEquals('3f2c8a1e-6b1d-4c2a-9e0f-1a2b3c4d5e6f', $response->getUuid());
    }

    public function testShouldWrapHttpErrors(): void
    {
        // Arrange
        $httpException = new HttpException('Unexpected HTTP status: 401');
        $this->httpClient->method('sendRequest')
            ->willThrowException($httpException);
        $trace = new ConverterResponse(['version' => '1.0.0']);

        // Act & Assert
        try {
            $this->store->store($trace);
            self::fail('StorageException was not thrown');
        } catch (StorageException $e) {
            self::assertSame($httpException, $e->getPrevious());
        }
    }
}
