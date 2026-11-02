<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Commands;

/**
 * Outcome of the export of one action
 */
enum ActionStatus
{
    /** Sent, or simulated in dry run */
    case Sent;

    /** Skipped because its data is invalid */
    case Skipped;

    /** Rejected by the LRC or LRS: the next actions of the visit can still be sent */
    case Rejected;

    /** The LRC or LRS is unavailable or misconfigured: the next actions would fail too */
    case Unavailable;
}
