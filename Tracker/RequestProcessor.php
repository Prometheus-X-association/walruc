<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Tracker;

use Exception;
use Piwik\Log\LoggerInterface;
use Piwik\Network\IPUtils;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreResponse;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visit\VisitProperties;
use Throwable;

class RequestProcessor extends \Piwik\Tracker\RequestProcessor
{
    private LoggerInterface $logger;
    private ConverterInterface $converter;
    private StoreInterface $store;

    public function __construct(LoggerInterface $logger, ConverterInterface $converter, StoreInterface $store)
    {
        $this->logger = $logger;
        $this->converter = $converter;
        $this->store = $store;

        $this->logger->debug('RequestProcessor initialized');
    }

    public function recordLogs(VisitProperties $visitProperties, Request $request): bool
    {
        $this->logger->info('Request received');

        // A failing export must not prevent Matomo from recording the visit
        try {
            $trackingData = $this->getExportedData(visitProperties: $visitProperties, request: $request);
            $convertedData = $this->sendDataToLRC(trackingData: $trackingData);
            $storeData = $this->sendTraceToLRS($convertedData);
        } catch (ConversionException | StorageException) {
            // Already logged by sendDataToLRC() / sendTraceToLRS()
            return false;
        } catch (Throwable $e) {
            $this->logger->error('Tracking data export failed', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return false;
        }

        $this->logger->info('Request processed', ['uuid' => $storeData->getUuid()]);
        return false;
    }

    /**
     * Extracts and transforms visit and request data into a standardized format
     *
     * @param VisitProperties $visitProperties Visit properties
     * @param Request $request HTTP Request
     * @return TrackingData Formatted data for export
     */
    private function getExportedData(VisitProperties $visitProperties, Request $request): TrackingData
    {
        try {
            $ip = $request->getIpString();
        } catch (Exception) {
            $ip = null;
        }

        try {
            $url = $request->getParam('url');
        } catch (Exception) {
            $url = null;
        }

        $mainUrl = null;
        if ($url) {
            $parts = parse_url($url);
            if (isset($parts['host'])) {
                $scheme = $parts['scheme'] ?? 'http';
                $mainUrl = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        try {
            $title = $request->getParam('action_name');
        } catch (Exception) {
            $title = null;
        }

        $userId = $request->getForcedUserId();
        if (!$userId) {
            $userId = null;
        }

        // Visit properties mapping
        // Keys define the final name in the export
        // Values correspond to the property names
        $extraData = [];
        static $visitPropertiesFieldMapping = [
            'idVisit' => 'idvisit',
            'visitorId' => 'idvisitor',
            'languageCode' => 'location_browser_lang',
            'browserName' => 'config_browser_name',
            'countryCode' => 'location_country',
            'regionCode' => 'location_region',
            'city' => 'location_city',
            'latitude' => 'location_latitude',
            'longitude' => 'location_longitude',
            'referrerKeyword' => 'referer_keyword',
            'referrerUrl' => 'referer_url',
        ];
        foreach ($visitPropertiesFieldMapping as $finalName => $visitPropertyName) {
            $extraData[$finalName] = $this->readableProperty(
                $visitPropertyName,
                $visitProperties->getProperty($visitPropertyName),
            );
        }
        $extraData['interactions'] = $visitProperties->getProperty(
            'visit_total_interactions',
        ) ? (int)$visitProperties->getProperty('visit_total_interactions') : 1;

        // Add unmapped properties
        $unmappedProperties = array_diff_key($visitProperties->getProperties(), array_flip($visitPropertiesFieldMapping));
        foreach ($unmappedProperties as $visitPropertyName => $value) {
            $extraData[$visitPropertyName] = $this->readableProperty($visitPropertyName, $value);
        }

        // url and action_name are already exported, and token_auth is a Matomo credential
        static $excludedRequestParams = ['url', 'action_name', 'token_auth'];
        $extraData = array_merge(
            $extraData,
            array_diff_key($request->getParams(), array_flip($excludedRequestParams)),
        );

        return new TrackingData(
            siteName: $mainUrl,
            visitIp: $ip,
            userId: $userId,
            timestamp: $request->getCurrentTimestamp(),
            url: $url,
            title: $title,
            timeSpent: $visitProperties->getProperty('time_spent_ref_action'),
            extraData: $extraData,
        );
    }

    /**
     * Converts the binary visit properties to text, as Matomo does when it prints visitor information
     */
    private function readableProperty(string $name, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match ($name) {
            'idvisitor', 'config_id' => bin2hex($value),
            'location_ip' => IPUtils::binaryToStringIP($value),
            default => $value,
        };
    }

    private function sendDataToLRC(TrackingData $trackingData): ConverterResponse
    {
        try {
            return $this->converter->convert(trackingData: $trackingData);
        } catch (ConversionException $e) {
            $this->logger->error('Conversion failed', ['error' => $e->getMessage()]);
            $this->logger->debug('Conversion failed: data', ['data' => $trackingData->toArray()]);
            throw $e;
        }
    }

    private function sendTraceToLRS(ConverterResponse $trace): StoreResponse
    {
        try {
            return $this->store->store($trace);
        } catch (StorageException $e) {
            $this->logger->error('Storage failed', ['error' => $e->getMessage()]);
            $this->logger->debug('Storage failed: data', ['data' => $trace->getTrace()]);
            throw $e;
        }
    }
}
