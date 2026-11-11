<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Frontenderrors\Dashboard;

use Joomla\Plugin\Healthcheck\Frontenderrors\Dashboard\Status;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the dashboard status rules.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors status rules')]
class StatusTest extends UnitTestCase
{
    public function testTile(): void
    {
        $this->assertSame('success', Status::tile(0, 0, 3, false));
        $this->assertSame('warning', Status::tile(2, 2, 3, false));
        $this->assertSame('error', Status::tile(3, 3, 3, false));
        $this->assertSame('error', Status::tile(1, 1, 3, true));
    }

    public function testBadge(): void
    {
        $this->assertSame('warning', Status::badge(2, 3));
        $this->assertSame('danger', Status::badge(3, 3));
    }

    public function testTrend(): void
    {
        $this->assertSame(['direction' => 'up', 'delta' => 4], Status::trend(5, 1));
        $this->assertSame(['direction' => 'down', 'delta' => 4], Status::trend(1, 5));
        $this->assertSame(['direction' => 'same', 'delta' => 0], Status::trend(2, 2));
    }
}
