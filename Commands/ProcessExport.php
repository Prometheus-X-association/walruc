<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Commands;

use Piwik\API\Request;
use Piwik\Container\StaticContainer;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\ExportFileException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\Http\RetryHandlerInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreResponse;
use Piwik\Plugins\Walruc\Tracker\TrackingData;
use Piwik\Site;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProcessExport extends ConsoleCommand
{
    private const API_PAGE_LIMIT = 1000;
    private const API_PAGE_DELAY_MS = 200;
    private const API_MAX_ATTEMPTS = 5;
    private const API_RETRY_DELAY_MS = 1000;
    private const MAX_CONSECUTIVE_UNAVAILABLE_VISITS = 3;

    private LoggerInterface $logger;
    private ConverterInterface $converter;
    private StoreInterface $store;
    private RetryHandlerInterface $retryHandler;
    private bool $fetchFailed = false;
    private int $skippedVisits = 0;
    private int $skippedActions = 0;
    private int $consecutiveUnavailableVisits = 0;
    private int $heldBackVisits = 0;
    /** @var array<int, string|null> Main URL of each site, by site ID */
    private array $siteUrls = [];

    public function __construct()
    {
        parent::__construct();

        $this->logger = StaticContainer::get(LoggerInterface::class);
        $this->converter = StaticContainer::get(ConverterInterface::class);
        $this->store = StaticContainer::get(StoreInterface::class);
        $this->retryHandler = StaticContainer::get(RetryHandlerInterface::class);

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
        $totalVisits = 0;

        foreach ($io->progressIterate($visits) as $visit) {
            $totalVisits++;
            $failedVisit = $this->processVisit($visit, $dryRun);
            if ($failedVisit !== null) {
                $failedVisits[] = $failedVisit;
            }
        }

        $errorCount = count($failedVisits);
        $successCount = $totalVisits - $errorCount - $this->skippedVisits;

        $processingTime = round(microtime(true) - $startTime, 2);

        $io->section('Processing Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total visits', $totalVisits],
                ['Successfully processed', $successCount],
                ['Failed', $errorCount],
                ['Skipped visits (no valid actions)', $this->skippedVisits],
                ['Skipped actions (invalid data)', $this->skippedActions],
                ['Processing time', $processingTime . ' seconds'],
            ],
        );

        $this->logger->debug('Processing completed', [
            'total_visits' => $totalVisits,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'processing_time' => $processingTime,
        ]);

        if ($this->skippedActions > 0) {
            $io->warning(
                sprintf('%d actions with invalid data were skipped, see the logs for details', $this->skippedActions),
            );
        }

        if ($this->heldBackVisits > 0) {
            $io->warning(sprintf(
                'The LRC or LRS failed for %d visits in a row, so the next %d visits were not sent',
                self::MAX_CONSECUTIVE_UNAVAILABLE_VISITS,
                $this->heldBackVisits,
            ));
        }

        if (!empty($failedVisits)) {
            $this->reportFailedVisits($io, $failedVisits);
        }

        return $errorCount > 0 || $this->fetchFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Saves failed visits to a file that can be given back to the --file option
     *
     * @param list<array<string, mixed>> $failedVisits
     */
    private function reportFailedVisits(SymfonyStyle $io, array $failedVisits): void
    {
        try {
            $errorFile = $this->saveFailedVisitsToFile($failedVisits);
        } catch (ExportFileException $e) {
            $io->error($e->getMessage());
            $this->logger->error('Failed visits could not be saved', [
                'error' => $e->getMessage(),
                'idVisits' => array_column($failedVisits, 'idVisit'),
            ]);
            return;
        }

        $io->warning(
            sprintf(
                '%d visits had processing errors and were saved to: %s',
                count($failedVisits),
                $errorFile,
            ),
        );
        $io->text('You can retry these failed visits with --file=' . $errorFile);
    }

    /**
     * @param array<string, mixed> $visit
     * @return array<string, mixed>|null The visit restricted to the actions to retry, or null if there is nothing to retry
     */
    private function processVisit(array $visit, bool $dryRun): ?array
    {
        if (!is_array($visit['actionDetails'] ?? null) || $visit['actionDetails'] === []) {
            $this->logger->debug('Visit has no actions, skipping', [
                'idVisit' => $visit['idVisit'] ?? 'unknown',
            ]);
            $this->skippedVisits++;
            return null;
        }

        // Only the actions that were not sent are kept, so that a retry does not duplicate traces in the LRS
        $actions = array_values($visit['actionDetails']);
        if ($this->isServiceUnavailable()) {
            $this->heldBackVisits++;
            $unsentActions = $actions;
        } else {
            $unsentActions = $this->sendActions($visit, $actions, $dryRun);
        }
        if ($unsentActions === []) {
            return null;
        }

        $visit['actionDetails'] = $unsentActions;
        return $visit;
    }

    /**
     * @param array<string, mixed> $visit
     * @param list<mixed> $actions
     * @return list<mixed> The actions that were not sent
     */
    private function sendActions(array $visit, array $actions, bool $dryRun): array
    {
        $unsentActions = [];
        $answered = false;
        foreach ($actions as $index => $action) {
            $status = $this->processAction($visit, $action, $dryRun);
            if ($status === ActionStatus::Unavailable) {
                $this->consecutiveUnavailableVisits++;
                return array_merge($unsentActions, array_slice($actions, $index));
            }
            if ($status === ActionStatus::Rejected) {
                $unsentActions[] = $action;
            }
            $answered = $answered || $status !== ActionStatus::Skipped;
        }

        if ($answered) {
            // The LRC or LRS answered
            $this->consecutiveUnavailableVisits = 0;
        } else {
            // Every action had invalid data, so nothing was sent
            $this->skippedVisits++;
        }

        return $unsentActions;
    }

    /**
     * Whether the LRC or LRS failed for too many visits in a row, so that the next visits are not sent
     */
    private function isServiceUnavailable(): bool
    {
        return $this->consecutiveUnavailableVisits >= self::MAX_CONSECUTIVE_UNAVAILABLE_VISITS;
    }

    /**
     * @param array<string, mixed> $visit
     * @param mixed $action Expected to be an array, anything else is skipped as invalid data
     */
    private function processAction(array $visit, mixed $action, bool $dryRun): ActionStatus
    {
        try {
            // Create data for LRC
            $trackingData = $this->createTrackingData($visit, $action);
        } catch (\Exception | \TypeError $e) {
            // Invalid data would fail on every retry, so the action is skipped
            $this->logger->error('Invalid action skipped', ['error' => $e->getMessage()]);
            $this->logger->debug('Invalid action skipped: url', ['url' => $action['url'] ?? 'unknown']);
            $this->skippedActions++;
            return ActionStatus::Skipped;
        }

        if ($dryRun) {
            $this->logger->info(
                'Data that would be sent : '
                . json_encode($trackingData->toArray()),
            );
            return ActionStatus::Sent;
        }

        return $this->sendTrackingData($trackingData);
    }

    /**
     * Sends tracking data to the LRC, then the converted trace to the LRS
     */
    private function sendTrackingData(TrackingData $trackingData): ActionStatus
    {
        try {
            $convertedData = $this->sendDataToLRC(trackingData: $trackingData);
            $storeData = $this->sendTraceToLRS(trace: $convertedData);
        } catch (ConversionException | StorageException $e) {
            // Already logged by sendDataToLRC() / sendTraceToLRS()
            return $e->isRetryable() || $e->isConfigurationError() ? ActionStatus::Unavailable : ActionStatus::Rejected;
        }

        $this->logger->info('Request processed', ['uuid' => $storeData->getUuid()]);
        return ActionStatus::Sent;
    }

    /**
     * Load data from a JSON file
     */
    private function loadDataFromFile(SymfonyStyle $io, string $filePath): \Generator
    {
        $this->logger->debug('Loading data from file', [
            'file' => $filePath,
        ]);

        $io->text("File: $filePath");

        $io->section('Reading file...');
        try {
            $visits = $this->readExportFile($filePath);
        } catch (ExportFileException $e) {
            $io->error($e->getMessage());
            $this->fetchFailed = true;
            return;
        }

        $io->text("Fetched " . count($visits) . " visits");
        foreach ($visits as $visit) {
            yield $visit;
        }
    }

    /**
     * @return list<array<mixed>>
     * @throws ExportFileException If the file cannot be read or does not contain a list of visits
     */
    private function readExportFile(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw ExportFileException::notFound($filePath);
        }

        $content = is_readable($filePath) ? file_get_contents($filePath) : false;
        if ($content === false) {
            throw ExportFileException::unreadable($filePath);
        }

        try {
            return self::toVisitList(json_decode($content, true, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException | \UnexpectedValueException $e) {
            throw ExportFileException::invalidContent($filePath, $e);
        }
    }

    /**
     * Fetch data from internal Matomo API with pagination
     */
    private function loadDataFromInternalAPI(SymfonyStyle $io, string $siteId, string $date): \Generator
    {
        // The range period needs two dates: a single date is a range of one day
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date)) {
            $date = "$date,$date";
        }

        $io->text('Fetching data from Matomo API');
        $io->text("Site ID: $siteId, Date: $date");
        $this->logger->debug('Starting API data fetch', [
            'site_id' => $siteId,
            'date' => $date,
            'limit' => self::API_PAGE_LIMIT,
        ]);

        $offset = 0;
        $pageCount = 0;

        // Prepare API parameters
        $params = [
            'method' => 'Live.getLastVisitsDetails',
            'format' => 'json',
            'idSite' => $siteId,
            'period' => 'range',
            'date' => $date,
            'filter_limit' => self::API_PAGE_LIMIT,
            'filter_offset' => $offset,
        ];

        do {
            $pageCount++;

            $io->text("Fetching page $pageCount (offset: $offset)...");

            $params['filter_offset'] = $offset;
            $visits = $this->fetchVisitsPage($io, $params, $pageCount);

            foreach ($visits as $visit) {
                yield $visit;
            }

            // Update offset for next page
            $offset += self::API_PAGE_LIMIT;

            // If we got fewer results than the limit, we've reached the end
            $continueFetching = count($visits) >= self::API_PAGE_LIMIT;

            // Add delay between requests to reduce server load
            if ($continueFetching) {
                usleep(self::API_PAGE_DELAY_MS * 1000);
            }
        } while ($continueFetching);

        $this->logger->debug('API data fetch completed', [
            'total_pages' => $pageCount,
            'last_offset' => $offset,
        ]);
    }

    /**
     * Fetch one page of visits from the internal Matomo API, with exponential backoff
     *
     * @param array<string, mixed> $params Live.getLastVisitsDetails API parameters
     * @return list<array<mixed>> The visits of the page, empty when there are no more visits or the API keeps failing
     */
    private function fetchVisitsPage(SymfonyStyle $io, array $params, int $pageCount): array
    {
        try {
            $visits = $this->retryHandler->executeWithRetry(
                // The API returns its errors instead of throwing them, so they are detected here to be retried
                operation: function () use ($params): array {
                    $response = (new Request($params))->process();
                    if (!is_string($response)) {
                        throw new \UnexpectedValueException(
                            'Unexpected Matomo API response type: ' . get_debug_type($response),
                        );
                    }

                    return $this->decodeVisits($response);
                },
                logger: $this->logger,
                operationName: "Matomo API request (page $pageCount)",
                maxAttempts: self::API_MAX_ATTEMPTS,
                initialDelayMs: self::API_RETRY_DELAY_MS,
            );
        } catch (\Exception $e) {
            $this->logger->debug('API request failed', [
                'error' => $e->getMessage(),
                'params' => $params,
                'last_offset' => $params['filter_offset'],
            ]);

            $io->error('Failed to fetch visits: ' . $e->getMessage());

            $io->note(sprintf('Visits from offset %d onwards were not fetched', $params['filter_offset']));

            $this->fetchFailed = true;
            return [];
        }

        if (empty($visits)) {
            $io->text('No more visits found. Stopping fetch.');

            $this->logger->debug('End of data reached, no more visits available', [
                'page' => $pageCount,
                'offset' => $params['filter_offset'],
            ]);

            return [];
        }

        $visitCount = count($visits);
        $io->text("Fetched $visitCount visits");

        $this->logger->debug('Processing page results', [
            'page' => $pageCount,
            'visits_count' => $visitCount,
        ]);

        return $visits;
    }

    /**
     * Decode a Live.getLastVisitsDetails JSON response
     *
     * @return list<array<mixed>>
     * @throws \UnexpectedValueException If the response is invalid JSON, an API error or not a list of visits
     */
    private function decodeVisits(string $response): array
    {
        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->debug('Invalid JSON response', [
                'error' => $e->getMessage(),
                'response_preview' => substr($response, 0, 200),
            ]);

            throw new \UnexpectedValueException('Invalid JSON content: ' . $e->getMessage(), 0, $e);
        }

        // The API renders its exceptions as {"result": "error", "message": "..."}
        if (is_array($data) && ($data['result'] ?? null) === 'error') {
            throw new \UnexpectedValueException('Matomo API error: ' . ($data['message'] ?? 'unknown error'));
        }

        return self::toVisitList($data);
    }

    /**
     * @return list<array<mixed>>
     * @throws \UnexpectedValueException If the data is not a list of visits
     */
    private static function toVisitList(mixed $data): array
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw new \UnexpectedValueException('A list of visits was expected');
        }

        $visits = [];
        foreach ($data as $visit) {
            if (!is_array($visit)) {
                throw new \UnexpectedValueException('A list of visits was expected');
            }
            $visits[] = $visit;
        }

        return $visits;
    }

    /**
     * @param array<string, mixed> $visit
     * @param array<string, mixed> $action
     */
    private function createTrackingData(array $visit, array $action): TrackingData
    {
        $ip = $visit['visitIp'] ?? null;
        $userId = $visit['visitorId'] ?? null;

        $siteUrl = $this->getSiteUrl($visit['idSite'] ?? null);

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

    /**
     * @param mixed $siteId Expected to be a site ID, anything else gives no URL
     */
    private function getSiteUrl(mixed $siteId): ?string
    {
        if (!is_numeric($siteId)) {
            return null;
        }

        $siteId = (int)$siteId;
        if (!array_key_exists($siteId, $this->siteUrls)) {
            try {
                $this->siteUrls[$siteId] = (new Site($siteId))->getMainUrl();
            } catch (\Exception $e) {
                // Unknown site: the traces are sent without a site name
                $this->logger->debug('Site not found', ['idSite' => $siteId, 'error' => $e->getMessage()]);
                $this->siteUrls[$siteId] = null;
            }
        }

        return $this->siteUrls[$siteId];
    }

    private function sendDataToLRC(TrackingData $trackingData): ConverterResponse
    {
        $this->logger->debug('Sending data to LRC');

        try {
            $response = $this->converter->convert(trackingData: $trackingData);

            $this->logger->debug('LRC conversion successful');

            return $response;
        } catch (ConversionException $e) {
            $this->logger->error('Conversion failed', ['error' => $e->getMessage()]);
            $this->logger->debug('Conversion failed: data', ['data' => $trackingData->toArray()]);
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
            $this->logger->error('Storage failed', ['error' => $e->getMessage()]);
            $this->logger->debug('Storage failed: data', ['data' => $trace->getTrace()]);
            throw $e;
        }
    }

    /**
     * Saves failed visits in a format compatible with the --file option
     *
     * @param list<array<string, mixed>> $failedVisits
     *
     * @throws ExportFileException If the file cannot be written
     */
    private function saveFailedVisitsToFile(array $failedVisits): string
    {
        $filename = sprintf(
            '%s/walruc_failed_%s_%s.json',
            StaticContainer::get('path.tmp'),
            date('Ymd_His'),
            bin2hex(random_bytes(4)),
        );

        $this->logger->debug('Saving failed visits to file', [
            'file' => $filename,
            'count' => count($failedVisits),
        ]);

        try {
            $json = json_encode($failedVisits, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ExportFileException::unwritable($filename, $e);
        }

        if (file_put_contents($filename, $json) === false) {
            throw ExportFileException::unwritable($filename);
        }

        return $filename;
    }
}
