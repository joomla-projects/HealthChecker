<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Module\Healthcheck\Administrator\Helper;

use Joomla\Module\Healthcheck\Administrator\Helper\HealthCheckHelper;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Health Check section grouping and summary
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The Health Check section grouping')]
class HealthCheckHelperTest extends UnitTestCase
{
    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('groups items of every type by section, in order of first appearance')]
    public function testGroupsInOrder(): void
    {
        $sections = HealthCheckHelper::groupBySection(
            [['id' => 'g1', 'section' => 'Security Headers']],
            [['id' => 'b1', 'section' => 'SSL Certificate'], ['id' => 'b2', 'section' => 'Security Headers']],
            [],
            [['id' => 't1', 'section' => 'Security Headers']],
            'Other checks'
        );

        $this->assertSame(['Security Headers', 'SSL Certificate'], array_column($sections, 'title'));
        $this->assertSame(['g1'], array_column($sections[0]['gauges'], 'id'));
        $this->assertSame(['b2'], array_column($sections[0]['buttons'], 'id'));
        $this->assertSame(['t1'], array_column($sections[0]['tables'], 'id'));
        $this->assertSame(['b1'], array_column($sections[1]['buttons'], 'id'));
        $this->assertSame([], $sections[1]['gauges']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('puts items without a section into one fallback panel, always last')]
    public function testFallbackLast(): void
    {
        $sections = HealthCheckHelper::groupBySection(
            [['id' => 'g1'], ['id' => 'g2', 'section' => null]],
            [['id' => 'b1', 'section' => '  '], ['id' => 'b2', 'section' => 'Site Information']],
            [['id' => 'l1', 'section' => 42]],
            [],
            'Other checks'
        );

        $this->assertSame(['Site Information', 'Other checks'], array_column($sections, 'title'));
        $this->assertSame(['g1', 'g2'], array_column($sections[1]['gauges'], 'id'));
        $this->assertSame(['b1'], array_column($sections[1]['buttons'], 'id'));
        $this->assertSame(['l1'], array_column($sections[1]['lists'], 'id'));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('returns no panels when there are no items')]
    public function testEmpty(): void
    {
        $this->assertSame([], HealthCheckHelper::groupBySection([], [], [], [], 'Other checks'));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('counts each item once by its status and reports the worst')]
    public function testSummarise(): void
    {
        $section = [
            'title'   => 'X',
            'gauges'  => [],
            'buttons' => [['status' => 'error'], ['status' => 'warning'], ['status' => 'success'], ['status' => null], ['status' => 'info']],
            'lists'   => [['status' => 'warning']],
            'tables'  => [['status' => 'success']],
        ];

        $this->assertSame(
            ['worst' => 'critical', 'counts' => ['critical' => 1, 'warning' => 2, 'healthy' => 4]],
            HealthCheckHelper::summarise($section)
        );

        $section['buttons'] = [['status' => 'success']];

        $this->assertSame('warning', HealthCheckHelper::summarise($section)['worst']);

        $this->assertSame(
            ['worst' => 'healthy', 'counts' => ['critical' => 0, 'warning' => 0, 'healthy' => 0]],
            HealthCheckHelper::summarise(['title' => 'Empty', 'gauges' => [], 'buttons' => [], 'lists' => [], 'tables' => []])
        );
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function gaugeProvider(): array
    {
        return [
            'explicit error'                                      => [['status' => 'error', 'score' => 99], 'critical'],
            'explicit success wins'                               => [['status' => 'success', 'score' => 1], 'healthy'],
            'score below default warning'                         => [['score' => 49], 'critical'],
            'score at default warning'                            => [['score' => 50], 'warning'],
            'score below default success'                         => [['score' => 89], 'warning'],
            'score at default success'                            => [['score' => 90], 'healthy'],
            'custom thresholds'                                   => [['score' => 75, 'score_threshold_warning' => 50, 'score_threshold_success' => 80], 'warning'],
            'null thresholds use defaults'                        => [['score' => 95, 'score_threshold_warning' => null, 'score_threshold_success' => null], 'healthy'],
            'empty status is explicit (healthy, like the layout)' => [['status' => '', 'score' => 10], 'healthy'],
            'null status uses score'                              => [['status' => null, 'score' => 10], 'critical'],
        ];
    }

    /**
     * @param   array   $gauge     Gauge item
     * @param   string  $expected  Status
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('gaugeProvider')]
    #[TestDox('derives a gauge status the way the gauge layout does')]
    public function testGaugeStatus(array $gauge, string $expected): void
    {
        $summary = HealthCheckHelper::summarise(['title' => 'G', 'gauges' => [$gauge], 'buttons' => [], 'lists' => [], 'tables' => []]);

        $this->assertSame($expected, $summary['worst']);
        $this->assertSame(1, $summary['counts'][$expected]);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('returns a distinct accordion id on every call')]
    public function testAccordionIdIsUnique(): void
    {
        $first  = HealthCheckHelper::accordionId(5);
        $second = HealthCheckHelper::accordionId(5);

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('healthcheck-sections-5-', $first);
    }
}
