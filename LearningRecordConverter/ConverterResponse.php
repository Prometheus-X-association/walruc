<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordConverter;

use JsonException;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;

class ConverterResponse
{
    public const DEFAULT_XAPI_VERSION = '1.0.3';

    /** @var array<string, mixed> */
    private array $outputTrace;

    /**
     * @param array<string, mixed> $outputTrace
     */
    public function __construct(array $outputTrace)
    {
        $this->outputTrace = $outputTrace;
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ConversionException::conversionFailed('Invalid JSON response', $e);
        }

        if (!is_array($data['output_trace'] ?? null)) {
            throw ConversionException::invalidResponse();
        }

        return new self(
            outputTrace: $data['output_trace'],
        );
    }

    public function getVersion(): string
    {
        $version = $this->getTrace()['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : self::DEFAULT_XAPI_VERSION;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrace(): array
    {
        return $this->outputTrace;
    }
}
