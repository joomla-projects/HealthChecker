<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Catalog;

use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Plugin\Healthcheck\Database\Catalog\MissingObjectException;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the missing table or column classification
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The missing table or column classification')]
class MissingObjectExceptionTest extends UnitTestCase
{
    /**
     * Query failures that mean the table or column is gone.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function missing(): array
    {
        $pdo            = new \PDOException('SQLSTATE[42P01]: Undefined table');
        $pdo->errorInfo = ['42P01', 7, 'relation does not exist'];

        return [
            'mysqli table'         => [new ExecutionFailureException('q', "Table 'x' doesn't exist", 1146)],
            'mysqli column'        => [new ExecutionFailureException('q', "Unknown column 'c'", 1054)],
            'pdo mysql table'      => [new ExecutionFailureException('q', "42S02, 1146, Table 'x' doesn't exist", 42)],
            'pdo mysql column'     => [new ExecutionFailureException('q', "42S22, 1054, Unknown column 'c'", 42)],
            'pdo pgsql table'      => [new ExecutionFailureException('q', '42P01, 7, ERROR: relation "x" does not exist', 42)],
            'chained PDOException' => [new \RuntimeException('wrapped', 0, $pdo)],
        ];
    }

    /**
     * Query failures that say nothing about the table being gone.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function other(): array
    {
        return [
            'lost connection'    => [new ExecutionFailureException('q', 'MySQL server has gone away', 2006)],
            'access denied'      => [new ExecutionFailureException('q', "SELECT command denied to user 'u' for table 'x'", 1142)],
            'lock wait'          => [new ExecutionFailureException('q', 'Lock wait timeout exceeded', 1205)],
            'pdo lost'           => [new ExecutionFailureException('q', 'HY000, 2006, MySQL server has gone away', 0)],
            'state not at start' => [new \RuntimeException('Some text about 42S02 later')],
        ];
    }

    /**
     * A missing-object failure is recognised and wrapped as one.
     *
     * @param   \Throwable  $e  The failure
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('missing')]
    #[TestDox('$_dataName is a missing object, wrapped with the original as previous')]
    public function testMissing(\Throwable $e): void
    {
        $this->assertTrue(MissingObjectException::isMissingObject($e));

        $wrapped = MissingObjectException::wrap($e, 'Could not count');
        $this->assertInstanceOf(MissingObjectException::class, $wrapped);
        $this->assertSame($e, $wrapped->getPrevious());
    }

    /**
     * Any other failure is wrapped as a plain RuntimeException.
     *
     * @param   \Throwable  $e  The failure
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('other')]
    #[TestDox('$_dataName is a plain failure, wrapped with the original as previous')]
    public function testOther(\Throwable $e): void
    {
        $this->assertFalse(MissingObjectException::isMissingObject($e));

        $wrapped = MissingObjectException::wrap($e, 'Could not count');
        $this->assertNotInstanceOf(MissingObjectException::class, $wrapped);
        $this->assertInstanceOf(\RuntimeException::class, $wrapped);
        $this->assertSame($e, $wrapped->getPrevious());
    }
}
