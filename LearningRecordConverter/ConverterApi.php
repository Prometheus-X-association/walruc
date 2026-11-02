<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordConverter;

use JsonException;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\HttpException;
use Piwik\Plugins\Walruc\Http\HttpClientInterface;
use Piwik\Plugins\Walruc\SystemSettings;
use Piwik\Plugins\Walruc\Tracker\TrackingData;

class ConverterApi implements ConverterInterface
{
    private const INPUT_FORMAT = 'matomo';

    private LoggerInterface $logger;
    private SystemSettings $settings;
    private HttpClientInterface $httpClient;

    public function __construct(LoggerInterface $logger, SystemSettings $settings, HttpClientInterface $httpClient)
    {
        $this->logger = $logger;
        $this->settings = $settings;
        $this->httpClient = $httpClient;
    }

    public function convert(TrackingData $trackingData): ConverterResponse
    {
        $body = [
            'input_format' => self::INPUT_FORMAT,
            'input_trace' => $trackingData->toArray(),
        ];

        $this->logger->debug('Converting data', ['body' => $body]);

        try {
            $jsonResponse = $this->httpClient->sendRequest(
                url: $this->settings->convertEndpoint->getValue(),
                method: 'POST',
                body: json_encode($body, JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR),
                headers: [
                    'Accept: application/json',
                    'Content-Type: application/json; charset=utf-8',
                ],
            );
        } catch (JsonException $exception) {
            throw ConversionException::conversionFailed('Tracking data cannot be encoded as JSON', $exception);
        } catch (HttpException $exception) {
            throw ConversionException::conversionFailed('Conversion API returned an error', $exception);
        }

        $this->logger->debug('Convert response received from LRC', ['response' => $jsonResponse]);

        return ConverterResponse::fromJson($jsonResponse);
    }
}
