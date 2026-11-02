<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit\LearningRecordConverter;

use JsonException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class ConverterResponseTest extends TestCase
{
    public function testShouldParseOutputTrace(): void
    {
        // Act
        $response = ConverterResponse::fromJson('{"output_trace": {"version": "1.0.0", "verb": {"id": "viewed"}}}');

        // Assert
        self::assertEquals(['version' => '1.0.0', 'verb' => ['id' => 'viewed']], $response->getTrace());
        self::assertEquals('1.0.0', $response->getVersion());
    }

    public function testShouldUseDefaultVersionWhenMissing(): void
    {
        // Act
        $response = ConverterResponse::fromJson('{"output_trace": {"verb": {"id": "viewed"}}}');

        // Assert
        self::assertEquals(ConverterResponse::DEFAULT_XAPI_VERSION, $response->getVersion());
    }

    public function testShouldUseDefaultVersionWhenNotAString(): void
    {
        // Act
        $response = ConverterResponse::fromJson('{"output_trace": {"version": 1}}');

        // Assert
        self::assertEquals(ConverterResponse::DEFAULT_XAPI_VERSION, $response->getVersion());
    }

    public function testShouldRejectOutputTraceThatIsNotAnObject(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Invalid LRC response: missing output_trace');

        ConverterResponse::fromJson('{"output_trace": "not a trace"}');
    }

    public function testShouldRejectInvalidJson(): void
    {
        try {
            ConverterResponse::fromJson('{invalid');
            self::fail('ConversionException was not thrown');
        } catch (ConversionException $e) {
            self::assertEquals('Conversion failed: Invalid JSON response', $e->getMessage());
            self::assertInstanceOf(JsonException::class, $e->getPrevious());
        }
    }

    public function testShouldRejectResponseWithoutOutputTrace(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Invalid LRC response: missing output_trace');

        ConverterResponse::fromJson('{"error": "Unknown format"}');
    }
}
