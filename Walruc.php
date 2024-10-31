<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc;

use Piwik\Plugin;

class Walruc extends Plugin
{
    public function isTrackerPlugin(): bool
    {
        return true;
    }
}
