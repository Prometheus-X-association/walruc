<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordStore;

use Piwik\Http;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\SystemSettings;
use Piwik\Plugins\Walruc\Traits\RetryableHttpTrait;

class StoreApi implements StoreInterface
{
    use RetryableHttpTrait;

    public const TIMEOUT_SECONDS = 10;

    private LoggerInterface $logger;
    private SystemSettings $settings;

    public function __construct(LoggerInterface $logger, SystemSettings $settings)
    {
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function store(ConverterResponse $trace): StoreResponse
    {
        return $this->executeWithRetry(
            operation: function () use ($trace) {
                $jsonResponse = Http::sendHttpRequestBy(
                    method: Http::getTransportMethod(),
                    aUrl: $this->settings->lrsEndpoint->getValue(),
                    timeout: self::TIMEOUT_SECONDS,
                    httpMethod: 'POST',
                    requestBody: json_encode($trace->getTrace()),
                    additionalHeaders: [
                        'Authorization: Basic ' . $this->settings->lrsApiKey->getValue(),
                        'X-Experience-API-Version: ' . $trace->getVersion(),
                        'Accept: application/json',
                        'Content-Type: application/json; charset=utf-8',
                    ],
                );

                if (!$jsonResponse) {
                    throw StorageException::storageFailed('API returned error status');
                }

                $this->logger->info('Store response received from LMS', ['response' => $jsonResponse]);

                return StoreResponse::fromJson($jsonResponse);
            },
            logger: $this->logger,
            operationName: 'Storing data',
        );
    }
}