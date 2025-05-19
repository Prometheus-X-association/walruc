<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordStore;

use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\Http\HttpClientInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\SystemSettings;

class StoreApi implements StoreInterface
{
    private LoggerInterface $logger;
    private SystemSettings $settings;
    private HttpClientInterface $httpClient;

    public function __construct(LoggerInterface $logger, SystemSettings $settings, HttpClientInterface $httpClient)
    {
        $this->logger = $logger;
        $this->settings = $settings;
        $this->httpClient = $httpClient;
    }

    public function store(ConverterResponse $trace): StoreResponse
    {
        try {
            $jsonResponse = $this->httpClient->sendRequest(
                url: $this->settings->storeEndpoint->getValue(),
                method: 'POST',
                body: json_encode($trace->getTrace()),
                headers: [
                    'Authorization: Basic ' . $this->settings->storeApiKey->getValue(),
                    'X-Experience-API-Version: ' . $trace->getVersion(),
                    'Accept: application/json',
                    'Content-Type: application/json; charset=utf-8',
                ],
            );
        } catch (\Exception $exception) {
            throw StorageException::storageFailed('API returned error status');
        }

        $this->logger->info('Store response received from LRS', ['response' => $jsonResponse]);

        return StoreResponse::fromJson($jsonResponse);
    }
}