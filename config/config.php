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

return [
    // Dependencies injection
    ConverterInterface::class => DI::autowire(ConverterApi::class),
    StoreInterface::class => DI::autowire(StoreApi::class),
    RetryHandlerInterface::class => DI::autowire(RetryHttpHandler::class),
    HttpClientInterface::class => DI::autowire(HttpClient::class),
];