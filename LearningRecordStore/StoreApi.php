<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordStore;

use Piwik\Http;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\SystemSettings;

class StoreApi implements StoreInterface
{
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
        try {
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
        } catch (Exception $e) {
            throw $e instanceof StorageException
                ? $e
                : StorageException::storageFailed($e->getMessage(), $e);
        }
    }
}