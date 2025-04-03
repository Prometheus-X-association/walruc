<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Commands;

use Piwik\API\Request;
use Piwik\Container\StaticContainer;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreResponse;
use Piwik\Plugins\Walruc\Tracker\TrackingData;
use Piwik\Site;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProcessExport extends ConsoleCommand
{
    private LoggerInterface $logger;
    private ConverterInterface $converter;
    private StoreInterface $store;

    public function __construct()
    {
        parent::__construct();

        $this->logger = StaticContainer::get(LoggerInterface::class);
        $this->converter = StaticContainer::get(ConverterInterface::class);
        $this->store = StaticContainer::get(StoreInterface::class);

        $this->logger->debug('Command initialized');
    }

    protected function configure(): void
    {
        $this->setName('walruc:process-export')
            ->setDescription('Process a Matomo export file in JSON format and send it to LRS')
            ->addRequiredValueOption(
                'file',
                'f',
                'Path to the Matomo export file (JSON format)'
            )
            ->addRequiredValueOption(
                'site-id',
                's',
                'Site ID to fetch visits for (can be an ID, comma-separated IDs like "1,2,3", or "all")'
            )
            ->addRequiredValueOption(
                'date',
                'd',
                'Date to fetch (YYYY-MM-DD, or date range YYYY-MM-DD,YYYY-MM-DD, or "last30", "previous7" etc.)',
                'last1000'
            )
            ->addNoValueOption(
                'dry-run',
                null,
                'Simulate processing without sending data'
            );
    }

    protected function doExecute(): int
    {
        $startTime = microtime(true);
        $input = $this->getInput();
        $output = $this->getOutput();

        $io = new SymfonyStyle($input, $output);
        $io->title('Processing Matomo Export');

        $dryRun = $input->getOption('dry-run');
        if ($dryRun) {
            $io->warning('DRY RUN MODE - No data will be sent to LRS');
        }

        $filePath = $input->getOption('file');
        $siteId = $input->getOption('site-id');
        // Verify that at least one option is specified
        if (empty($filePath) && empty($siteId)) {
            $io->error('You must specify either a file (--file) or a site ID (--site-id)');
            return self::FAILURE;
        }

        // Determine data source and load data
        $io->section('Loading data...');
        if ($filePath) {
            $data = $this->loadDataFromFile($io, $filePath);
        } else {
            $date = $input->getOption('date');
            $data = $this->loadDataFromInternalAPI($io, $siteId, $date);
        }
        if (empty($data)) {
            $io->error('No data found to process');
            return self::FAILURE;
        }

        $totalVisits = count($data);
        $io->success("Found $totalVisits visits to process");

        // Process records
        $io->section('Processing visits...');
        $io->progressStart($totalVisits);

        foreach ($data as $visit) {
            if (!isset($visit['actionDetails'])) {
                $this->logger->warning('Visit has no actions, skipping', [
                    'idVisit' => $visit['idVisit'] ?? 'unknown',
                ]);
                continue;
            }

            foreach ($visit['actionDetails'] as $action) {
                try {
                    $trackingData = $this->createTrackingData($visit, $action);
                } catch (\Exception $e) {
                    $this->logger->error('Action processing failed', [
                        'error' => $e->getMessage(),
                        'url' => $action['url'] ?? 'unknown',
                    ]);
                    continue;
                }
                if (!$dryRun) {
                    $convertedData = $this->sendDataToLRC(trackingData: $trackingData);
                    $storeData = $this->sendTraceToLRS(trace: $convertedData);
                    $this->logger->info('Request processed', ['uuid' => $storeData->getUuid()]);
                } else {
                    $this->logger->info(
                        'Data that would be sent : '
                        . json_encode($trackingData->toArray()),
                    );
                }
            }
            $io->progressAdvance();
        }

        $io->progressFinish();
        $processingTime = microtime(true) - $startTime;
        $io->success('Processing completed in ' . round($processingTime, 2) . ' seconds');

        return self::SUCCESS;
    }

    /**
     * Load data from a JSON file
     */
    private function loadDataFromFile(SymfonyStyle $io, string $filePath): array
    {
        // Validate file
        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return [];
        }
        if (!is_readable($filePath)) {
            $io->error("File is not readable: $filePath");
            return [];
        }

        $io->text("File: $filePath");

        $io->section('Reading file...');
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: $filePath");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON content: ' . json_last_error_msg());
        }
        return $data;
    }

    /**
     * Fetch data from internal Matomo API with pagination
     */
    private function loadDataFromInternalAPI(SymfonyStyle $io, string $siteId, string $date): array
    {
        $delayMs = 200;
        $limit = 1000;

        $io->text('Fetching data from Matomo API');
        $io->text("Site ID: $siteId, Date: $date");

        $allVisits = [];
        $offset = 0;
        $pageCount = 0;
        $continueFetching = true;

        // Prepare API parameters
        $params = [
            'method' => 'Live.getLastVisitsDetails',
            'format' => 'json',
            'idSite' => $siteId,
            'period' => 'range',
            'date' => $date,
            'filter_limit' => 1000,
            'filter_offset' => $offset,
        ];

        while ($continueFetching) {
            $pageCount++;

            $io->text("Fetching page $pageCount (offset: $offset)...");

            $params['filter_offset'] = $offset;
            // Call the API internally
            try {
                $request = new Request($params);
                $response = $request->process();
            } catch (\Exception $e) {
                $io->error('API request failed: ' . $e->getMessage());
                $this->logger->error('API request failed', [
                    'error' => $e->getMessage(),
                    'params' => $params,
                ]);
                break;
            }
            $responseData = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid JSON content: ' . json_last_error_msg());
            }

            // Check if response is empty or not an array
            if (!is_array($responseData) || empty($responseData)) {
                $io->text('No more visits found. Stopping fetch.');
                break;
            }

            // Add visits to collection
            $visitCount = count($responseData);
            $allVisits = array_merge($allVisits, $responseData);
            $io->text("Fetched $visitCount visits (total: " . count($allVisits) . ')');

            // Update offset for next page
            $offset += $limit;

            // If we got fewer results than the limit, we've reached the end
            if ($visitCount < $limit) {
                $continueFetching = false;
            }

            // Add delay between requests to reduce server load
            if ($delayMs > 0 && $continueFetching) {
                usleep($delayMs * 1000);
            }
        }

        return $allVisits;
    }

    private function createTrackingData(array $visit, array $action): TrackingData
    {
        $ip = $visit['visitIp'] ?? null;
        $userId = $visit['visitorId'] ?? null;

        $site = null;
        if ($siteId = $visit['idSite']) {
            try {
                $site = new Site($siteId);
            } catch (\Exception) {
            }
        }
        $siteUrl = $site?->getMainUrl();

        $url = $action['url'] ?? null;
        $title = $action['pageTitle'] ?? $action['title'] ?? null;

        $timestamp = $action['timestamp'] ?? $visit['serverTimestamp'] ?? null;
        $timeSpent = $action['timeSpent'] ?? 0;

        $extraData = [];
        static $visitFields = [
            'idVisit',
            'visitorId',
            'languageCode',
            'browserName',
            'countryCode',
            'regionCode',
            'city',
            'latitude',
            'longitude',
            'referrerKeyword',
            'referrerUrl',
            'interactions',
        ];
        foreach ($visitFields as $visitFieldName) {
            if (isset($visit[$visitFieldName])) {
                $extraData[$visitFieldName] = $visit[$visitFieldName];
            }
        }

        return new TrackingData(
            siteName: $siteUrl,
            visitIp: $ip,
            userId: $userId,
            timestamp: $timestamp,
            url: $url,
            title: $title,
            timeSpent: $timeSpent,
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

    private function sendTraceToLRS(ConverterResponse $trace): StoreResponse
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
