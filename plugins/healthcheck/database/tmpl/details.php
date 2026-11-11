<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;

/**
 * Details of unused tables or zero-date columns, with Ignore / Reopen. Names, counts, sizes and dates only.
 * Table and column names come from the database: escape all of them.
 *
 * Zero dates also name the table the last run could not check and the tables too large to check in one request.
 * The popup ends with the next step for the maintainer; the plugin itself never changes anything.
 *
 * Every row and group header carries its state (open, ignored, or prefix: ignored through Ignore all), and each
 * badge or button lists the states it is shown in, so the admin script can switch them in place.
 *
 * @var  array  $displayData  The result of Database::detailsData()
 */
$kind     = $displayData['kind'] ?? '';
$groups   = $displayData['groups'] ?? [];
$findings = $displayData['findings'] ?? [];
$skipped  = $displayData['skipped'] ?? [];
$byName   = $displayData['skippedName'] ?? [];
$error    = $displayData['error'] ?? null;
$notes    = $kind === 'zerodates' && ($skipped || $byName || $error !== null);
$rows     = ($kind === 'unused' && $groups) || ($kind === 'zerodates' && $findings);

$e      = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$hidden = static fn (array $states, string $state): string => \in_array($state, $states, true) ? '' : ' hidden';
$dash   = static fn ($value, callable $format): string => $value === null ? '-' : $format($value);

/**
 * The Ignored badge and the Ignore / Reopen buttons of one row or group.
 *
 * @param   string  $state   open, ignored or prefix
 * @param   string  $kind    table, column or prefix
 * @param   string  $name    The ignore entry name
 * @param   string  $ignore  Label of the Ignore button
 */
$actions = static function (string $state, string $kind, string $name, string $ignore) use ($e, $hidden): string {
    $html = '<span class="badge bg-secondary" data-hc-db-show="ignored prefix"' . $hidden(['ignored', 'prefix'], $state) . '>'
        . $e(Text::_('PLG_HEALTHCHECK_DATABASE_BADGE_IGNORED')) . '</span>';

    if (!IgnoreRepository::isValid($kind, $name)) {
        return $html;
    }

    $button = static fn (string $action, string $class, string $label, array $states): string => ' <button type="button"'
        . ' class="btn btn-sm ' . $class . '" data-hc-db-show="' . implode(' ', $states) . '"'
        . ' data-hc-db-action="' . $action . '" data-hc-db-kind="' . $e($kind) . '" data-hc-db-name="' . $e($name) . '"'
        . $hidden($states, $state) . '>' . $e($label) . '</button>';

    return $html
        . $button('ignore', 'btn-outline-secondary', $ignore, ['open'])
        . $button('reopen', 'btn-outline-primary', Text::_('PLG_HEALTHCHECK_DATABASE_ACTION_REOPEN'), ['ignored']);
};
?>
<div class="p-2 plg-hc-db-details-body">
    <p class="text-body-secondary"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_DETAILS_READONLY')); ?></p>
<?php if ($kind === 'zerodates' && $error !== null) : ?>
    <div class="alert alert-warning" role="alert"><?php echo $e(Text::sprintf('PLG_HEALTHCHECK_DATABASE_ZERODATES_ERROR', $error)); ?></div>
<?php endif; ?>
<?php if ($kind === 'zerodates' && $skipped) : ?>
    <p><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_DETAILS_SKIPPED')); ?>
        <?php echo implode(', ', array_map(static fn ($name): string => '<code>' . $e($name) . '</code>', $skipped)); ?></p>
<?php endif; ?>
<?php if ($kind === 'zerodates' && $byName) : ?>
    <p><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_DETAILS_NAME_SKIPPED')); ?>
        <?php echo implode(', ', array_map(static fn ($name): string => '<code>' . $e($name) . '</code>', $byName)); ?></p>
<?php endif; ?>
<?php if (!$rows) : ?>
    <?php if (!$notes) : ?>
    <p class="mb-0"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_DETAILS_NONE')); ?></p>
    <?php endif; ?>
<?php elseif ($kind === 'unused') : ?>
    <?php foreach ($groups as $group) : ?>
        <?php
        $prefix     = (string) $group['prefix'];
        $groupState = $group['prefixIgnored'] ? 'ignored' : 'open';
        $where      = $prefix === '' ? Text::_('PLG_HEALTHCHECK_DATABASE_WHERE_NO_PREFIX') : $prefix;
        $finding    = $group['own'] ? 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED' : 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED_OTHER';
        ?>
        <div class="mb-3" data-hc-db-group>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1" data-hc-db-header data-hc-db-state="<?php echo $groupState; ?>">
                <h3 class="h6 mb-0"><?php echo $e(Text::_($finding)); ?>: <code><?php echo $e($where); ?></code></h3>
                <?php if (!$group['own'] && $prefix !== '') : ?>
                    <?php echo $actions($groupState, 'prefix', $prefix, Text::sprintf('PLG_HEALTHCHECK_DATABASE_ACTION_IGNORE_ALL', \count($group['tables']))); ?>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <caption class="visually-hidden"><?php echo $e(Text::_($finding) . ': ' . $where); ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_TABLE')); ?></th>
                            <th scope="col" class="text-end"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_ROWS')); ?></th>
                            <th scope="col" class="text-end"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_SIZE')); ?></th>
                            <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_CHANGED')); ?></th>
                            <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_ACTIONS')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['tables'] as $table) : ?>
                        <tr data-hc-db-state="<?php echo $e($table['state']); ?>">
                            <td class="text-break"><code><?php echo $e($table['name']); ?></code></td>
                            <td class="text-end"><?php echo $e($dash($table['rows'], static fn ($rows): string => (string) (int) $rows)); ?></td>
                            <td class="text-end"><?php echo $e($dash($table['bytes'], static fn ($bytes): string => (string) HTMLHelper::_('number.bytes', (int) $bytes))); ?></td>
                            <td><?php echo $e($table['updated'] === null ? '' : HTMLHelper::_('date', $table['updated'], Text::_('DATE_FORMAT_LC5'))); ?></td>
                            <td class="text-nowrap"><?php echo $actions($table['state'], 'table', (string) $table['name'], Text::_('PLG_HEALTHCHECK_DATABASE_ACTION_IGNORE')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php elseif ($kind === 'zerodates') : ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <caption class="visually-hidden"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME')); ?></caption>
            <thead>
                <tr>
                    <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_TABLE')); ?></th>
                    <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_COLUMN')); ?></th>
                    <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_TYPE')); ?></th>
                    <th scope="col" class="text-end"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_ZERO_ROWS')); ?></th>
                    <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_ACTIONS')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($findings as $finding) : ?>
                <tr data-hc-db-state="<?php echo $e($finding['state']); ?>">
                    <td class="text-break"><code><?php echo $e($finding['table']); ?></code></td>
                    <td class="text-break"><code><?php echo $e($finding['column']); ?></code></td>
                    <td><?php echo $e($finding['type']); ?></td>
                    <td class="text-end"><?php echo (int) $finding['zero']; ?></td>
                    <td class="text-nowrap"><?php echo $actions($finding['state'], 'column', $finding['table'] . '.' . $finding['column'], Text::_('PLG_HEALTHCHECK_DATABASE_ACTION_IGNORE')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php if ($rows) : ?>
    <p class="mb-0"><?php echo $e(Text::_($kind === 'zerodates' ? 'PLG_HEALTHCHECK_DATABASE_DETAILS_NEXT_ZERODATES' : 'PLG_HEALTHCHECK_DATABASE_DETAILS_NEXT_UNUSED')); ?></p>
<?php endif; ?>
</div>
