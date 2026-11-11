<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Ownership;

use Joomla\Plugin\Healthcheck\Database\Ownership\SqlTableNames;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the SQL table name extraction
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The SQL table name extraction')]
class SqlTableNamesTest extends UnitTestCase
{
    /**
     * CREATE TABLE with backticks, double quotes, brackets, IF NOT EXISTS and LIKE.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCreateVariants(): void
    {
        $sql = "CREATE TABLE `#__a` (id int);\n"
            . "CREATE TABLE IF NOT EXISTS \"#__B\" (id int);\n"
            . "create table [#__c] (id int);\n"
            . 'CREATE TABLE #__d LIKE #__a;';

        $this->assertSame(['#__a', '#__b', '#__c', '#__d'], SqlTableNames::fromSql($sql));
    }

    /**
     * RENAME TABLE and ALTER TABLE ... RENAME TO name the new table.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRenames(): void
    {
        $sql = "RENAME TABLE #__old TO #__new, #__x TO #__y;\n"
            . 'ALTER TABLE "#__p" RENAME TO "#__q";';

        $this->assertSame(['#__new', '#__y', '#__q'], SqlTableNames::fromSql($sql));
    }

    /**
     * A statement continued on the next line, which starts with `#__`, is not a `#` comment.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testLineStartPrefixContinuation(): void
    {
        $this->assertSame(['#__new'], SqlTableNames::fromSql("RENAME TABLE\n#__old TO #__new;"));
        $this->assertSame(['#__b'], SqlTableNames::fromSql("CREATE TABLE\n#__b (id int);"));
    }

    /**
     * Statements inside comments are not read.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCommentsIgnored(): void
    {
        $sql = "-- CREATE TABLE #__no\n"
            . "/* CREATE TABLE #__no2 */\n"
            . '# CREATE TABLE #__no3';

        $this->assertSame([], SqlTableNames::fromSql($sql));
    }

    /**
     * Several statements on one line are all read.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSeveralPerLine(): void
    {
        $sql = 'CREATE TABLE #__a (id int); CREATE TABLE #__b (id int);';

        $this->assertSame(['#__a', '#__b'], SqlTableNames::fromSql($sql));
    }

    /**
     * Only names with the #__ placeholder count.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testIgnoresLiteralPrefix(): void
    {
        $this->assertSame([], SqlTableNames::fromSql('CREATE TABLE jos_content (id int);'));
    }

    /**
     * Each name is returned once, lowercased.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDuplicatesOnce(): void
    {
        $sql = 'CREATE TABLE #__a (id int); CREATE TABLE IF NOT EXISTS `#__A` (id int);';

        $this->assertSame(['#__a'], SqlTableNames::fromSql($sql));
    }
}
