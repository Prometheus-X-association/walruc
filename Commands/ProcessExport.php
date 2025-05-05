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
                'Path to the Matomo export file (JSON format)',
            )
            ->addRequiredValueOption(
                'site-id',
                's',
                'Site ID to fetch visits for (can be an ID, comma-separated IDs like "1,2,3", or "all")',
            )
            ->addRequiredValueOption(
                'date',
                'd',
                'Date to fetch (YYYY-MM-DD, or date range YYYY-MM-DD,YYYY-MM-DD, or "last30", "previous7" etc.)',
                'last1000',
            )
            ->addNoValueOption(
                'dry-run',
                null,
                'Simulate processing without sending data',
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
            $visits = $this->loadDataFromFile($io, $filePath);
        } else {
            $date = $input->getOption('date');
            $visits = $this->loadDataFromInternalAPI($io, $siteId, $date);
        }

        $this->logger->debug('Starting export processing command', [
            'timestamp' => date('Y-m-d H:i:s'),
            'file' => $filePath,
            'site_id' => $siteId,
            'date' => $input->getOption('date'),
            'dry_run' => $dryRun,
        ]);

        // Process records
        $io->section('Processing visits...');

        $failedVisits = [];
        $totalVisits = $successCount = $errorCount = 0;

        foreach ($io->progressIterate($visits) as $visit) {
            $totalVisits++;
            $processed = $this->processVisit($visit, $dryRun);
            if ($processed) {
                $successCount++;
            } else {
                $failedVisits [] = $visit;
                $errorCount++;
            }
        }

        $io->section('Processing Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total visits', $totalVisits],
                ['Successfully processed', $successCount],
                ['Failed', $errorCount],
                ['Processing time', round(microtime(true) - $startTime, 2) . ' seconds'],
            ],
        );

        $this->logger->debug('Processing completed', [
            'total_visits' => $totalVisits,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'processing_time' => $processingTime,
        ]);

        // Handle failed visits
        if (!empty($failedVisits)) {
            $errorFile = $this->saveFailedVisitsToFile($failedVisits);
            $io->warning(
                sprintf(
                    '%d visits had processing errors and were saved to: %s',
                    count($failedVisits),
                    $errorFile,
                ),
            );
            $io->text('You can retry these failed visits with --file=' . $errorFile);
        }

        return self::SUCCESS;
    }

    private function processVisit(array $visit, bool $dryRun): bool
    {
        if (!isset($visit['actionDetails'])) {
            $this->logger->debug('Visit has no actions, skipping', [
                'idVisit' => $visit['idVisit'] ?? 'unknown',
            ]);
            return false;
        }

        foreach ($visit['actionDetails'] as $action) {
            try {
                // Create data for LRC
                $trackingData = $this->createTrackingData($visit, $action);
            } catch (\Exception $e) {
                $this->logger->error('Action processing failed', [
                    'error' => $e->getMessage(),
                    'url' => $action['url'] ?? 'unknown',
                ]);
                return false;
            }

            if ($dryRun) {
                $this->logger->info(
                    'Data that would be sent : '
                    . json_encode($trackingData->toArray()),
                );
                continue;
            }

            // Send data to the LRC
            try {
                $convertedData = $this->sendDataToLRC(trackingData: $trackingData);
            } catch (ConversionException $exception) {
                return false;
            }

            // Send converted trace to LRS
            try {
                $storeData = $this->sendTraceToLRS(trace: $convertedData);
            } catch (StorageException $exception) {
                return false;
            }
            $this->logger->info('Request processed', ['uuid' => $storeData->getUuid()]);
        }

        return true;
    }

    /**
     * Load data from a JSON file
     */
    private function loadDataFromFile(SymfonyStyle $io, string $filePath): \Generator
    {
        $this->logger->debug('Loading data from file', [
            'file' => $filePath,
        ]);

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

        $io->text("Fetched " . count($data) . " visits");
        foreach ($data as $visit) {
            yield $visit;
        }
    }

    /**
     * Fetch data from internal Matomo API with pagination
     */
    private function loadDataFromInternalAPI(SymfonyStyle $io, string $siteId, string $date): \Generator
    {
        $delayMs = 200;
        $limit = 1000;
        $maxRetries = 5;
        $retryDelayMs = 1000;

        $io->text('Fetching data from Matomo API');
        $io->text("Site ID: $siteId, Date: $date");
        $this->logger->debug('Starting API data fetch', [
            'site_id' => $siteId,
            'date' => $date,
            'limit' => $limit,
        ]);

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
            'filter_limit' => $limit,
            'filter_offset' => $offset,
        ];

        while ($continueFetching) {
            $pageCount++;

            $io->text("Fetching page $pageCount (offset: $offset)...");

            $params['filter_offset'] = $offset;

            $attempt = 0;
            $success = false;
            $lastError = null;

            // Call the API internally with exponential backoff
            while (!$success && $attempt < $maxRetries) {
                $attempt++;

                try {
                    $request = new Request($params);
                    $response = $request->process();
                    $success = true;

                    $this->logger->debug('API request successful', [
                        'attempt' => $attempt,
                        'page' => $pageCount,
                        'offset' => $offset,
                    ]);
                } catch (\Exception $e) {
                    $lastError = $e;
                    $currentRetryDelay = $retryDelayMs * pow(2, $attempt - 1);

                    $this->logger->debug('API request attempt failed', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    if ($attempt < $maxRetries) {
                        $io->warning(
                            sprintf(
                                "API request failed (attempt %d/%d). Retrying in %.1f seconds...",
                                $attempt,
                                $maxRetries,
                                $currentRetryDelay / 1000,
                            ),
                        );

                        usleep($currentRetryDelay * 1000);
                    }
                    break;
                }
            }

            if (!$success) {
                $this->logger->debug('API request failed after multiple attempts', [
                    'error' => $lastError?->getMessage(),
                    'params' => $params,
                    'attempts' => $maxRetries,
                    'last_offset' => $offset,
                ]);

                $io->error(
                    sprintf(
                        "Failed to fetch data after %d attempts. Last error: %s",
                        $maxRetries,
                        $lastError?->getMessage() ?? 'Unknown error',
                    ),
                );

                $io->note(
                    sprintf(
                        "To resume from this point later, you can use: --site-id=%s --date=%s --offset=%d",
                        $siteId,
                        $date,
                        $offset,
                    ),
                );

                break;
            }

            $responseData = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->debug('Invalid JSON response', [
                    'error' => json_last_error_msg(),
                    'response_preview' => substr($response, 0, 200),
                ]);

                throw new \InvalidArgumentException('Invalid JSON content: ' . json_last_error_msg());
            }

            // Check if response is empty or not an array
            if (!is_array($responseData) || empty($responseData)) {
                $io->text('No more visits found. Stopping fetch.');

                $this->logger->debug('End of data reached, no more visits available', [
                    'page' => $pageCount,
                    'offset' => $offset,
                ]);

                break;
            }

            // Add visits to collection
            $visitCount = count($responseData);
            $io->text("Fetched $visitCount visits");

            $this->logger->debug('Processing page results', [
                'page' => $pageCount,
                'visits_count' => $visitCount,
            ]);

            foreach ($responseData as $visit) {
                yield $visit;
            }

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

        $this->logger->debug('API data fetch completed', [
            'total_pages' => $pageCount,
            'last_offset' => $offset,
        ]);
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
        $this->logger->debug('Sending data to LRC');

        try {
            $response = $this->converter->convert(trackingData: $trackingData);

            $this->logger->debug('LRC conversion successful');

            return $response;
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
        $this->logger->debug('Sending trace to LRS');

        try {
            $response = $this->store->store($trace);

            $this->logger->debug('LRS storage successful');

            return $response;
        } catch (StorageException $e) {
            $this->logger->error('Storage failed', [
                'error' => $e->getMessage(),
                'data' => $trace->getTrace(),
            ]);
            throw $e;
        }
    }

    /**
     * Saves failed visits in a format compatible with the --file option
     */
    private function saveFailedVisitsToFile(array $failedVisits): string
    {
        $timestamp = date('Ymd_His');
        $filename = PIWIK_DOCUMENT_ROOT . "/tmp/walruc_failed_{$timestamp}.json";

        $this->logger->debug('Saving failed visits to file', [
            'file' => $filename,
            'count' => count($failedVisits),
        ]);

        file_put_contents($filename, json_encode($failedVisits, JSON_PRETTY_PRINT));

        return $filename;
    }
}
