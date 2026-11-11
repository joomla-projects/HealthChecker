<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Securityheaders
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Securityheaders\Headers;

use Joomla\Plugin\Healthcheck\Securityheaders\Headers\HeaderScore;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the security headers score
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The security headers score')]
class HeaderScoreTest extends UnitTestCase
{
    /**
     * A complete, well-configured header set.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function complete(): array
    {
        return [
            'strict-transport-security'  => 'max-age=31536000; includeSubDomains',
            'content-security-policy'    => "default-src 'self'",
            'x-frame-options'            => 'SAMEORIGIN',
            'x-content-type-options'     => 'nosniff',
            'referrer-policy'            => 'strict-origin-when-cross-origin',
            'permissions-policy'         => 'camera=()',
            'cross-origin-opener-policy' => 'same-origin',
            'server'                     => 'Caddy',
        ];
    }

    /**
     * Find one item by key.
     *
     * @param   array   $result  evaluate() result
     * @param   string  $key     Item key
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function item(array $result, string $key): array
    {
        foreach ($result['items'] as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        throw new \LogicException('No item ' . $key);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('gives a complete header set 100 points and success')]
    public function testComplete(): void
    {
        $result = HeaderScore::evaluate(self::complete(), true);

        $this->assertSame(100, $result['score']);
        $this->assertSame('success', $result['status']);
        $this->assertSame(['hsts', 'csp', 'frame', 'nosniff', 'referrer', 'permissions', 'coop', 'leak'], array_column($result['items'], 'key'));
        $this->assertSame([null], array_values(array_unique(array_column($result['items'], 'fix'))));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('gives an empty header set 0 points and error')]
    public function testEmpty(): void
    {
        $result = HeaderScore::evaluate([], true);

        $this->assertSame(0, $result['score']);
        $this->assertSame('error', $result['status']);
        $this->assertSame('PLG_HEALTHCHECK_SECURITYHEADERS_FIX_CSP', self::item($result, 'csp')['fix']);
        $this->assertSame('success', self::item($result, 'leak')['status']);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function hstsProvider(): array
    {
        return [
            'one year'         => ['max-age=31536000', true, 25, 'success'],
            'exactly 180 days' => ['max-age=15552000', true, 25, 'success'],
            'one second short' => ['max-age=15551999', true, 10, 'warning'],
            'quoted'           => ['max-age="31536000"', true, 25, 'success'],
            'directives first' => ['includeSubDomains; preload; max-age=63072000', true, 25, 'success'],
            'upper case'       => ['MAX-AGE=31536000', true, 25, 'success'],
            'zero'             => ['max-age=0', true, 0, 'error'],
            'unparsable'       => ['max-age=forever', true, 0, 'error'],
            'missing'          => ['', true, 0, 'error'],
            'plain http'       => ['max-age=31536000', false, 0, 'error'],
        ];
    }

    /**
     * @param   string   $value   HSTS header
     * @param   boolean  $https   Final URL is https
     * @param   integer  $points  Expected points
     * @param   string   $status  Expected status
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('hstsProvider')]
    #[TestDox('scores Strict-Transport-Security by max-age')]
    public function testHsts(string $value, bool $https, int $points, string $status): void
    {
        $headers = $value === '' ? [] : ['strict-transport-security' => $value];
        $item    = self::item(HeaderScore::evaluate($headers, $https), 'hsts');

        $this->assertSame([$points, 25, $status], [$item['points'], $item['max'], $item['status']]);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('points plain-http sites to HTTPS instead of the HSTS header')]
    public function testHstsHttpFix(): void
    {
        $this->assertSame('PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS_HTTP', self::item(HeaderScore::evaluate([], false), 'hsts')['fix']);
        $this->assertSame('PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS', self::item(HeaderScore::evaluate([], true), 'hsts')['fix']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('scores an enforced CSP above a report-only one')]
    public function testCsp(): void
    {
        $this->assertSame(25, self::item(HeaderScore::evaluate(['content-security-policy' => "default-src 'self'"], true), 'csp')['points']);

        $reportOnly = self::item(HeaderScore::evaluate(['content-security-policy-report-only' => "default-src 'self'"], true), 'csp');

        $this->assertSame([10, 'warning', "default-src 'self'"], [$reportOnly['points'], $reportOnly['status'], $reportOnly['value']]);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function frameProvider(): array
    {
        return [
            'sameorigin'             => [['x-frame-options' => 'SAMEORIGIN'], 15],
            'deny lower case'        => [['x-frame-options' => 'deny'], 15],
            'sent twice'             => [['x-frame-options' => 'SAMEORIGIN, SAMEORIGIN'], 15],
            'conflicting duplicates' => [['x-frame-options' => 'DENY, SAMEORIGIN'], 0],
            'allow-from'             => [['x-frame-options' => 'ALLOW-FROM https://example.org'], 0],
            'csp frame-ancestors'    => [['content-security-policy' => "default-src 'self'; frame-ancestors 'self'"], 15],
            'fa none'                => [['content-security-policy' => "frame-ancestors 'none'"], 15],
            'fa host'                => [['content-security-policy' => 'frame-ancestors https://example.org'], 15],
            'fa two headers'         => [['content-security-policy' => "default-src 'self', frame-ancestors 'self'"], 15],
            'fa wildcard'            => [['content-security-policy' => 'frame-ancestors *'], 0],
            'fa bare scheme'         => [['content-security-policy' => 'frame-ancestors https:'], 0],
            'fa bare schemes'        => [['content-security-policy' => 'frame-ancestors http: https:'], 0],
            'fa wildcard + scheme'   => [['content-security-policy' => 'frame-ancestors * https:'], 0],
            'fa empty'               => [['content-security-policy' => 'frame-ancestors; default-src \'self\''], 0],
            'fa lookalike'           => [['content-security-policy' => "frame-ancestors-x 'self'"], 0],
            'report-only only'       => [['content-security-policy-report-only' => "frame-ancestors 'self'"], 0],
            'missing'                => [[], 0],
        ];
    }

    /**
     * @param   array    $headers  Headers
     * @param   integer  $points   Expected points
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('frameProvider')]
    #[TestDox('scores frame protection from X-Frame-Options or CSP frame-ancestors')]
    public function testFrame(array $headers, int $points): void
    {
        $this->assertSame($points, self::item(HeaderScore::evaluate($headers, true), 'frame')['points']);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function nosniffProvider(): array
    {
        return [
            'nosniff'    => ['nosniff', 15],
            'upper case' => ['NoSniff', 15],
            'sent twice' => ['nosniff, nosniff', 15],
            'other'      => ['sniff', 0],
            'missing'    => ['', 0],
        ];
    }

    /**
     * @param   string   $value   Header value
     * @param   integer  $points  Expected points
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('nosniffProvider')]
    #[TestDox('scores X-Content-Type-Options')]
    public function testNosniff(string $value, int $points): void
    {
        $headers = $value === '' ? [] : ['x-content-type-options' => $value];

        $this->assertSame($points, self::item(HeaderScore::evaluate($headers, true), 'nosniff')['points']);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function referrerProvider(): array
    {
        return [
            'strict-origin-when-cross-origin' => ['strict-origin-when-cross-origin', 10],
            'no-referrer'                     => ['no-referrer', 10],
            'same-origin upper case'          => ['Same-Origin', 10],
            'unsafe-url'                      => ['unsafe-url', 0],
            'no-referrer-when-downgrade'      => ['no-referrer-when-downgrade', 0],
            'fallback list, safe last'        => ['no-referrer, strict-origin-when-cross-origin', 10],
            'fallback list, unsafe last'      => ['strict-origin, unsafe-url', 0],
            'unknown last token ignored'      => ['strict-origin, some-future-value', 10],
            'only unknown'                    => ['some-future-value', 0],
        ];
    }

    /**
     * @param   string   $value   Header value
     * @param   integer  $points  Expected points
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('referrerProvider')]
    #[TestDox('scores the effective Referrer-Policy')]
    public function testReferrer(string $value, int $points): void
    {
        $this->assertSame($points, self::item(HeaderScore::evaluate(['referrer-policy' => $value], true), 'referrer')['points']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('scores Permissions-Policy and COOP by presence')]
    public function testPresence(): void
    {
        $result = HeaderScore::evaluate(['permissions-policy' => 'geolocation=()', 'cross-origin-opener-policy' => 'same-origin'], true);

        $this->assertSame([5, 5], [self::item($result, 'permissions')['points'], self::item($result, 'coop')['points']]);

        $blank = HeaderScore::evaluate(['permissions-policy' => '  ', 'cross-origin-opener-policy' => ''], true);

        $this->assertSame([0, 0], [self::item($blank, 'permissions')['points'], self::item($blank, 'coop')['points']]);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function leakProvider(): array
    {
        return [
            'caddy'              => [['server' => 'Caddy'], 0],
            'cloudflare'         => [['server' => 'cloudflare'], 0],
            'php without number' => [['x-powered-by' => 'PHP'], 0],
            'apache version'     => [['server' => 'Apache/2.4.62 (Debian)'], -5],
            'php version'        => [['x-powered-by' => 'PHP/8.3.1'], -5],
            'both'               => [['server' => 'nginx/1.27.0', 'x-powered-by' => 'PHP/8.3.1'], -5],
        ];
    }

    /**
     * @param   array    $headers  Headers
     * @param   integer  $points   Expected points
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('leakProvider')]
    #[TestDox('deducts 5 points when Server or X-Powered-By leaks a version')]
    public function testLeak(array $headers, int $points): void
    {
        $item = self::item(HeaderScore::evaluate($headers, true), 'leak');

        $this->assertSame([$points, 0, $points < 0 ? 'error' : 'success'], [$item['points'], $item['max'], $item['status']]);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('never goes below 0 and maps the score to the gauge status')]
    public function testClampAndStatus(): void
    {
        $this->assertSame(0, HeaderScore::evaluate(['server' => 'Apache/2.4.62'], true)['score']);

        $warning = ['content-security-policy' => "default-src 'self'", 'x-frame-options' => 'DENY', 'x-content-type-options' => 'nosniff'];

        $this->assertSame([55, 'warning'], array_values(array_intersect_key(HeaderScore::evaluate($warning, true), ['score' => 0, 'status' => 0])));

        $error = ['x-frame-options' => 'DENY', 'x-content-type-options' => 'nosniff', 'referrer-policy' => 'no-referrer', 'permissions-policy' => 'a=()'];

        $this->assertSame([45, 'error'], array_values(array_intersect_key(HeaderScore::evaluate($error, true), ['score' => 0, 'status' => 0])));

        $success = self::complete();
        unset($success['permissions-policy'], $success['cross-origin-opener-policy'], $success['referrer-policy']);

        $this->assertSame([80, 'success'], array_values(array_intersect_key(HeaderScore::evaluate($success, true), ['score' => 0, 'status' => 0])));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function hstsRepeatProvider(): array
    {
        return [
            'identical twice' => ['max-age=31536000, max-age=31536000', 25],
            'first is full'   => ['max-age=31536000, max-age=63072000; includeSubDomains; preload', 25],
            'first wins'      => ['max-age=100, max-age=31536000', 10],
        ];
    }

    /**
     * @param   string   $value   HSTS header value
     * @param   integer  $points  Expected points
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('hstsRepeatProvider')]
    #[TestDox('scores only the first of repeated Strict-Transport-Security headers')]
    public function testHstsRepeated(string $value, int $points): void
    {
        $result = HeaderScore::evaluate(['strict-transport-security' => $value], true);

        $this->assertSame($points, self::item($result, 'hsts')['points']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('switches status exactly at the 50 and 80 boundaries')]
    public function testStatusBoundaries(): void
    {
        $csp      = ['content-security-policy' => "default-src 'self'"];
        $frame    = ['x-frame-options' => 'DENY'];
        $referrer = ['referrer-policy' => 'no-referrer'];
        $nosniff  = ['x-content-type-options' => 'nosniff'];
        $hsts     = ['strict-transport-security' => 'max-age=31536000'];
        $leak     = ['server' => 'Apache/2.4.62'];

        $cases = [
            // 25 + 15 + 5 (permissions) = 45; scores move in steps of 5, so 49 and 79 cannot occur.
            [$csp + $frame + ['permissions-policy' => 'a=()'], 45, 'error'],
            [$csp + $frame + $referrer, 50, 'warning'],
            [$hsts + $csp + $frame + $nosniff + $leak, 75, 'warning'],
            [$hsts + $csp + $frame + $nosniff, 80, 'success'],
        ];

        foreach ($cases as [$headers, $score, $status]) {
            $result = HeaderScore::evaluate($headers, true);

            $this->assertSame([$score, $status], [$result['score'], $result['status']]);
        }
    }
}
