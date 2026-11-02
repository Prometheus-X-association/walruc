<?php

declare(strict_types=1);

use Piwik\DI;
use Piwik\Plugins\Walruc\Http\HttpClient;
use Piwik\Plugins\Walruc\Http\HttpClientInterface;
use Piwik\Plugins\Walruc\Http\RetryHandlerInterface;
use Piwik\Plugins\Walruc\Http\RetryHttpHandler;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterApi;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreApi;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
use Piwik\Plugins\Walruc\Tracker\RequestProcessor;

return [
    // Dependencies injection
    ConverterInterface::class => DI::autowire(ConverterApi::class),
    StoreInterface::class => DI::autowire(StoreApi::class),
    RetryHandlerInterface::class => DI::autowire(RetryHttpHandler::class),
    HttpClientInterface::class => DI::autowire(HttpClient::class),

    // The tracker sends the traces while the tracking request is pending: a short timeout and a single retry
    // limit how long an unavailable LRC or LRS holds each request (about 10 seconds per service at most).
    // Traces that still fail are only logged: re-exporting their period with walruc:process-export
    // also sends again the traces of that period that were already stored.
    'Walruc.tracker.httpClient' => DI::autowire(HttpClient::class)
        ->constructorParameter('timeout', 5)
        ->constructorParameter('maxAttempts', 2)
        ->constructorParameter('retryDelayMs', 500),
    RequestProcessor::class => DI::autowire()
        ->constructorParameter(
            'converter',
            DI::autowire(ConverterApi::class)->constructorParameter('httpClient', DI::get('Walruc.tracker.httpClient')),
        )
        ->constructorParameter(
            'store',
            DI::autowire(StoreApi::class)->constructorParameter('httpClient', DI::get('Walruc.tracker.httpClient')),
        ),
];
