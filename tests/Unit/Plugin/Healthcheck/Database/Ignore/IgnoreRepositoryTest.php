<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Ignore;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\Mysqli\MysqliQuery;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the ignore repository, without a database
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The ignore repository')]
class IgnoreRepositoryTest extends UnitTestCase
{
    #[TestDox('a failed insert does not pass the database error on to the response')]
    public function testInsertFailureIsGeneric(): void
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('createQuery')->willReturnCallback(static fn () => new MysqliQuery($db));
        $db->method('quoteName')->willReturnArgument(0);
        $db->method('setQuery')->willReturnSelf();
        $db->method('loadAssocList')->willReturn([]);
        $db->method('execute')->willThrowException(
            new ExecutionFailureException('INSERT INTO secret_table', 'Table secret_table is read only', 1036)
        );

        try {
            (new IgnoreRepository($db))->add('table', 'hc_orphan', 1, '2026-10-17 10:00:00');
            $this->fail('A failed insert must throw');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(ExecutionFailureException::class, $e);
            $this->assertStringNotContainsString('secret_table', $e->getMessage());
            $this->assertSame(500, $e->getCode());
        }
    }

    #[TestDox('names with spaces, also at either end, are valid (MySQL allows a leading space, PostgreSQL both)')]
    public function testSpacesAroundNamesAreValid(): void
    {
        $this->assertTrue(IgnoreRepository::isValid('table', ' old_users'));
        $this->assertTrue(IgnoreRepository::isValid('table', 'hc_orphan '));
        $this->assertTrue(IgnoreRepository::isValid('column', ' old_users.created'));
        $this->assertTrue(IgnoreRepository::isValid('table', 'hc_my table'));
    }
}
