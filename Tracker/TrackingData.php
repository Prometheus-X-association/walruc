<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\Tracker;

use InvalidArgumentException;

/**
 * Data Transfer Object for Matomo tracking data
 */
class TrackingData
{
    private ?string $siteName;
    private ?string $visitIp;
    private ?string $userId;
    private ?int $timestamp;
    private ?string $url;
    private ?string $title;
    private ?int $timeSpent;
    private array $extraData;

    /**
     * @param string|null $siteName Site Name
     * @param string|null $visitIp Visitor IP address
     * @param string|null $userId User identifier
     * @param int|null $timestamp Visit timestamp
     * @param string|null $url Page URL
     * @param string|null $title Page Title
     * @param int|null $timeSpent
     * @param array $extraData Additional visit properties
     */
    public function __construct(
        ?string $siteName,
        ?string $visitIp,
        ?string $userId,
        ?int $timestamp,
        ?string $url,
        ?string $title,
        ?int $timeSpent,
        array $extraData = [],
    ) {
        if ($timestamp !== null && $timestamp < 0) {
            throw new InvalidArgumentException('Timestamp must be positive');
        }

        if ($timeSpent !== null && $timeSpent < 0) {
            throw new InvalidArgumentException('Time spent must be positive');
        }

        if ($visitIp !== null && !filter_var($visitIp, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Invalid IP address format');
        }

        if ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
            $parts = parse_url($url);
            if ($parts === false || !isset($parts['host'])) {
                throw new InvalidArgumentException('Invalid URL format');
            }
        }

        $this->siteName = $siteName;
        $this->visitIp = $visitIp;
        $this->userId = $userId;
        $this->timestamp = $timestamp;
        $this->url = $url;
        $this->title = $title;
        $this->timeSpent = $timeSpent;
        $this->extraData = $extraData;
    }

    public function toArray(): array
    {
        return array_merge(
            [
                'siteName' => $this->siteName,
                'visitIp' => $this->visitIp,
                'user_id' => $this->userId,
                'actionDetails' => [
                    'type' => 'action',
                    'timestamp' => $this->timestamp,
                    'url' => $this->url,
                    'title' => $this->title,
                    'timeSpent' => $this->timeSpent ?: 0,
                ],
            ],
            $this->extraData,
        );
    }
}