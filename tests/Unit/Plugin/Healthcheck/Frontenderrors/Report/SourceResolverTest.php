<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Frontenderrors\Report;

use Joomla\Plugin\Healthcheck\Frontenderrors\Report\SourceResolver;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the source resolver.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors source resolver')]
class SourceResolverTest extends UnitTestCase
{
    public static function sources(): array
    {
        return [
            ['/media/system/js/core.min.js', 'core'],
            ['/media/vendor/bootstrap/js/x.js', 'core'],
            ['/media/com_foo/js/a.js', 'com_foo'],
            ['/media/plg_system_bar/js/b.js', 'plg_system_bar'],
            ['/media/mod_baz/c.js', 'mod_baz'],
            ['/media/templates/site/cassiopeia/js/t.js', 'tpl_cassiopeia'],
            ['/components/com_foo/x.js', 'com_foo'],
            ['/modules/mod_baz/x.js', 'mod_baz'],
            ['/plugins/content/qux/x.js', 'plg_content_qux'],
            ['/templates/shaper/js/x.js', 'tpl_shaper'],
            ['https://cdn.example.com/lib.js', 'external:cdn.example.com'],
            ['', null],
            ['/images/x.png', null],
        ];
    }

    #[DataProvider('sources')]
    public function testResolve(string $file, ?string $expected): void
    {
        $this->assertSame($expected, SourceResolver::resolve($file));
    }

    public function testSubfolderInstall(): void
    {
        $this->assertSame('com_foo', SourceResolver::resolve('/joomla/en/media/com_foo/js/a.js'));
        $this->assertSame('tpl_shaper', SourceResolver::resolve('/joomla/templates/shaper/x.js'));
    }

    public function testLibraryMedia(): void
    {
        $this->assertSame('lib_foo', SourceResolver::resolve('/media/lib_foo/js/a.js'));
    }
}
