<?php

namespace Piwik\Plugins\Walruc\Commands;

use Piwik\Container\StaticContainer;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\Walruc\Exceptions\ConversionException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
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

    protected function configure() : void
    {
        $this->setName('walruc:process-export')
            ->setDescription('Process a Matomo export file in JSON format and send it to LRS')
            ->addRequiredArgument(
                'file',
                'Path to the Matomo export file (JSON format)',
            )
            ->addNoValueOption(
                'dry-run',
                null,
                'Simulate processing without sending data',
            );
    }

    protected function doExecute(): int
    {
        $input = $this->getInput();
        $output = $this->getOutput();

        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file');
        $dryRun = $input->getOption('dry-run');

        // Validate file
        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return self::FAILURE;
        }
        if (!is_readable($filePath)) {
            $io->error("File is not readable: $filePath");
            return self::FAILURE;
        }

        $io->title('Processing Matomo Export File');
        $io->text("File: $filePath");
        if ($dryRun) {
            $io->warning('DRY RUN MODE - No data will be sent to LRS');
        }

        $startTime = microtime(true);

        $io->section('Reading file...');
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: $filePath");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON content: ' . json_last_error_msg());
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
                    $this->logger->info('Data that would be sent : '
                        . json_encode($trackingData->toArray())
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
