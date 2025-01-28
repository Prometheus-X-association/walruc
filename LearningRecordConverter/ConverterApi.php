<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordConverter;

use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\HttpException;
use Piwik\Plugins\Walruc\Http\HttpClientInterface;
use Piwik\Plugins\Walruc\Tracker\TrackingData;

class ConverterApi implements ConverterInterface
{
    private const INPUT_FORMAT = 'matomo';
    private const ENDPOINT_CONFIG = 'lrc.endpoint';

    private LoggerInterface $logger;
    private Config $config;
    private HttpClientInterface $httpClient;

    public function __construct(LoggerInterface $logger, Config $config, HttpClientInterface $httpClient)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    public function convert(TrackingData $trackingData): ConverterResponse
    {
        $endpoint = StaticContainer::get(self::ENDPOINT_CONFIG);

        $body = [
            'input_format' => self::INPUT_FORMAT,
            'input_trace' => $trackingData->toArray(),
        ];

        $this->logger->info('Converting data', ['body' => $body]);

        try {
            $jsonResponse = $this->httpClient->sendRequest(
                url: $endpoint,
                method: 'POST',
                body: json_encode($body, JSON_INVALID_UTF8_IGNORE),
                headers: [
                    'Accept: application/json',
                    'Content-Type: application/json; charset=utf-8',
                ],
            );
        } catch (HttpException $exception) {
            throw ConversionException::conversionFailed('Conversion API returned an error');
        }

        $this->logger->info('Convert response received from LRC', ['response' => $jsonResponse]);

        return ConverterResponse::fromJson($jsonResponse);
    }
}