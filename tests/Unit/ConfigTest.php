<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Unit;

use DI\Container;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Log\NullLogger;
use Piwik\Plugins\Walruc\Http\HttpClient;
use Piwik\Plugins\Walruc\LearningRecordConverter\ConverterInterface;
use Piwik\Plugins\Walruc\LearningRecordStore\StoreInterface;
use Piwik\Plugins\Walruc\SystemSettings;
use Piwik\Plugins\Walruc\Tracker\RequestProcessor;
use ReflectionClass;
use ReflectionProperty;

use function DI\factory;
use function DI\value;

/**
 * Checks the dependency injection configuration of config/config.php
 *
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class ConfigTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $builder = new ContainerBuilder();
        $builder->addDefinitions(__DIR__ . '/../../config/config.php');
        $builder->addDefinitions([
            LoggerInterface::class => value(new NullLogger()),
            // The settings are not read when the services are built
            SystemSettings::class => factory(
                static fn () => (new ReflectionClass(SystemSettings::class))->newInstanceWithoutConstructor(),
            ),
        ]);
        $this->container = $builder->build();
    }

    public function testTrackerUsesShortHttpPolicy(): void
    {
        // Act
        $requestProcessor = $this->container->get(RequestProcessor::class);

        // Assert
        foreach (['converter', 'store'] as $dependency) {
            $httpClient = self::property(self::property($requestProcessor, $dependency), 'httpClient');
            self::assertEquals(
                ['timeout' => 5, 'maxAttempts' => 2, 'retryDelayMs' => 500],
                self::httpPolicy($httpClient),
                "HTTP policy of the tracker $dependency",
            );
        }
    }

    public function testExportUsesDefaultHttpPolicy(): void
    {
        // Act
        $converter = $this->container->get(ConverterInterface::class);
        $store = $this->container->get(StoreInterface::class);

        // Assert
        $expectedPolicy = ['timeout' => 10, 'maxAttempts' => 3, 'retryDelayMs' => 1000];
        self::assertEquals($expectedPolicy, self::httpPolicy(self::property($converter, 'httpClient')));
        self::assertEquals($expectedPolicy, self::httpPolicy(self::property($store, 'httpClient')));
    }

    /**
     * @return array<string, mixed>
     */
    private static function httpPolicy(object $httpClient): array
    {
        self::assertInstanceOf(HttpClient::class, $httpClient);

        return [
            'timeout' => self::property($httpClient, 'timeout'),
            'maxAttempts' => self::property($httpClient, 'maxAttempts'),
            'retryDelayMs' => self::property($httpClient, 'retryDelayMs'),
        ];
    }

    private static function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
