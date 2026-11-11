<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Frontenderrors\Extension;

use Joomla\Plugin\Healthcheck\Frontenderrors\Extension\Frontenderrors;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Frontend Errors Health Check table helpers.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors Health Check table')]
class FrontenderrorsTest extends UnitTestCase
{
    private const LABELS = [
        'page'      => 'Page', 'errors' => 'Errors', 'seen' => 'Seen', 'top' => 'Top error',
        'last_seen' => 'Last seen', 'details' => 'Details', 'new' => 'New', 'regressed' => 'Regressed',
    ];

    private function page(array $over = []): array
    {
        return $over + [
            'page'      => '/about', 'errors' => 4, 'hits' => 12, 'top_message' => 'TypeError: x is undefined',
            'last_seen' => '2026-10-16 10:00:00', 'has_new' => false, 'has_regressed' => false,
        ];
    }

    public function testTableShowsTopTenPages(): void
    {
        $this->assertSame(10, Frontenderrors::TABLE_LIMIT);
    }

    public function testRowsUseBadgeFromThreshold(): void
    {
        $rows = Frontenderrors::tableRows([$this->page(), $this->page(['errors' => 2])], 3);

        $this->assertSame('danger', $rows[0]['badge']);
        $this->assertSame('warning', $rows[1]['badge']);
        $this->assertSame('/about', $rows[0]['page']);
        $this->assertSame(12, $rows[0]['hits']);
        $this->assertSame('2026-10-16 10:00:00', $rows[0]['last_seen']);
    }

    public function testHitsLabelIsPluralised(): void
    {
        $label = static fn (int $n): string => $n === 1 ? '1 time' : $n . ' times';
        $rows  = Frontenderrors::tableRows([$this->page(['hits' => 1]), $this->page(['hits' => 12])], 3, $label);
        $cols  = array_column(Frontenderrors::columns(self::LABELS, 'https://example.test'), null, 'key');

        $this->assertSame('1 time', $cols['hits']['renderer'](1, $rows[0]));
        $this->assertSame('12 times', $cols['hits']['renderer'](12, $rows[1]));
    }

    public function testTopMessageIsShortened(): void
    {
        $rows = Frontenderrors::tableRows([$this->page(['top_message' => str_repeat('a', 200)])], 3);

        $this->assertSame(str_repeat('a', 79) . '…', $rows[0]['top']);
        $this->assertSame('short', Frontenderrors::tableRows([$this->page(['top_message' => 'short'])], 3)[0]['top']);
    }

    public function testRegressedWinsOverNew(): void
    {
        $rows = Frontenderrors::tableRows([
            $this->page(['has_new' => true, 'has_regressed' => true]),
            $this->page(['has_new' => true]),
            $this->page(),
        ], 3);

        $this->assertSame('regressed', $rows[0]['marker']);
        $this->assertSame('new', $rows[1]['marker']);
        $this->assertSame('', $rows[2]['marker']);
    }

    public function testHtmlInMessageIsEscaped(): void
    {
        $evil    = '<img src=x onerror=alert(1)>';
        $rows    = Frontenderrors::tableRows([$this->page(['page' => $evil, 'top_message' => $evil, 'has_new' => true])], 3);
        $columns = array_column(Frontenderrors::columns(self::LABELS, 'https://example.test'), null, 'key');

        foreach (['page', 'top', 'details'] as $key) {
            $html = $columns[$key]['renderer']($rows[0][$key] ?? '', $rows[0]);

            $this->assertStringNotContainsString('<img', $html, $key);
        }

        $this->assertStringContainsString('&lt;img', $columns['top']['renderer']($rows[0]['top'], $rows[0]));
        $this->assertStringContainsString('&lt;img', $columns['page']['renderer']($rows[0]['page'], $rows[0]));
    }

    public function testPageLinkAndDetailsButton(): void
    {
        $rows    = Frontenderrors::tableRows([$this->page()], 3);
        $columns = array_column(Frontenderrors::columns(self::LABELS, 'https://example.test'), null, 'key');

        $link = $columns['page']['renderer']('/about', $rows[0]);
        $this->assertStringContainsString('href="https://example.test/about"', $link);
        $this->assertStringContainsString('rel="noopener"', $link);

        $button = $columns['details']['renderer']('', $rows[0]);
        $this->assertStringContainsString('plg-hc-fe-details', $button);
        $this->assertStringContainsString('data-hc-fe-page="/about"', $button);
    }
}
