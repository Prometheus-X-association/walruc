<?php

declare(strict_types=1);

namespace Piwik\Plugins\Walruc\tests\Integration;

use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Walruc
 * @group WalrucTest
 * @group Plugins
 */
class WalrucTest extends IntegrationTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // set up your test here if needed
    }

    public function tearDown(): void
    {
        // clean up your test here if needed

        parent::tearDown();
    }

    /**
     * All your actual test methods should start with the name "test"
     */
    public function testSimpleAddition()
    {
        $this->assertEquals(2, 1 + 1);
    }
}
