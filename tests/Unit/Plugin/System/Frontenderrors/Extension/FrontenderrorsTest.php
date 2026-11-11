<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  System.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\System\Frontenderrors\Extension;

use Joomla\Plugin\System\Frontenderrors\Extension\Frontenderrors;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Frontend Errors collector plugin decisions.
 *
 * @package     Joomla.UnitTest
 * @subpackage  System.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors collector plugin')]
class FrontenderrorsTest extends UnitTestCase
{
    public function testInjectsOnCollectingSiteHtml(): void
    {
        $this->assertTrue(Frontenderrors::shouldInject(true, true, true, false, 'html'));
    }

    public function testNeverInjectsInAdministrator(): void
    {
        $this->assertFalse(Frontenderrors::shouldInject(false, true, true, false, 'html'));
    }

    public function testNeverInjectsWhenHealthCheckPluginDisabled(): void
    {
        $this->assertFalse(Frontenderrors::shouldInject(true, false, true, false, 'html'));
        $this->assertFalse(Frontenderrors::shouldInject(true, false, false, true, 'html'));
    }

    public function testNotCollectingInjectsOnlyForScan(): void
    {
        $this->assertFalse(Frontenderrors::shouldInject(true, true, false, false, 'html'));
        $this->assertTrue(Frontenderrors::shouldInject(true, true, false, true, 'html'));
    }

    public function testNonHtmlFormatsAreSkipped(): void
    {
        $this->assertFalse(Frontenderrors::shouldInject(true, true, true, false, 'json'));
        $this->assertFalse(Frontenderrors::shouldInject(true, true, true, false, 'raw'));
    }

    public function testAcceptsValidBody(): void
    {
        $this->assertSame(['page' => '/a', 'reports' => []], Frontenderrors::acceptBody('{"page":"/a","reports":[]}', true));
    }

    public function testRejectsOversizedBody(): void
    {
        $body = '{"page":"/a","pad":"' . str_repeat('x', 8200) . '"}';

        $this->assertNull(Frontenderrors::acceptBody($body, true));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->assertNull(Frontenderrors::acceptBody('{nope', true));
        $this->assertNull(Frontenderrors::acceptBody('"scalar"', true));
    }

    public function testRejectsWhenNotCollecting(): void
    {
        $this->assertNull(Frontenderrors::acceptBody('{"page":"/a"}', false));
    }

    public function testRejectsEmptyBody(): void
    {
        $this->assertNull(Frontenderrors::acceptBody('', true));
    }

    public function testBodyLimitIsInclusive(): void
    {
        $exact = '{"p":"' . str_repeat('x', 8192 - 8) . '"}';

        $this->assertSame(8192, \strlen($exact));
        $this->assertIsArray(Frontenderrors::acceptBody($exact, true));
        $this->assertNull(Frontenderrors::acceptBody($exact . ' ', true));
    }

    public function testOnlyPostIsABeacon(): void
    {
        $this->assertTrue(Frontenderrors::isBeaconMethod('POST'));
        $this->assertTrue(Frontenderrors::isBeaconMethod('post'));
        $this->assertFalse(Frontenderrors::isBeaconMethod('GET'));
        $this->assertFalse(Frontenderrors::isBeaconMethod(''));
    }
}
