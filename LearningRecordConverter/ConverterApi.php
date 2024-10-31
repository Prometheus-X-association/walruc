<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordConverter;

use Piwik\Config;
use Piwik\Http;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Tracker\TrackingData;
use Throwable;

class ConverterApi implements ConverterInterface
{
    private const INPUT_FORMAT = 'matomo';
    private const TIMEOUT_SECONDS = 10;
    private const ENDPOINT_CONFIG = 'lrcEndpoint';

    private LoggerInterface $logger;
    private Config $config;

    public function __construct(LoggerInterface $logger, Config $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function convert(TrackingData $trackingData): ConverterResponse
    {
        $body = [
            'input_format' => self::INPUT_FORMAT,
            'input_trace' => $trackingData->toArray(),
        ];

        $this->logger->info('Converting data', ['body' => $body]);

        try {
            $jsonResponse = Http::sendHttpRequestBy(
                method: Http::getTransportMethod(),
                aUrl: $this->config->getFromLocalConfig('Walruc')[self::ENDPOINT_CONFIG],
                timeout: self::TIMEOUT_SECONDS,
                httpMethod: 'POST',
                requestBody: json_encode($body, JSON_INVALID_UTF8_IGNORE),
                additionalHeaders: [
                    'Accept: application/json',
                    'Content-Type: application/json; charset=utf-8',
                ],
            );

            if (!$jsonResponse) {
                throw ConversionException::conversionFailed('Conversion API returned an error');
            }

            $this->logger->info('Convert response received from LRC', ['response' => $jsonResponse]);

            return ConverterResponse::fromJson($jsonResponse);
        } catch (Throwable $e) {
            throw $e instanceof ConversionException
                ? $e
                : ConversionException::conversionFailed($e->getMessage(), $e);
        }
    }
}