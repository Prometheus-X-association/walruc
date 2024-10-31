<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordConverter;

use JsonException;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;

class ConverterResponse
{
    public const DEFAULT_XAPI_VERSION = '1.0.3';

    private array $outputTrace;

    public function __construct(array $outputTrace)
    {
        $this->outputTrace = $outputTrace;
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ConversionException::conversionFailed('Invalid JSON response', $e);
        }

        if (!isset($data['output_trace'])) {
            throw ConversionException::invalidResponse();
        }

        return new self(
            outputTrace: $data['output_trace'],
        );
    }

    public function getVersion(): string
    {
        return $this->getTrace()['version'] ?: self::DEFAULT_XAPI_VERSION;
    }

    public function getTrace(): array
    {
        return $this->outputTrace;
    }
}