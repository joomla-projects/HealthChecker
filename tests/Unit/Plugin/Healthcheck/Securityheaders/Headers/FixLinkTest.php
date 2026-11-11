<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Securityheaders
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Securityheaders\Headers;

use Joomla\Plugin\Healthcheck\Securityheaders\Headers\FixLink;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the security headers fix links
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The security headers fix link')]
class FixLinkTest extends UnitTestCase
{
    private const PLUGIN = 'index.php?option=com_plugins&task=plugin.edit&extension_id=188';

    /**
     * Rows and the link they get.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function linkProvider(): array
    {
        $config = ['url' => 'index.php?option=com_config#page-server', 'label' => 'PLG_HEALTHCHECK_SECURITYHEADERS_OPEN_CONFIG'];
        $plugin = static fn (string $tab): array => [
            'url'   => self::PLUGIN . '#' . $tab,
            'label' => 'PLG_HEALTHCHECK_SECURITYHEADERS_OPEN_HTTPHEADERS',
        ];

        return [
            'hsts'                        => ['hsts', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS', 188, $plugin('attrib-hsts')],
            'csp'                         => ['csp', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_CSP', 188, $plugin('attrib-csp')],
            'frame'                       => ['frame', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_FRAME', 188, $plugin('attrib-basic')],
            'referrer'                    => ['referrer', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_REFERRER', 188, $plugin('attrib-basic')],
            'coop'                        => ['coop', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_COOP', 188, $plugin('attrib-basic')],
            'permissions'                 => ['permissions', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_PERMISSIONS', 188, $plugin('attrib-basic')],
            'hsts http'                   => ['hsts', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS_HTTP', 188, $config],
            'hsts http without plugin id' => ['hsts', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS_HTTP', null, $config],
            'nosniff'                     => ['nosniff', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_NOSNIFF', 188, null],
            'leak'                        => ['leak', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_LEAK', 188, null],
            'hsts full marks'             => ['hsts', null, 188, null],
            'csp full marks'              => ['csp', null, 188, null],
            'hsts no id'                  => ['hsts', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS', null, null],
            'csp no id'                   => ['csp', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_CSP', null, null],
            'frame no id'                 => ['frame', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_FRAME', null, null],
            'coop no id'                  => ['coop', 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_COOP', null, null],
        ];
    }

    #[DataProvider('linkProvider')]
    #[TestDox('maps $key with fix $fix and plugin id $id')]
    public function testFor(string $key, ?string $fix, ?int $id, ?array $expected): void
    {
        $this->assertSame($expected, FixLink::for(['key' => $key, 'fix' => $fix], $id));
    }
}
