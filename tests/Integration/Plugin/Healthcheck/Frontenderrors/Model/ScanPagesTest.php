<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Plugin\Healthcheck\Frontenderrors\Model;

use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ScanPages;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Test class for the Frontend Errors scan page list (runs on MySQL/MariaDB and PostgreSQL).
 *
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
class ScanPagesTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    /**
     * Guest view levels used by the tests: Public (1) and Guest (5).
     */
    private const GUEST_LEVELS = [1, 5];

    private const NOW = '2026-10-16 12:00:00';

    private int $lft = 0;

    public function getSchemasToLoad(): array
    {
        return ['frontenderrors_menu.sql'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->getDBDriver()->setQuery('DELETE FROM ' . $this->getDBDriver()->quoteName('#__menu'))->execute();
        $this->lft = 0;
    }

    /**
     * Insert one menu item and return its id.
     */
    private function item(string $alias, array $overrides = []): int
    {
        $db         = $this->getDBDriver();
        $this->lft += 2;

        $row = (object) array_merge(
            [
                'menutype'  => 'mainmenu',
                'title'     => $alias,
                'alias'     => $alias,
                'link'      => 'index.php?option=com_content&view=article&id=1',
                'type'      => 'component',
                'published' => 1,
                'parent_id' => 1,
                'level'     => 1,
                'access'    => 1,
                'lft'       => $this->lft,
                'rgt'       => $this->lft + 1,
                'home'      => 0,
                'language'  => '*',
                'client_id' => 0,
            ],
            $overrides
        );

        $db->insertObject('#__menu', $row, 'id');

        return (int) $row->id;
    }

    /**
     * Only published, guest-visible, page-rendering site items are listed; home is left out.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOnlyGuestVisibleComponentItems(): void
    {
        $this->item('home', ['home' => 1]);
        $article = $this->item('article');
        $this->item('unpublished', ['published' => 0]);
        $this->item('trashed', ['published' => -2]);
        $this->item('registered', ['access' => 2]);
        $guest = $this->item('guest-only', ['access' => 5]);
        $this->item('separator', ['type' => 'separator', 'link' => '']);
        $this->item('heading', ['type' => 'heading', 'link' => '']);
        $this->item('url', ['type' => 'url', 'link' => 'https://example.org/']);
        $this->item('alias', ['type' => 'alias', 'link' => 'index.php?Itemid=']);
        $this->item('admin', ['client_id' => 1, 'menutype' => 'main']);
        $this->item('expired', ['publish_down' => '2026-10-01 00:00:00']);
        $this->item('scheduled', ['publish_up' => '2026-11-01 00:00:00']);
        $window = $this->item('in-window', ['publish_up' => '2026-10-01 00:00:00', 'publish_down' => '2026-11-01 00:00:00']);

        $this->assertSame([$article, $guest, $window], (new ScanPages($this->getDBDriver()))->itemIds(25, self::GUEST_LEVELS, self::NOW));
    }

    /**
     * The list follows menu order and stops at the limit.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testLimitAndMenuOrder(): void
    {
        $ids = [];

        for ($i = 1; $i <= 30; $i++) {
            $ids[] = $this->item('article-' . $i);
        }

        // Move the first item to the end of the tree: order is lft, not id.
        $db    = $this->getDBDriver();
        $query = $db->createQuery()
            ->update($db->quoteName('#__menu'))
            ->set($db->quoteName('lft') . ' = 1000')
            ->set($db->quoteName('rgt') . ' = 1001')
            ->where($db->quoteName('id') . ' = ' . (int) $ids[0]);
        $db->setQuery($query)->execute();

        $expected = \array_slice(array_merge(\array_slice($ids, 1), [$ids[0]]), 0, 25);

        $this->assertSame($expected, (new ScanPages($db))->itemIds(25, self::GUEST_LEVELS, self::NOW));
    }

    /**
     * No view levels or a zero limit give an empty list.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testEmptyInputs(): void
    {
        $this->item('article');

        $scan = new ScanPages($this->getDBDriver());

        $this->assertSame([], $scan->itemIds(25, [], self::NOW));
        $this->assertSame([], $scan->itemIds(0, self::GUEST_LEVELS, self::NOW));
    }
}
