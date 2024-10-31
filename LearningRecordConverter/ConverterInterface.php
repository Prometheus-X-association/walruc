<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordConverter;

use Piwik\Plugins\Walruc\Tracker\TrackingData;

interface ConverterInterface
{
    public function convert(TrackingData $trackingData): ConverterResponse;
}