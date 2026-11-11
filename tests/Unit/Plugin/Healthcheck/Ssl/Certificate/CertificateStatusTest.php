<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Ssl
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Ssl\Certificate;

use Joomla\Plugin\Healthcheck\Ssl\Certificate\CertificateStatus;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the SSL certificate status rules
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The SSL certificate status rules')]
class CertificateStatusTest extends UnitTestCase
{
    private const NOW = 1800000000;

    private const DAY = 86400;

    /**
     * A successful probe result expiring at the given time.
     *
     * @param   int      $validTo    Expiry timestamp
     * @param   boolean  $trusted    Whether verification passed
     * @param   ?int     $validFrom  Start timestamp, default 60 days before NOW
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function probe(int $validTo, bool $trusted = true, ?int $validFrom = null): array
    {
        return [
            'ok'        => true,
            'trusted'   => $trusted,
            'reason'    => $trusted ? '' : 'certificate verify failed',
            'validFrom' => $validFrom ?? self::NOW - 60 * self::DAY,
            'validTo'   => $validTo,
            'issuer'    => 'Test CA',
            'subject'   => 'example.test',
            'checkedAt' => self::NOW,
        ];
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function expiryProvider(): array
    {
        $now = self::NOW;
        $day = self::DAY;

        return [
            '90 days'             => [$now + 90 * $day, 'success', 'PLG_HEALTHCHECK_SSL_STATUS_VALID_N', 90],
            '15 days'             => [$now + 15 * $day, 'success', 'PLG_HEALTHCHECK_SSL_STATUS_VALID_N', 15],
            '14 days'             => [$now + 14 * $day, 'warning', 'PLG_HEALTHCHECK_SSL_STATUS_RENEW_SOON_N', 14],
            '14 days and an hour' => [$now + 14 * $day + 3600, 'warning', 'PLG_HEALTHCHECK_SSL_STATUS_RENEW_SOON_N', 14],
            '8 days'              => [$now + 8 * $day, 'warning', 'PLG_HEALTHCHECK_SSL_STATUS_RENEW_SOON_N', 8],
            '7 days'              => [$now + 7 * $day, 'error', 'PLG_HEALTHCHECK_SSL_STATUS_EXPIRES_N', 7],
            'later today'         => [$now + 3600, 'error', 'PLG_HEALTHCHECK_SSL_STATUS_EXPIRES_N', 0],
            'this very second'    => [$now, 'error', 'PLG_HEALTHCHECK_SSL_STATUS_EXPIRES_N', 0],
        ];
    }

    /**
     * @param   int     $validTo  Expiry timestamp
     * @param   string  $status   Expected status
     * @param   string  $key      Expected message key
     * @param   int     $days     Expected days left
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('expiryProvider')]
    #[TestDox('maps days to expiry to a stoplight at the default 14/7 thresholds')]
    public function testDefaultThresholds(int $validTo, string $status, string $key, int $days): void
    {
        $result = CertificateStatus::evaluate($this->probe($validTo), self::NOW, 14, 7);

        $this->assertSame(['status' => $status, 'key' => $key, 'days' => $days, 'date' => $validTo, 'plural' => true], $result);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('reports an expired certificate as an error with its expiry date')]
    public function testExpired(): void
    {
        $validTo = self::NOW - 1;

        $this->assertSame(
            ['status' => 'error', 'key' => 'PLG_HEALTHCHECK_SSL_STATUS_EXPIRED', 'days' => null, 'date' => $validTo, 'plural' => false],
            CertificateStatus::evaluate($this->probe($validTo), self::NOW, 14, 7)
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('says expired, not untrusted, for an expired certificate that failed verification')]
    public function testExpiredBeatsUntrusted(): void
    {
        $result = CertificateStatus::evaluate($this->probe(self::NOW - 5 * self::DAY, false), self::NOW, 14, 7);

        $this->assertSame('PLG_HEALTHCHECK_SSL_STATUS_EXPIRED', $result['key']);
        $this->assertSame('error', $result['status']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('reports a certificate that is not valid yet as an error with its start date')]
    public function testNotYetValid(): void
    {
        $validFrom = self::NOW + self::DAY;

        $this->assertSame(
            ['status' => 'error', 'key' => 'PLG_HEALTHCHECK_SSL_STATUS_NOT_YET_VALID', 'days' => null, 'date' => $validFrom, 'plural' => false],
            CertificateStatus::evaluate($this->probe(self::NOW + 90 * self::DAY, true, $validFrom), self::NOW, 14, 7)
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('reports an untrusted certificate as an error even with plenty of days left')]
    public function testUntrusted(): void
    {
        $validTo = self::NOW + 60 * self::DAY;

        $this->assertSame(
            ['status' => 'error', 'key' => 'PLG_HEALTHCHECK_SSL_STATUS_UNTRUSTED', 'days' => 60, 'date' => $validTo, 'plural' => false],
            CertificateStatus::evaluate($this->probe($validTo, false), self::NOW, 14, 7)
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('warns when the site is not served over HTTPS')]
    public function testNoHttps(): void
    {
        $this->assertSame(
            ['status' => 'warning', 'key' => 'PLG_HEALTHCHECK_SSL_STATUS_NO_HTTPS', 'days' => null, 'date' => null, 'plural' => false],
            CertificateStatus::evaluate(null, self::NOW, 14, 7)
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('stays neutral when the certificate could not be read')]
    public function testUnchecked(): void
    {
        $probe = ['ok' => false, 'trusted' => false, 'reason' => 'Connection refused', 'validFrom' => null, 'validTo' => null, 'issuer' => '', 'subject' => '', 'checkedAt' => self::NOW];

        $this->assertSame(
            ['status' => 'none', 'key' => 'PLG_HEALTHCHECK_SSL_STATUS_UNCHECKED', 'days' => null, 'date' => null, 'plural' => false],
            CertificateStatus::evaluate($probe, self::NOW, 14, 7)
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('applies custom thresholds')]
    public function testCustomThresholds(): void
    {
        $this->assertSame('warning', CertificateStatus::evaluate($this->probe(self::NOW + 20 * self::DAY), self::NOW, 30, 14)['status']);
        $this->assertSame('error', CertificateStatus::evaluate($this->probe(self::NOW + 14 * self::DAY), self::NOW, 30, 14)['status']);
        $this->assertSame('success', CertificateStatus::evaluate($this->probe(self::NOW + 31 * self::DAY), self::NOW, 30, 14)['status']);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function thresholdsProvider(): array
    {
        return [
            'defaults from null'      => [null, null, [14, 7]],
            'strings from the form'   => ['30', '14', [30, 14]],
            'garbage'                 => ['abc', 'x', [14, 7]],
            'negative'                => ['-3', '-1', [14, 7]],
            'zero warning is invalid' => ['0', '0', [14, 0]],
            'error above warning'     => ['5', '10', [10, 10]],
            'error equals warning'    => [7, 7, [7, 7]],
        ];
    }

    /**
     * @param   mixed  $warning   Raw warning_days param
     * @param   mixed  $error     Raw error_days param
     * @param   array  $expected  [warningDays, errorDays]
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('thresholdsProvider')]
    #[TestDox('sanitises threshold params and never inverts the windows')]
    public function testThresholds(mixed $warning, mixed $error, array $expected): void
    {
        $this->assertSame($expected, CertificateStatus::thresholds($warning, $error));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('turns an inverted configuration into an empty warning window')]
    public function testInvertedThresholdsEvaluate(): void
    {
        [$warning, $error] = CertificateStatus::thresholds('5', '10');

        $this->assertSame('error', CertificateStatus::evaluate($this->probe(self::NOW + 8 * self::DAY), self::NOW, $warning, $error)['status']);
        $this->assertSame('success', CertificateStatus::evaluate($this->probe(self::NOW + 11 * self::DAY), self::NOW, $warning, $error)['status']);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function timeoutProvider(): array
    {
        return [
            'null'    => [null, 3],
            'string'  => ['5', 5],
            'zero'    => ['0', 1],
            'too big' => ['99', 10],
            'garbage' => ['x', 3],
        ];
    }

    /**
     * @param   mixed  $raw       Raw timeout param
     * @param   int    $expected  Seconds
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('timeoutProvider')]
    #[TestDox('clamps the timeout to 1 to 10 seconds')]
    public function testTimeout(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, CertificateStatus::timeout($raw));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function usableProvider(): array
    {
        $now     = self::NOW;
        $success = ['ok' => true, 'trusted' => true, 'reason' => '', 'validFrom' => $now - 86400, 'validTo' => $now + 86400 * 60, 'issuer' => 'CA', 'subject' => 'example.org', 'checkedAt' => $now - 3600];
        $failure = ['ok' => false, 'trusted' => false, 'reason' => 'refused', 'validFrom' => null, 'validTo' => null, 'issuer' => '', 'subject' => '', 'checkedAt' => $now - 600];

        return [
            'fresh success'                     => [$success, true],
            'success at 23 hours'               => [['checkedAt' => $now - 82800] + $success, true],
            'success at 24 hours'               => [['checkedAt' => $now - 86400] + $success, false],
            'fresh failure'                     => [$failure, true],
            'failure at one hour'               => [['checkedAt' => $now - 3600] + $failure, false],
            'checked in the future'             => [['checkedAt' => $now + 60] + $success, false],
            'cache miss'                        => [false, false],
            'not an array'                      => ['x', false],
            'missing key'                       => [array_diff_key($success, ['issuer' => true]), false],
            'missing subject (old cache shape)' => [array_diff_key($success, ['subject' => true]), false],
            'string timestamp'                  => [['validTo' => '1800000000'] + $success, false],
            'ok without dates'                  => [['validTo' => null] + $success, false],
        ];
    }

    /**
     * @param   mixed    $cached    What the cache returned
     * @param   boolean  $expected  Whether it may be used
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('usableProvider')]
    #[TestDox('reuses well-formed cached results for 24 hours, failures for 1 hour')]
    public function testIsUsable(mixed $cached, bool $expected): void
    {
        $this->assertSame($expected, CertificateStatus::isUsable($cached, self::NOW));
    }
}
