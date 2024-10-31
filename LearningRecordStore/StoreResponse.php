<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\LearningRecordStore;

use JsonException;
use Piwik\Plugins\Walruc\Exceptions\StorageException;

class StoreResponse
{
    private string $uuid;

    public function __construct(string $uuid)
    {
        $this->uuid = $uuid;
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw StorageException::storageFailed('Invalid JSON response', $e);
        }

        if (!isset($data[0])) {
            throw StorageException::invalidResponse();
        }

        return new self(
            uuid: $data[0],
        );
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }
}