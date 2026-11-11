<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Ownership;

use Joomla\Plugin\Healthcheck\Database\Ownership\CoreTables;
use Joomla\Plugin\Healthcheck\Database\Ownership\SqlTableNames;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the shipped core table list
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The core table list')]
class CoreTablesTest extends UnitTestCase
{
    /**
     * The installation SQL folders to compare the shipped list against.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function drivers(): array
    {
        return [
            'mysql'      => ['mysql'],
            'postgresql' => ['postgresql'],
        ];
    }

    /**
     * The shipped list must match the installation SQL files.
     *
     * @param   string  $driver  Installation SQL folder
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('drivers')]
    public function testMatchesInstallationSql(string $driver): void
    {
        $found = [];

        foreach (['base', 'extensions', 'supports'] as $file) {
            $sql = file_get_contents(JPATH_ROOT . '/installation/sql/' . $driver . '/' . $file . '.sql');

            $this->assertIsString($sql);

            $found = array_merge($found, SqlTableNames::fromSql($sql));
        }

        $found    = array_values(array_unique($found));
        $expected = CoreTables::NAMES;

        sort($found);
        sort($expected);

        $this->assertSame(
            $expected,
            $found,
            'Missing from CoreTables::NAMES: ' . implode(', ', array_diff($found, $expected))
            . '. Extra in CoreTables::NAMES: ' . implode(', ', array_diff($expected, $found))
        );
    }

    /**
     * A core table name matches whatever its case.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testContainsIsCaseInsensitive(): void
    {
        $this->assertTrue(CoreTables::contains('#__Content'));
        $this->assertFalse(CoreTables::contains('#__nope'));
    }
}
