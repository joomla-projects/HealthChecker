<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Scan;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the result cache wrapper
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The result store')]
class ResultStoreTest extends UnitTestCase
{
    #[TestDox('stores and returns an array')]
    public function testRoundTrip()
    {
        $bucket     = [];
        $controller = $this->createStub(OutputController::class);
        $controller->method('store')->willReturnCallback(function ($data, $id) use (&$bucket) {
            $bucket[$id] = $data;

            return true;
        });
        $controller->method('get')->willReturnCallback(function ($id) use (&$bucket) {
            return $bucket[$id] ?? false;
        });

        $factory = $this->createMock(CacheControllerFactoryInterface::class);
        $factory->expects($this->atLeastOnce())->method('createCacheController')
            ->with('output', $this->callback(fn ($o) => $o['defaultgroup'] === 'plg_healthcheck_database' && $o['lifetime'] === 10080))
            ->willReturn($controller);

        $store = new ResultStore($factory);
        $store->put('zerodates', ['a' => 1]);

        $this->assertSame(['a' => 1], $store->get('zerodates'));
        $this->assertNull($store->get('unused'));
    }

    #[TestDox('turns a cache exception into null')]
    public function testExceptionBecomesNull()
    {
        $factory = $this->createStub(CacheControllerFactoryInterface::class);
        $factory->method('createCacheController')->willThrowException(new \RuntimeException('cache down'));

        $store = new ResultStore($factory);

        $this->assertNull($store->get('unused'));
        $store->put('unused', ['x']);
        $store->remove('unused');
    }
}
