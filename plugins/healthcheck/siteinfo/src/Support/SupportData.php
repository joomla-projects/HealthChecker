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
 * Support data (vendor end of life and Joomla requirements) and the rules that classify a version.
 *
 * Built-in values come from endoflife.date and the Joomla manual's Technical Requirements page.
 * Refresh them with every Joomla release. Requirements can also be refreshed at runtime from the manual.
 *
 * @since  __DEPLOY_VERSION__
 */
final class SupportData
{
    /**
     * Status values
     *
     * @since  __DEPLOY_VERSION__
     */
    public const STATUS_CRITICAL = 'critical';
    public const STATUS_WARNING  = 'warning';
    public const STATUS_HEALTHY  = 'healthy';
    public const STATUS_NONE     = 'none';

    /**
     * Months before end of life when a supported version starts to warn
     *
     * @since  __DEPLOY_VERSION__
     */
    public const WARNING_MONTHS = 6;

    /**
     * Built-in release cycles: active = end of full support (null if not published), eol = end of security fixes.
     * Source: https://endoflife.date (retrieved 2026-10-15). Include every cycle at or above Joomla's minimum
     * supported version (PHP 8.3, MySQL 8.0, MariaDB 10.4, PostgreSQL 12), including cycles already end of life.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const CYCLES = [
        'php' => [
            '7.4' => ['active' => '2021-11-28', 'eol' => '2022-11-28'],
            '8.0' => ['active' => '2022-11-26', 'eol' => '2023-11-26'],
            '8.1' => ['active' => '2023-11-25', 'eol' => '2025-12-31'],
            '8.2' => ['active' => '2024-12-31', 'eol' => '2026-12-31'],
            '8.3' => ['active' => '2025-12-31', 'eol' => '2027-12-31'],
            '8.4' => ['active' => '2026-12-31', 'eol' => '2028-12-31'],
            '8.5' => ['active' => '2027-12-31', 'eol' => '2029-12-31'],
        ],
        'mysql' => [
            '5.7' => ['active' => '2020-10-31', 'eol' => '2023-10-31'],
            '8.0' => ['active' => '2025-04-30', 'eol' => '2026-04-30'],
            '8.1' => ['active' => '2023-10-25', 'eol' => '2023-10-25'],
            '8.2' => ['active' => '2024-01-16', 'eol' => '2024-01-16'],
            '8.3' => ['active' => '2024-04-30', 'eol' => '2024-04-30'],
            '8.4' => ['active' => '2029-04-30', 'eol' => '2032-04-30'],
            '9.0' => ['active' => '2024-10-15', 'eol' => '2024-10-15'],
            '9.1' => ['active' => '2025-01-21', 'eol' => '2025-01-21'],
            '9.2' => ['active' => '2025-04-15', 'eol' => '2025-04-15'],
            '9.3' => ['active' => '2025-07-22', 'eol' => '2025-07-22'],
            '9.4' => ['active' => '2025-10-21', 'eol' => '2025-10-21'],
            '9.5' => ['active' => '2026-01-20', 'eol' => '2026-01-20'],
            '9.6' => ['active' => '2026-04-21', 'eol' => '2026-04-21'],
            '9.7' => ['active' => '2031-04-30', 'eol' => '2034-04-30'],
        ],
        'mariadb' => [
            '10.4'  => ['active' => null, 'eol' => '2024-06-18'],
            '10.5'  => ['active' => null, 'eol' => '2025-06-24'],
            '10.6'  => ['active' => null, 'eol' => '2026-07-06'],
            '10.7'  => ['active' => null, 'eol' => '2023-02-09'],
            '10.8'  => ['active' => null, 'eol' => '2023-05-20'],
            '10.9'  => ['active' => null, 'eol' => '2023-08-22'],
            '10.10' => ['active' => null, 'eol' => '2023-11-17'],
            '10.11' => ['active' => null, 'eol' => '2028-02-16'],
            '11.0'  => ['active' => null, 'eol' => '2024-06-06'],
            '11.1'  => ['active' => null, 'eol' => '2024-08-21'],
            '11.2'  => ['active' => null, 'eol' => '2024-11-21'],
            '11.3'  => ['active' => null, 'eol' => '2024-05-29'],
            '11.4'  => ['active' => null, 'eol' => '2029-05-29'],
            '11.5'  => ['active' => null, 'eol' => '2024-11-21'],
            '11.6'  => ['active' => null, 'eol' => '2025-02-13'],
            '11.7'  => ['active' => null, 'eol' => '2025-05-12'],
            '11.8'  => ['active' => null, 'eol' => '2028-06-04'],
            '12.0'  => ['active' => null, 'eol' => '2025-11-18'],
            '12.1'  => ['active' => null, 'eol' => '2026-02-13'],
            '12.2'  => ['active' => null, 'eol' => '2026-05-28'],
            '12.3'  => ['active' => null, 'eol' => '2029-06-12'],
            '13.0'  => ['active' => null, 'eol' => '2026-12-31'],
        ],
        'postgresql' => [
            '12' => ['active' => null, 'eol' => '2024-11-21'],
            '13' => ['active' => null, 'eol' => '2025-11-13'],
            '14' => ['active' => null, 'eol' => '2026-11-12'],
            '15' => ['active' => null, 'eol' => '2027-11-11'],
            '16' => ['active' => null, 'eol' => '2028-11-09'],
            '17' => ['active' => null, 'eol' => '2029-11-08'],
            '18' => ['active' => null, 'eol' => '2030-11-14'],
        ],
        'joomla' => [
            '3' => ['active' => '2021-08-17', 'eol' => '2023-08-17'],
            '4' => ['active' => '2024-10-15', 'eol' => '2025-10-14'],
            '5' => ['active' => '2026-10-13', 'eol' => '2027-10-12'],
            '6' => ['active' => '2028-10-17', 'eol' => '2029-10-16'],
        ],
    ];

    /**
     * Joomla 6.x requirements: [recommended, supported, minimum]. Minimum null = not enforced.
     * Source: https://manual.joomla.org/docs/get-started/technical-requirements/ (the page SupportData::MANUAL_URL refreshes from, retrieved 2026-10-15)
     *
     * @since  __DEPLOY_VERSION__
     */
    private const REQUIREMENTS = [
        'php'        => ['8.4', '8.3.0', '8.3.0'],
        'mysql'      => ['8.4', '8.0.13', '8.0.13'],
        'mariadb'    => ['12.0', '10.6', '10.4'],
        'postgresql' => ['17.6', '14.0', '12.0'],
        'apache'     => ['2.4', '2.4', null],
        'nginx'      => ['1.29', '1.26', null],
        'iis'        => ['10', '10', null],
    ];

