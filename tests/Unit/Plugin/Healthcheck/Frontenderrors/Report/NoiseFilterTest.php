<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Frontenderrors\Report;

use Joomla\Plugin\Healthcheck\Frontenderrors\Report\NoiseFilter;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the noise filter.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors noise filter')]
class NoiseFilterTest extends UnitTestCase
{
    private static function report(array $over = []): array
    {
        return array_merge([
            'fingerprint' => 'abc',
            'type'        => 'error',
            'message'     => 'boom',
            'file'        => '/media/com_foo/js/a.js',
            'line'        => 1,
            'col'         => 1,
            'stack'       => '',
            'page'        => '/',
            'count'       => 1,
        ], $over);
    }

    public function testKeepsOrdinaryReport(): void
    {
        $this->assertTrue(NoiseFilter::keep(self::report(), []));
    }

    public function testDropsExtensionSchemesInFileOrStack(): void
    {
        foreach (['chrome-extension://', 'moz-extension://', 'safari-web-extension://'] as $scheme) {
            $this->assertFalse(NoiseFilter::keep(self::report(['file' => $scheme . 'abc/x.js']), []));
            $this->assertFalse(NoiseFilter::keep(self::report(['stack' => "at f ($scheme" . 'abc/x.js:1:1)']), []));
        }
    }

    public function testScriptErrorWithoutFileIsDropped(): void
    {
        $this->assertFalse(NoiseFilter::keep(self::report(['message' => 'Script error.', 'file' => '']), []));
        $this->assertFalse(NoiseFilter::keep(self::report(['message' => 'Script error', 'file' => '']), []));
    }

    public function testScriptErrorWithFileIsKept(): void
    {
        $this->assertTrue(NoiseFilter::keep(self::report(['message' => 'Script error.']), []));
    }

    public function testEmptyMessageAndFileIsDropped(): void
    {
        $this->assertFalse(NoiseFilter::keep(self::report(['message' => '', 'file' => '']), []));
    }

    public function testIgnorePatternMatchesMessageOrFileCaseInsensitively(): void
    {
        $this->assertFalse(NoiseFilter::keep(self::report(['message' => 'ResizeObserver loop limit']), ['resizeobserver']));
        $this->assertFalse(NoiseFilter::keep(self::report(), ['COM_FOO']));
        $this->assertTrue(NoiseFilter::keep(self::report(), ['nomatch']));
    }

    public function testEmptyPatternIsIgnored(): void
    {
        $this->assertTrue(NoiseFilter::keep(self::report(), ['']));
    }
}
