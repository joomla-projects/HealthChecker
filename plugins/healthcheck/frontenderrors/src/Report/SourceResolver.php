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
 * Maps a script file path or URL to the extension that ships it.
 *
 * @since  __DEPLOY_VERSION__
 */
final class SourceResolver
{
    private const PATTERNS = [
        '#/media/(?:system|vendor|legacy)/#'                 => null,
        '#/media/templates/(?:site|administrator)/([^/]+)/#' => 'tpl_$1',
        '#/media/((?:com|mod|lib)_[^/]+)/#'                  => '$1',
        '#/media/(plg_[^/]+)/#'                              => '$1',
        '#/components/(com_[^/]+)/#'                         => '$1',
        '#/modules/(mod_[^/]+)/#'                            => '$1',
        '#/plugins/([^/]+)/([^/]+)/#'                        => 'plg_$1_$2',
        '#/templates/([^/]+)/#'                              => 'tpl_$1',
    ];

    /**
     * Resolve a file to its source label.
     *
     * @param   string  $file  Path or absolute URL, without query string.
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function resolve(string $file): ?string
    {
        if ($file === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $file) === 1) {
            $host = parse_url($file, PHP_URL_HOST);

            if (\is_string($host) && $host !== '') {
                return 'external:' . $host;
            }
        }

        foreach (self::PATTERNS as $regex => $label) {
            if (preg_match($regex, $file, $m) === 1) {
                return $label === null ? 'core' : preg_replace_callback(
                    '#\$(\d)#',
                    static fn (array $r): string => $m[(int) $r[1]],
                    $label
                );
            }
        }

        return null;
    }
}
