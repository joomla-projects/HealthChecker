<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Siteinfo
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Siteinfo\Support;

use Joomla\Plugin\Healthcheck\Siteinfo\Support\SupportData;
use Joomla\Plugin\Healthcheck\Siteinfo\Support\SystemReport;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Site Information system report
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The Site Information system report')]
class SystemReportTest extends UnitTestCase
{
    /**
     * @return  SystemReport
     *
     * @since   __DEPLOY_VERSION__
     */
    private function report(): SystemReport
    {
        return new SystemReport(new SupportData(), new \DateTimeImmutable('2026-10-15', new \DateTimeZone('UTC')));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('reports an end-of-life database as critical with its end date')]
    public function testVersionRowEol(): void
    {
        $row = $this->report()->versionRow('mariadb', '12.2.2-MariaDB');

        $this->assertSame('critical', $row['status']);
        $this->assertSame('2026-05-28', $row['ends']);
        $this->assertSame([['PLG_HEALTHCHECK_SITEINFO_STATUS_EOL_PAST', ['2026-05-28']]], $row['messages']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('takes the worse of the EOL and requirement statuses and shows both messages')]
    public function testVersionRowCombined(): void
    {
        $row = $this->report()->versionRow('mariadb', '10.5.27');

        $this->assertSame('critical', $row['status']);
        $this->assertSame(
            [
                ['PLG_HEALTHCHECK_SITEINFO_STATUS_EOL_PAST', ['2025-06-24']],
                ['PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_BELOW_SUPPORTED', ['10.6']],
            ],
            $row['messages']
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('checks a web server only against Joomla requirements')]
    public function testVersionRowWebServer(): void
    {
        $row = $this->report()->versionRow('nginx', '1.24.0');

        $this->assertSame('warning', $row['status']);
        $this->assertSame('', $row['ends']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('shows Unknown when a version could not be detected')]
    public function testVersionRowUnknown(): void
    {
        $row = $this->report()->versionRow('mysql', '');

        $this->assertSame('none', $row['status']);
        $this->assertSame([['PLG_HEALTHCHECK_SITEINFO_STATUS_UNKNOWN', []]], $row['messages']);
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function serverProvider(): array
    {
        return [
            'apache'         => ['Apache/2.4.62 (Unix) OpenSSL/3.0.13', 'apache', 'Apache', '2.4.62'],
            'nginx'          => ['nginx/1.27.3', 'nginx', 'nginx', '1.27.3'],
            'iis'            => ['Microsoft-IIS/10.0', 'iis', 'Microsoft-IIS', '10.0'],
            'litespeed'      => ['LiteSpeed', null, 'LiteSpeed', ''],
            'hidden version' => ['Apache', 'apache', 'Apache', ''],
            'cloudflare'     => ['cloudflare', null, 'cloudflare', ''],
            'empty'          => ['', null, '', ''],
        ];
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('serverProvider')]
    #[TestDox('parses SERVER_SOFTWARE "$software"')]
    public function testParseServerSoftware(string $software, ?string $product, string $name, string $version): void
    {
        $this->assertSame(
            ['product' => $product, 'name' => $name, 'version' => $version],
            SystemReport::parseServerSoftware($software)
        );
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function bytesProvider(): array
    {
        return [['-1', -1], ['128M', 134217728], ['512m', 536870912], ['1G', 1073741824], ['2048K', 2097152], ['1000', 1000]];
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('bytesProvider')]
    #[TestDox('converts $value to $bytes bytes')]
    public function testToBytes(string $value, int $bytes): void
    {
        $this->assertSame($bytes, SystemReport::toBytes($value));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('warns about PHP settings below Joomla recommendations')]
    public function testSettingsRowsWarnings(): void
    {
        $rows = SystemReport::settingsRows(
            [
                'memory_limit'        => '128M',
                'max_execution_time'  => '20',
                'post_max_size'       => '8M',
                'upload_max_filesize' => '16M',
                'display_errors'      => 'stderr',
            ],
            false
        );

        $this->assertSame(
            ['warning', 'warning', 'warning', 'warning', 'warning'],
            array_column($rows, 'status')
        );
        $this->assertSame(
            ['memory_limit', 'max_execution_time', 'post_max_size', 'opcache.enable', 'display_errors'],
            array_column($rows, 'setting')
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('accepts unlimited and generous PHP settings')]
    public function testSettingsRowsHealthy(): void
    {
        $rows = SystemReport::settingsRows(
            [
                'memory_limit'        => '-1',
                'max_execution_time'  => '0',
                'post_max_size'       => '1G',
                'upload_max_filesize' => '512m',
                'display_errors'      => 'Off',
            ],
            true
        );

        $this->assertSame(['healthy', 'healthy', 'healthy', 'healthy', 'healthy'], array_column($rows, 'status'));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('treats post_max_size 0 as unlimited and display_errors stdout as on')]
    public function testSettingsRowsEdgeValues(): void
    {
        $rows = SystemReport::settingsRows(
            [
                'memory_limit'        => '256M',
                'max_execution_time'  => '30',
                'post_max_size'       => '0',
                'upload_max_filesize' => '64M',
                'display_errors'      => 'stdout',
            ],
            true
        );

        $this->assertSame(['healthy', 'healthy', 'healthy', 'healthy', 'warning'], array_column($rows, 'status'));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('uses a refreshed memory limit')]
    public function testSettingsRowsRefreshedMemory(): void
    {
        $rows = SystemReport::settingsRows(['memory_limit' => '384M'], true, '512M');

        $this->assertSame('warning', $rows[0]['status']);
        $this->assertSame(['PLG_HEALTHCHECK_SITEINFO_RECOMMENDED_MEMORY', ['512M']], $rows[0]['recommended']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('picks the worst status')]
    public function testWorst(): void
    {
        $this->assertSame('critical', SystemReport::worst(['healthy', 'critical', 'warning']));
        $this->assertSame('warning', SystemReport::worst(['none', 'warning']));
        $this->assertSame('none', SystemReport::worst([]));
    }
}
