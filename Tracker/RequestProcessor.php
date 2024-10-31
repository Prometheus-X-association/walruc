<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Tracker;

use Exception;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreResponse;
use Piwik\Site;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visit\VisitProperties;

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

        $site = null;
        if ($siteId = $visitProperties->getProperty('idsite')) {
            $site = new Site($siteId);
        }

        $trackingData = $this->getExportedData(visitProperties: $visitProperties, request: $request, site: $site);
        $convertedData = $this->sendDataToLRC(trackingData: $trackingData);
        $storeData = $this->sendTraceToLMS($convertedData);

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
    private function getExportedData(VisitProperties $visitProperties, Request $request, ?Site $site): TrackingData
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
            $extraData[$finalName] = $visitProperties->getProperty($visitPropertyName);
        }
        $extraData['interactions'] = $visitProperties->getProperty(
            'visit_total_interactions',
        ) ? (int)$visitProperties->getProperty('visit_total_interactions') : 1;

        // Add unmapped properties
        $extraData = array_merge(
            $extraData,
            array_diff_key($visitProperties->getProperties(), array_flip($visitPropertiesFieldMapping)),
        );
        $extraData = array_merge(
            $extraData,
            array_diff_key($request->getParams(), ['url', 'action_name']),
        );

        return new TrackingData(
            siteName: $site?->getMainUrl(),
            visitIp: $ip,
            userId: $userId,
            timestamp: $request->getCurrentTimestamp(),
            url: $url,
            title: $title,
            timeSpent: $visitProperties->getProperty('time_spent_ref_action'),
            extraData: $extraData,
        );
    }

    private function sendDataToLRC(TrackingData $trackingData): ConverterResponse
    {
        try {
            return $this->converter->convert(trackingData: $trackingData);
        } catch (ConversionException $e) {
            $this->logger->error('Conversion failed', [
                'error' => $e->getMessage(),
                'data' => $trackingData->toArray(),
            ]);
            throw $e;
        }
    }

    private function sendTraceToLMS(ConverterResponse $trace): StoreResponse
    {
        try {
            return $this->store->store($trace);
        } catch (StorageException $e) {
            $this->logger->error('Storage failed', [
                'error' => $e->getMessage(),
                'data' => $trace->getTrace(),
            ]);
            throw $e;
        }
    }
}
