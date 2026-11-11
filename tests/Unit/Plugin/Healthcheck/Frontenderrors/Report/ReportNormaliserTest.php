<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Frontenderrors\Report;

use Joomla\Plugin\Healthcheck\Frontenderrors\Report\ReportNormaliser;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the report normaliser.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors report normaliser')]
class ReportNormaliserTest extends UnitTestCase
{
    private const ROOT = 'https://example.org/joomla/';

    private static function report(array $over = []): array
    {
        return array_merge([
            'type'    => 'error',
            'message' => 'boom',
            'file'    => 'https://example.org/joomla/media/com_foo/js/a.js?v=3#x',
            'line'    => 10,
            'col'     => 5,
            'stack'   => '',
            'count'   => 1,
        ], $over);
    }

    private static function norm(array $reports, string $page = '/joomla/about'): array
    {
        return ReportNormaliser::normalise(['page' => $page, 'reports' => $reports], self::ROOT);
    }

    public function testLineAndColumnAreClamped(): void
    {
        $out = self::norm([self::report(['line' => 99999999999, 'col' => '99999999999'])]);

        $this->assertSame(4294967295, $out[0]['line']);
        $this->assertSame(4294967295, $out[0]['col']);

        $out = self::norm([self::report(['line' => -5, 'col' => -1])]);

        $this->assertSame(0, $out[0]['line']);
        $this->assertSame(0, $out[0]['col']);
    }

    public function testValidReport(): void
    {
        $out = self::norm([self::report()]);

        $this->assertCount(1, $out);
        $this->assertSame('/joomla/media/com_foo/js/a.js', $out[0]['file']);
        $this->assertSame(1, $out[0]['count']);
        $this->assertSame('error', $out[0]['type']);
        $this->assertSame('/joomla/about', $out[0]['page']);
        $this->assertSame(10, $out[0]['line']);
        $this->assertSame(5, $out[0]['col']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $out[0]['fingerprint']);
    }

    public function testExternalFileKeepsHost(): void
    {
        $out = self::norm([self::report(['file' => 'https://cdn.example.com/lib.js?x=1'])]);

        $this->assertSame('https://cdn.example.com/lib.js', $out[0]['file']);
    }

    public function testTruncation(): void
    {
        $out = self::norm([self::report([
            'message' => str_repeat('m', 600),
            'stack'   => str_repeat('s', 3000),
            'file'    => 'https://cdn.example.com/' . str_repeat('f', 300),
        ])]);

        $this->assertSame(500, mb_strlen($out[0]['message']));
        $this->assertSame(2000, mb_strlen($out[0]['stack']));
        $this->assertSame(255, mb_strlen($out[0]['file']));
    }

    public function testPageQueryAndHashDropped(): void
    {
        $out = self::norm([self::report()], '/joomla/search?q=x#top');

        $this->assertSame('/joomla/search', $out[0]['page']);
    }

    public function testRejectsAbsolutePage(): void
    {
        foreach (['https://evil.example/', '//evil.example/', 'about'] as $page) {
            $this->assertSame([], self::norm([self::report()], $page), $page);
        }
    }

    public function testNonArrayReports(): void
    {
        $this->assertSame([], ReportNormaliser::normalise(['page' => '/a', 'reports' => 'x'], self::ROOT));
        $this->assertSame([], ReportNormaliser::normalise(null, self::ROOT));
        $this->assertCount(1, self::norm(['nope', self::report()]));
    }

    public function testUnknownTypeDropped(): void
    {
        $this->assertSame([], self::norm([self::report(['type' => 'warn'])]));

        $types = ['error', 'rejection', 'resource', 'csp', 'console'];
        $out   = self::norm(array_map(static fn (string $t): array => self::report(['type' => $t]), $types));

        $this->assertSame($types, array_column($out, 'type'));
    }

    public function testReportCap(): void
    {
        foreach ([10 => 10, 11 => 0, 12 => 0] as $in => $expected) {
            $reports = [];

            for ($i = 0; $i < $in; $i++) {
                $reports[] = self::report(['line' => $i]);
            }

            $this->assertCount($expected, self::norm($reports), (string) $in);
        }
    }

    public function testCountClamp(): void
    {
        $counts = [0 => 1, 999 => 50, '7' => 7];

        foreach ($counts as $in => $expected) {
            $this->assertSame($expected, self::norm([self::report(['count' => $in])])[0]['count']);
        }

        $r = self::report();
        unset($r['count']);
        $this->assertSame(1, self::norm([$r])[0]['count']);
    }

    public function testFileIsScrubbed(): void
    {
        $out = self::norm([self::report(['file' => 'https://example.test/joomla/u/jane@example.com/app.js'])]);

        $this->assertStringContainsString('[redacted]', $out[0]['file']);
        $this->assertStringNotContainsString('jane@example.com', $out[0]['file']);
    }

    public function testScrub(): void
    {
        $this->assertSame('Failed for [redacted]', ReportNormaliser::scrub('Failed for jane@example.com'));
        $this->assertSame('[redacted]', ReportNormaliser::scrub(str_repeat('a1b2c3d4', 4)));
        $this->assertSame('order [redacted]', ReportNormaliser::scrub('order 1234567'));
        $this->assertSame('line 12345', ReportNormaliser::scrub('line 12345'));
        $this->assertSame('internationalization', ReportNormaliser::scrub('internationalization'));

        $out = self::norm([self::report()], '/joomla/profile/jane@example.com');
        $this->assertSame('/joomla/profile/[redacted]', $out[0]['page']);
    }

    public function testScrubKeepsOrdinaryPages(): void
    {
        $pages = [
            '/joomla/en/blog/2024-news',
            '/joomla/index.php/component/content/article/5-faq',
            '/joomla/en/2024-annual-report-final-version',
        ];

        foreach ($pages as $page) {
            $this->assertSame($page, self::norm([self::report()], $page)[0]['page'], $page);
        }
    }

    public function testScrubRedactsUuid(): void
    {
        $this->assertSame(
            'user [redacted] failed',
            ReportNormaliser::scrub('user 123e4567-e89b-12d3-a456-426614174000 failed')
        );
    }

    public function testFingerprintStable(): void
    {
        $a = self::norm([self::report(['file' => 'https://example.org/joomla/a.js?v=1'])]);
        $b = self::norm([self::report(['file' => 'https://example.org/joomla/a.js?v=2'])]);
        $c = self::norm([self::report(['file' => 'https://example.org/joomla/a.js?v=2', 'line' => 11])]);

        $this->assertSame($a[0]['fingerprint'], $b[0]['fingerprint']);
        $this->assertNotSame($a[0]['fingerprint'], $c[0]['fingerprint']);
    }
}
