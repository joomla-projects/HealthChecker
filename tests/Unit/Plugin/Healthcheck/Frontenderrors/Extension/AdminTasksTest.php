<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Frontenderrors\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Language;
use Joomla\Plugin\Healthcheck\Frontenderrors\Extension\Frontenderrors;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Frontend Errors admin task helpers and the details layout.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors admin tasks')]
class AdminTasksTest extends UnitTestCase
{
    private const LAYOUT = JPATH_ROOT . '/plugins/healthcheck/frontenderrors/tmpl/details.php';

    private const EVIL = '<img src=x onerror=alert(1)>"\'';

    protected function setUp(): void
    {
        parent::setUp();

        $language = $this->createStub(Language::class);
        $language->method('_')->willReturnArgument(0);
        $language->method('getPluralSuffixes')->willReturn(['0']);
        $language->method('hasKey')->willReturn(false);
        Factory::$language = $language;

        HTMLHelper::register('date', static fn ($value): string => 'DATE(' . $value . ')');
    }

    protected function tearDown(): void
    {
        HTMLHelper::unregister('date');
        Factory::$language = null;

        parent::tearDown();
    }

    private function error(array $over = []): array
    {
        return $over + [
            'id'               => 7,
            'type'             => 'error',
            'message'          => 'TypeError: x is undefined',
            'file'             => '/media/com_foo/js/a.js',
            'line'             => 3,
            'col'              => 4,
            'stack'            => 'at x (a.js:3:4)',
            'source_extension' => 'com_foo',
            'hits'             => 12,
            'first_seen'       => '2026-10-10 08:00:00',
            'last_seen'        => '2026-10-16 10:00:00',
            'state'            => 0,
            'origins'          => ['visitor'],
        ];
    }

    public function testScanListStartsWithHomeAndStopsAtLimit(): void
    {
        $paths = ['/about', '/', '/about', 'https://example.test/contact', 'https://evil.example/x', '', 'relative', '/blog?x=1'];

        $this->assertSame(
            ['/', '/about', '/contact', '/blog?x=1'],
            Frontenderrors::scanPageList('/', $paths, 'https://example.test', 25)
        );
        $this->assertSame(['/', '/about'], Frontenderrors::scanPageList('/', $paths, 'https://example.test', 2));
        $this->assertSame(['/sub/', '/sub/about'], Frontenderrors::scanPageList('/sub/', ['/sub/about'], 'https://example.test', 25));
    }

    public function testScanPayload(): void
    {
        $this->assertSame(['page' => '/a', 'reports' => []], Frontenderrors::scanPayload('{"page":"/a","reports":[]}'));
        $this->assertNull(Frontenderrors::scanPayload(''));
        $this->assertNull(Frontenderrors::scanPayload('"text"'));
        $this->assertNull(Frontenderrors::scanPayload('{bad json'));
        $this->assertNull(Frontenderrors::scanPayload('{"page":"' . str_repeat('a', Frontenderrors::MAX_BODY_BYTES) . '"}'));
    }

    public function testDetailsRowShowsEveryField(): void
    {
        $html = Frontenderrors::renderDetails(self::LAYOUT, [$this->error()], 7);

        $this->assertStringContainsString('data-hc-fe-row="7"', $html);
        $this->assertStringContainsString('/media/com_foo/js/a.js:3:4', $html);
        $this->assertStringContainsString('com_foo', $html);
        $this->assertStringContainsString('DATE(2026-10-10 08:00:00)', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_FRONTENDERRORS_ORIGIN_VISITORS', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertMatchesRegularExpression('/data-hc-fe-state="1" data-hc-fe-visible="0 3">/', $html);
        $this->assertMatchesRegularExpression('/data-hc-fe-state="0" data-hc-fe-visible="1 2" hidden>/', $html);
    }

    public function testDetailsSourceAndOriginLabels(): void
    {
        $html = Frontenderrors::renderDetails(self::LAYOUT, [
            $this->error(['id' => 1, 'source_extension' => 'core', 'origins' => ['scan', 'visitor']]),
            $this->error(['id' => 2, 'source_extension' => 'external:cdn.example.com', 'origins' => ['scan']]),
            $this->error(['id' => 3, 'source_extension' => null, 'file' => '', 'stack' => '', 'state' => 1]),
        ], 7);

        $this->assertStringContainsString('PLG_HEALTHCHECK_FRONTENDERRORS_SOURCE_CORE', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_FRONTENDERRORS_SOURCE_EXTERNAL', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_FRONTENDERRORS_SOURCE_UNKNOWN', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_FRONTENDERRORS_ORIGIN_BOTH', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_FRONTENDERRORS_ORIGIN_SCAN', $html);
        $this->assertSame(2, substr_count($html, '<details'));
        $this->assertMatchesRegularExpression('/data-hc-fe-state="0" data-hc-fe-visible="1 2">/', $html);
    }

    public function testDetailsEscapesEveryValue(): void
    {
        $html = Frontenderrors::renderDetails(self::LAYOUT, [$this->error([
            'type'             => self::EVIL,
            'message'          => self::EVIL,
            'file'             => self::EVIL,
            'stack'            => self::EVIL,
            'source_extension' => self::EVIL,
            'first_seen'       => self::EVIL,
        ])], 7);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('"\'', $html);
        $this->assertSame(6, substr_count($html, '&lt;img'));
    }

    public function testDetailsEmptyState(): void
    {
        $html = Frontenderrors::renderDetails(self::LAYOUT, [], 7);

        $this->assertStringContainsString('PLG_HEALTHCHECK_FRONTENDERRORS_DETAILS_NONE', $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
