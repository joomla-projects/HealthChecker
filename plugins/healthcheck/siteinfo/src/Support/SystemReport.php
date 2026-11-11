<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Siteinfo
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Siteinfo\Support;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns detected versions and PHP settings into report rows.
 *
 * @since  __DEPLOY_VERSION__
 */
final class SystemReport
{
    /**
     * Status order, worst first
     *
     * @since  __DEPLOY_VERSION__
     */
    private const SEVERITY = [
        SupportData::STATUS_CRITICAL,
        SupportData::STATUS_WARNING,
        SupportData::STATUS_HEALTHY,
        SupportData::STATUS_NONE,
    ];

    /**
     * Web server names in SERVER_SOFTWARE mapped to requirement products
     *
     * @since  __DEPLOY_VERSION__
     */
    private const WEB_SERVERS = ['apache' => 'apache', 'nginx' => 'nginx', 'microsoft-iis' => 'iis'];

    /**
     * Recommended PHP memory limit (Joomla manual, Technical Requirements)
     *
     * @since  __DEPLOY_VERSION__
     */
    public const MEMORY_LIMIT = '256M';

    /**
     * Constructor
     *
     * @param   SupportData         $data   The support data
     * @param   \DateTimeImmutable  $today  The current date
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly SupportData $data, private readonly \DateTimeImmutable $today)
    {
    }

    /**
     * Build the row for one product.
     *
     * @param   string  $product  The product name (php, mysql, mariadb, postgresql, joomla, apache, nginx, iis)
     * @param   string  $version  The detected version, empty if unknown
     *
     * @return  array  ['product', 'version', 'status', 'messages' => list of [language key, args], 'ends']
     *
     * @since   __DEPLOY_VERSION__
     */
    public function versionRow(string $product, string $version): array
    {
        $row = ['product' => $product, 'version' => $version, 'status' => SupportData::STATUS_NONE, 'messages' => [], 'ends' => ''];

        if (SupportData::normalize($version) === '') {
            $row['messages'][] = ['PLG_HEALTHCHECK_SITEINFO_STATUS_UNKNOWN', []];

            return $row;
        }

        $statuses = [];

        if ($this->data->hasEolData($product)) {
            $eol               = $this->data->getEolStatus($product, $version, $this->today);
            $statuses[]        = $eol['status'];
            $row['messages'][] = [$eol['key'], $eol['args']];
            $row['ends']       = $eol['eol'];
        }

        $requirement = $this->data->getRequirementStatus($product, $version);

        if ($requirement !== null) {
            $statuses[]        = $requirement['status'];
            $row['messages'][] = [$requirement['key'], $requirement['args']];
        }

        $row['status'] = self::worst($statuses);

        return $row;
    }

    /**
     * Split SERVER_SOFTWARE into a known product, its display name and version.
     *
     * @param   string  $software  The SERVER_SOFTWARE value
     *
     * @return  array  ['product' => ?string, 'name' => string, 'version' => string]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function parseServerSoftware(string $software): array
    {
        if (!preg_match('#^([A-Za-z][\w.-]*)(?:/(\d+(?:\.\d+)*))?#', trim($software), $matches)) {
            return ['product' => null, 'name' => '', 'version' => ''];
        }

        return [
            'product' => self::WEB_SERVERS[strtolower($matches[1])] ?? null,
            'name'    => $matches[1],
            'version' => $matches[2] ?? '',
        ];
    }

    /**
     * Check PHP settings against Joomla recommendations.
     *
     * @param   array    $ini             Values of memory_limit, max_execution_time, post_max_size, upload_max_filesize, display_errors
     * @param   boolean  $opcacheEnabled  Whether OPcache is enabled
     * @param   string   $memoryLimit     Recommended memory limit (built-in or refreshed from the manual)
     *
     * @return  array  List of ['setting', 'current', 'recommended' => [language key, args], 'status']
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function settingsRows(array $ini, bool $opcacheEnabled, string $memoryLimit = self::MEMORY_LIMIT): array
    {
        $memory    = self::toBytes((string) ($ini['memory_limit'] ?? ''));
        $execution = (int) ($ini['max_execution_time'] ?? 0);
        $post      = self::toBytes((string) ($ini['post_max_size'] ?? ''));
        $upload    = self::toBytes((string) ($ini['upload_max_filesize'] ?? ''));
        $errors    = \in_array(strtolower((string) ($ini['display_errors'] ?? '')), ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true);

        $rows = [
            ['memory_limit', (string) ($ini['memory_limit'] ?? ''), ['MEMORY', [$memoryLimit]], $memory !== -1 && $memory < self::toBytes($memoryLimit)],
            ['max_execution_time', (string) $execution, ['EXECUTION', []], $execution > 0 && $execution < 30],
            ['post_max_size', (string) ($ini['post_max_size'] ?? ''), ['POST', [(string) ($ini['upload_max_filesize'] ?? '')]], $post > 0 && $upload !== -1 && $post < $upload],
            ['opcache.enable', $opcacheEnabled ? 'JENABLED' : 'JDISABLED', ['OPCACHE', []], !$opcacheEnabled],
            ['display_errors', $errors ? 'JON' : 'JOFF', ['DISPLAY_ERRORS', []], $errors],
        ];

        return array_map(
            static fn (array $row): array => [
                'setting'     => $row[0],
                'current'     => $row[1],
                'recommended' => ['PLG_HEALTHCHECK_SITEINFO_RECOMMENDED_' . $row[2][0], $row[2][1]],
                'status'      => $row[3] ? SupportData::STATUS_WARNING : SupportData::STATUS_HEALTHY,
            ],
            $rows
        );
    }

    /**
     * Convert a PHP shorthand byte value to bytes.
     *
     * @param   string  $value  The value, e.g. 128M, 1G, -1
     *
     * @return  integer  Bytes, or -1 for unlimited
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    /**
     * Get the worst of several statuses.
     *
     * @param   array  $statuses  Status values
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function worst(array $statuses): string
    {
        foreach (self::SEVERITY as $status) {
            if (\in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return SupportData::STATUS_NONE;
    }
}
