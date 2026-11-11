<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Frontenderrors\Report;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Decides whether a normalised report is worth recording.
 *
 * @since  __DEPLOY_VERSION__
 */
final class NoiseFilter
{
    private const EXTENSION_SCHEMES = ['chrome-extension://', 'moz-extension://', 'safari-web-extension://'];

    /**
     * Whether the report should be kept.
     *
     * @param   array         $report          Normalised report.
     * @param   list<string>  $ignorePatterns  Case-insensitive substrings to drop.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function keep(array $report, array $ignorePatterns): bool
    {
        $message = (string) ($report['message'] ?? '');
        $file    = (string) ($report['file'] ?? '');
        $stack   = (string) ($report['stack'] ?? '');

        if ($message === '' && $file === '') {
            return false;
        }

        foreach (self::EXTENSION_SCHEMES as $scheme) {
            if (str_contains($file, $scheme) || str_contains($stack, $scheme)) {
                return false;
            }
        }

        if ($file === '' && rtrim($message, '.') === 'Script error') {
            return false;
        }

        foreach ($ignorePatterns as $pattern) {
            $pattern = (string) $pattern;

            if ($pattern === '') {
                continue;
            }

            if (stripos($message, $pattern) !== false || stripos($file, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }
}
