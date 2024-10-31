<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordStore;

use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterResponse;

interface StoreInterface
{
    public function store(ConverterResponse $trace): StoreResponse;
}