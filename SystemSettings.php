<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc;

use Piwik\Settings\FieldConfig;
use Piwik\Settings\Plugin\SystemSetting;
use Piwik\Validators\NotEmpty;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    public SystemSetting $storeEndpoint;

    public SystemSetting $storeApiKey;

    public SystemSetting $convertEndpoint;

    protected function init(): void
    {
        $this->storeEndpoint = $this->createStoreEndpointSetting();
        $this->storeApiKey = $this->createStoreApiKeySetting();
        $this->convertEndpoint = $this->createConvertEndpointSetting();
    }

    private function createStoreEndpointSetting(): SystemSetting
    {
        return $this->makeSetting(
            name: 'lrsEndpoint',
            defaultValue: '',
            type: FieldConfig::TYPE_STRING,
            fieldConfigCallback: static function (FieldConfig $field) {
                $field->title = 'LRS Endpoint URL';
                $field->description = 'The URL of your Learning Record Store API Endpoint to store a trace';
                $field->uiControl = FieldConfig::UI_CONTROL_URL;
                $field->validators[] = new NotEmpty();
            },
        );
    }

    private function createStoreApiKeySetting(): SystemSetting
    {
        return $this->makeSetting(
            name: 'lrsApiKey',
            defaultValue: '',
            type: FieldConfig::TYPE_STRING,
            fieldConfigCallback: static function (FieldConfig $field) {
                $field->title = 'LRS API Key';
                $field->description = 'API key for Learning Record Store authentication';
                $field->uiControl = FieldConfig::UI_CONTROL_PASSWORD;
                $field->validators[] = new NotEmpty();
            },
        );
    }

    private function createConvertEndpointSetting(): SystemSetting
    {
        return $this->makeSetting(
            name: 'lrcEndpoint',
            defaultValue: 'https://lrc-dev.inokufu.space/convert',
            type: FieldConfig::TYPE_STRING,
            fieldConfigCallback: static function (FieldConfig $field) {
                $field->title = 'LRC Endpoint URL';
                $field->description = 'The URL of the Learning Record Converter API Endpoint to convert a trace';
                $field->uiControl = FieldConfig::UI_CONTROL_URL;
                $field->validators[] = new NotEmpty();
            },
        );
    }
}
