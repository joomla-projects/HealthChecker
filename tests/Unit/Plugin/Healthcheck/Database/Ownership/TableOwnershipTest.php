<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Ownership;

use Joomla\Plugin\Healthcheck\Database\Ownership\TableOwnership;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the table ownership reader
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The table ownership reader')]
class TableOwnershipTest extends UnitTestCase
{
    /**
     * An #__extensions row.
     *
     * @param   string  $type     Extension type
     * @param   string  $element  Element
     * @param   string  $folder   Plugin folder
     * @param   string  $client   Client id (string, as a database row gives it)
     *
     * @return  object
     *
     * @since   __DEPLOY_VERSION__
     */
    private function extension(string $type, string $element, string $folder = '', string $client = '0'): object
    {
        return (object) ['type' => $type, 'element' => $element, 'folder' => $folder, 'client_id' => $client];
    }

    /**
     * An ownership reader over the fixture site.
     *
     * @return  TableOwnership
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ownership(): TableOwnership
    {
        $root = \dirname(__DIR__) . '/fixtures/site';

        return new TableOwnership($root, $root . '/administrator');
    }

    #[TestDox('Core tables and tables from install and update SQL are accounted for')]
    public function testCoreAndExtensionTables(): void
    {
        $names = $this->ownership()->accounted([
            $this->extension('component', 'com_foo'),
            $this->extension('plugin', 'bar', 'system'),
            $this->extension('module', 'mod_baz', '', '0'),
        ]);

        $this->assertContains('#__content', $names);
        $this->assertContains('#__foo_items', $names);
        $this->assertContains('#__foo_log', $names);
        $this->assertSame($names, array_values(array_unique($names)));
    }

    #[TestDox('Without a schemapath in the manifest, the update files in sql/updates/<driver> count, as in core')]
    public function testDefaultUpdateFolders(): void
    {
        $names = $this->ownership()->accounted([$this->extension('component', 'com_bar')]);

        $this->assertContains('#__bar_items', $names);
        $this->assertContains('#__bar_pg', $names);
    }

    #[TestDox('Extension types without a known location are skipped')]
    public function testUnknownTypeSkipped(): void
    {
        // com_foo would resolve to a fixture with SQL if a library were treated like a component.
        $names = $this->ownership()->accounted([$this->extension('library', 'com_foo')]);

        $this->assertNotContains('#__foo_items', $names);
        $this->assertNotContains('#__foo_log', $names);
    }

    #[TestDox('A manifest path leaving the extension folder is not read')]
    public function testPathEscapeRejected(): void
    {
        $names = $this->ownership()->accounted([$this->extension('component', 'com_evil')]);

        $this->assertNotContains('#__evil', $names);
    }

    #[TestDox('A manifest symlinked from outside the extension folder is not read')]
    public function testSymlinkedManifestRejected(): void
    {
        $root = sys_get_temp_dir() . '/hcdb-' . uniqid();
        $base = $root . '/administrator/components/com_sym';

        mkdir($base . '/sql', 0777, true);
        file_put_contents($root . '/outside.xml', '<extension type="component"><install><sql><file>sql/a.sql</file></sql></install></extension>');
        file_put_contents($base . '/sql/a.sql', 'CREATE TABLE `#__symlinked` (`id` int);');
        symlink($root . '/outside.xml', $base . '/sym.xml');

        try {
            $names = (new TableOwnership($root, $root . '/administrator'))->accounted([$this->extension('component', 'com_sym')]);
            $this->assertNotContains('#__symlinked', $names);
        } finally {
            unlink($base . '/sym.xml');
            unlink($base . '/sql/a.sql');
            unlink($root . '/outside.xml');
            rmdir($base . '/sql');
            rmdir($base);
            rmdir($root . '/administrator/components');
            rmdir($root . '/administrator');
            rmdir($root);
        }
    }
}