    /**
     * The manual page holding Joomla's technical requirements
     *
     * @since  __DEPLOY_VERSION__
     */
    public const MANUAL_URL = 'https://manual.joomla.org/docs/get-started/technical-requirements/';

    /**
     * Row names on the manual page mapped to products
     *
     * @since  __DEPLOY_VERSION__
     */
    private const MANUAL_PRODUCTS = [
        'php'           => 'php',
        'mysql'         => 'mysql',
        'mariadb'       => 'mariadb',
        'postgresql'    => 'postgresql',
        'apache'        => 'apache',
        'nginx'         => 'nginx',
        'microsoft iis' => 'iis',
    ];

    /**
     * Requirements in use (built-in, with refreshed products replaced)
     *
     * @var    array
     * @since  __DEPLOY_VERSION__
     */
    private array $requirements;

    /**
     * Constructor
     *
     * @param   array  $requirements  Refreshed requirements, [product => [recommended, supported, ?minimum]]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $requirements = [])
    {
        $this->requirements = array_merge(self::REQUIREMENTS, array_intersect_key($requirements, self::REQUIREMENTS));
    }

    /**
     * Strip vendor and pre-release suffixes, keeping the leading numeric version.
     *
     * @param   string  $version  The raw version string
     *
     * @return  string  The numeric version, or an empty string if there is none
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function normalize(string $version): string
    {
        return preg_match('/^\d+(?:\.\d+)*/', trim($version), $matches) ? $matches[0] : '';
    }

    /**
     * Get the release cycle a version belongs to.
     *
     * @param   string  $product  The product name
     * @param   string  $version  The version
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function cycleFor(string $product, string $version): string
    {
        $parts = explode('.', self::normalize($version));

        if ($product === 'joomla' || ($product === 'postgresql' && (int) $parts[0] >= 10)) {
            return $parts[0];
        }

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /**
     * Whether end of life data exists for a product.
     *
     * @param   string  $product  The product name
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function hasEolData(string $product): bool
    {
        return isset(self::CYCLES[$product]);
    }

    /**
     * Get the dates of one release cycle.
     *
     * @param   string  $product  The product name
     * @param   string  $cycle    The cycle key
     *
     * @return  array|null  ['active' => ?string, 'eol' => string] or null if unknown
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getCycle(string $product, string $cycle): ?array
    {
        return self::CYCLES[$product][$cycle] ?? null;
    }

    /**
     * Classify a version by its vendor end of life.
     *
     * @param   string              $product  The product name
     * @param   string              $version  The detected version
     * @param   \DateTimeImmutable  $today    The current date
     *
     * @return  array  ['status' => string, 'key' => language key, 'args' => list<string>, 'eol' => string]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getEolStatus(string $product, string $version, \DateTimeImmutable $today): array
    {
        if (self::normalize($version) === '') {
            return $this->result(self::STATUS_NONE, 'NO_DATA');
        }

        $cycle = self::cycleFor($product, $version);
        $dates = $this->getCycle($product, $cycle);

        if ($dates === null) {
            $known = array_keys(self::CYCLES[$product] ?? []);
            usort($known, 'version_compare');

            if ($known && version_compare($cycle, $known[0], '<')) {
                return $this->result(self::STATUS_CRITICAL, 'EOL_OLD');
            }

            if ($known && version_compare($cycle, end($known), '>')) {
                return $this->result(self::STATUS_HEALTHY, 'NEWER');
            }

            return $this->result(self::STATUS_NONE, 'NO_DATA');
        }

        $eol  = $dates['eol'];
        $day  = $today->format('Y-m-d');
        $soon = $today->modify('+' . self::WARNING_MONTHS . ' months')->format('Y-m-d');

        if ($eol < $day) {
            return $this->result(self::STATUS_CRITICAL, 'EOL_PAST', [$eol], $eol);
        }

        if ($eol <= $soon) {
            return $this->result(self::STATUS_WARNING, 'EOL_SOON', [$eol], $eol);
        }

        if ($dates['active'] !== null && $dates['active'] < $day) {
            return $this->result(self::STATUS_WARNING, 'SECURITY_ONLY', [$eol], $eol);
        }

        return $this->result(self::STATUS_HEALTHY, 'SUPPORTED', [$eol], $eol);
    }

    /**
     * Classify a version against Joomla's own requirements.
     *
     * @param   string  $product  The product name
     * @param   string  $version  The detected version
     *
     * @return  array|null  ['status' => string, 'key' => language key, 'args' => list<string>], null if nothing to report
     *
     * @since   __DEPLOY_VERSION__
     */
    public function getRequirementStatus(string $product, string $version): ?array
    {
        $version = self::normalize($version);

        if ($version === '' || !isset($this->requirements[$product])) {
            return null;
        }

        [$recommended, $supported, $minimum] = $this->requirements[$product];

        if ($minimum !== null && version_compare($version, $minimum, '<')) {
            return $this->requirement(self::STATUS_CRITICAL, 'BELOW_MINIMUM', $minimum);
        }

        if (version_compare($version, $supported, '<')) {
            return $this->requirement(self::STATUS_WARNING, 'BELOW_SUPPORTED', $supported);
        }

        if (version_compare($version, $recommended, '<')) {
            return $this->requirement(self::STATUS_NONE, 'BELOW_RECOMMENDED', $recommended);
        }

        return null;
    }

    /**
     * Read Joomla's requirements for one major version from the manual's Technical Requirements page.
     *
     * This is the only place that knows the page's structure. Replace it if the manual publishes structured data.
     *
     * @param   string   $html   The page HTML
     * @param   integer  $major  The Joomla major version
     *
     * @return  array  ['requirements' => [product => [recommended, supported, ?minimum]], 'memory' => ?string]
     *
     * @throws  \UnexpectedValueException
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function parseManual(string $html, int $major): array
    {
        if (trim($html) === '') {
            throw new \UnexpectedValueException('Empty manual page');
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath   = new \DOMXPath($document);
        $heading = 'Requirements for Joomla! ' . $major . '.x';
        $table   = $xpath->query('(//h2|//h3)[contains(normalize-space(.), "' . $heading . '")]/following-sibling::*[self::table or self::h2 or self::h3][1][self::table]')->item(0);

        if ($table === null) {
            throw new \UnexpectedValueException('No "' . $heading . '" table on the manual page');
        }

        $requirements = [];

        foreach ($xpath->query('.//tbody/tr', $table) as $row) {
            $cells   = array_map(static fn (\DOMNode $cell): string => trim($cell->textContent), iterator_to_array($xpath->query('./td', $row)));
            $product = self::MANUAL_PRODUCTS[strtolower($cells[0] ?? '')] ?? null;

            if ($product === null || \count($cells) < 4 || $cells[1] === '' || $cells[2] === '') {
                continue;
            }

            $minimum = $cells[3] === '' ? null : $cells[3];

            foreach ([$cells[1], $cells[2], $minimum] as $value) {
                if ($value !== null && !preg_match('/^\d+(\.\d+)*$/', $value)) {
                    throw new \UnexpectedValueException('Unexpected version "' . $value . '" for ' . $cells[0] . ' in the "' . $heading . '" table');
                }
            }

            $requirements[$product] = [$cells[1], $cells[2], $minimum];
        }

        if (!isset($requirements['php'])) {
            throw new \UnexpectedValueException('No PHP row in the "' . $heading . '" table');
        }

        $memory = preg_match('/Recommended PHP Memory Limit:\s*at least\s*(\d+)\s*MB/i', $document->textContent, $matches) ? $matches[1] . 'M' : null;

        return ['requirements' => $requirements, 'memory' => $memory];
    }

    /**
     * Build a status result.
     *
     * @param   string  $status  The status
     * @param   string  $key     Language key suffix after PLG_HEALTHCHECK_SITEINFO_STATUS_
     * @param   array   $args    Arguments for Text::sprintf
     * @param   string  $eol     End of life date, if known
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function result(string $status, string $key, array $args = [], string $eol = ''): array
    {
        return ['status' => $status, 'key' => 'PLG_HEALTHCHECK_SITEINFO_STATUS_' . $key, 'args' => $args, 'eol' => $eol];
    }

    /**
     * Build a requirement result.
     *
     * @param   string  $status   The status
     * @param   string  $key      Language key suffix after PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_
     * @param   string  $version  The threshold version
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function requirement(string $status, string $key, string $version): array
    {
        return ['status' => $status, 'key' => 'PLG_HEALTHCHECK_SITEINFO_REQUIREMENT_' . $key, 'args' => [$version]];
    }
}
