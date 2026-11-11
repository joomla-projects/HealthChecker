<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Frontenderrors\Settings;

use Joomla\Plugin\Healthcheck\Frontenderrors\Settings\Settings;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Frontend Errors settings.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
#[TestDox('The Frontend Errors settings')]
class SettingsTest extends UnitTestCase
{
    public function testDefaults(): void
    {
        $s = Settings::fromParams(new Registry());

        $this->assertSame('general', $s->context);
        $this->assertSame('ongoing', $s->mode);
        $this->assertNull($s->collectUntil);
        $this->assertFalse($s->collectConsole);
        $this->assertSame(100, $s->sampleRate);
        $this->assertSame(30, $s->retentionDays);
        $this->assertSame(3, $s->thresholdPage);
        $this->assertSame(7, $s->periodDays);
        $this->assertSame([], $s->ignorePatterns);
        $this->assertSame(25, $s->scanLimit);
    }

    public function testClamps(): void
    {
        $low  = Settings::fromParams(new Registry([
            'sample_rate' => 0, 'scan_limit' => 1, 'period_days' => 0, 'threshold_page' => 0, 'retention_days' => 0,
        ]));
        $high = Settings::fromParams(new Registry(['sample_rate' => 500, 'scan_limit' => 999]));

        $this->assertSame(1, $low->sampleRate);
        $this->assertSame(5, $low->scanLimit);
        $this->assertSame(1, $low->periodDays);
        $this->assertSame(1, $low->thresholdPage);
        $this->assertSame(1, $low->retentionDays);
        $this->assertSame(100, $high->sampleRate);
        $this->assertSame(100, $high->scanLimit);
    }

    public function testIgnorePatterns(): void
    {
        $s = Settings::fromParams(new Registry(['ignore_patterns' => "  foo \n\n bar\r\n"]));

        $this->assertSame(['foo', 'bar'], $s->ignorePatterns);
    }

    #[DataProvider('stateProvider')]
    public function testCollectionStates(array $params, string $today, string $state, bool $collecting): void
    {
        $s = Settings::fromParams(new Registry($params));

        $this->assertSame($state, $s->collectionState($today));
        $this->assertSame($collecting, $s->isCollecting($today));
    }

    public static function stateProvider(): array
    {
        return [
            'ongoing'      => [['mode' => 'ongoing'], '2026-10-30', 'ongoing', true],
            'until, last'  => [['mode' => 'timed', 'collect_until' => '2026-10-30'], '2026-10-30', 'until', true],
            'ended'        => [['mode' => 'timed', 'collect_until' => '2026-10-30'], '2026-10-31', 'ended', false],
            'unset, empty' => [['mode' => 'timed', 'collect_until' => ''], '2026-10-30', 'unset', false],
            'unset, junk'  => [['mode' => 'timed', 'collect_until' => 'not-a-date'], '2026-10-30', 'unset', false],
        ];
    }
}
