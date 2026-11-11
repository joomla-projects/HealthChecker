<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * The errors of one page. Every value comes from the public internet: escape all of it.
 *
 * @var  array  $displayData  errors: rows from ErrorRepository::pageErrors(), periodDays: int
 */
$errors     = $displayData['errors'] ?? [];
$periodDays = (int) ($displayData['periodDays'] ?? 0);

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

// State values: 0 open, 1 resolved, 2 ignored, 3 regressed. Each control lists the states it is shown in.
$badges = [
    ['label' => 'PLG_HEALTHCHECK_FRONTENDERRORS_STATE_RESOLVED', 'class' => 'bg-success', 'visible' => [1]],
    ['label' => 'PLG_HEALTHCHECK_FRONTENDERRORS_STATE_IGNORED', 'class' => 'bg-secondary', 'visible' => [2]],
    ['label' => 'PLG_HEALTHCHECK_FRONTENDERRORS_BADGE_REGRESSED', 'class' => 'bg-danger', 'visible' => [3]],
];
$buttons = [
    ['label' => 'PLG_HEALTHCHECK_FRONTENDERRORS_ACTION_RESOLVE', 'class' => 'btn-outline-success', 'state' => 1, 'visible' => [0, 3]],
    ['label' => 'PLG_HEALTHCHECK_FRONTENDERRORS_ACTION_IGNORE', 'class' => 'btn-outline-secondary', 'state' => 2, 'visible' => [0, 1, 3]],
    ['label' => 'PLG_HEALTHCHECK_FRONTENDERRORS_ACTION_REOPEN', 'class' => 'btn-outline-primary', 'state' => 0, 'visible' => [1, 2]],
];
$hidden = static fn (array $visible, int $state): string => \in_array($state, $visible, true) ? '' : ' hidden';
$date   = static fn (string $value): string => $value === '' ? '' : (string) HTMLHelper::_('date', $value, Text::_('DATE_FORMAT_LC5'));

$rows = [];

foreach ($errors as $error) {
    $location = (string) $error['file'];

    if ($location !== '' && (int) $error['line'] > 0) {
        $location .= ':' . (int) $error['line'] . ((int) $error['col'] > 0 ? ':' . (int) $error['col'] : '');
    }

    $source = $error['source_extension'];

    if ($source === null || $source === '') {
        $source = Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_SOURCE_UNKNOWN');
    } elseif ($source === 'core') {
        $source = Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_SOURCE_CORE');
    } elseif (str_starts_with($source, 'external:')) {
        $source = Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_SOURCE_EXTERNAL', substr($source, 9));
    }

    $origins = $error['origins'] ?? [];
    $visitor = \in_array('visitor', $origins, true);
    $scan    = \in_array('scan', $origins, true);

    $rows[] = [
        'id'         => (int) $error['id'],
        'state'      => (int) $error['state'],
        'type'       => $error['type'],
        'message'    => $error['message'],
        'stack'      => (string) $error['stack'],
        'location'   => $location,
        'source'     => $source,
        'seen'       => Text::plural('PLG_HEALTHCHECK_FRONTENDERRORS_N_TIMES', (int) $error['hits']),
        'first_seen' => $date((string) $error['first_seen']),
        'last_seen'  => $date((string) $error['last_seen']),
        'origin'     => match (true) {
            $visitor && $scan => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_ORIGIN_BOTH'),
            $scan             => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_ORIGIN_SCAN'),
            default           => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_ORIGIN_VISITORS'),
        },
    ];
}
?>
<?php if (!$errors) : ?>
    <p class="p-3 mb-0"><?php echo $e(Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_DETAILS_NONE', $periodDays)); ?></p>
    <?php return; ?>
<?php endif; ?>
<div class="table-responsive p-2">
    <table class="table table-sm align-middle plg-hc-fe-details-table">
        <caption class="visually-hidden"><?php echo $e(Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_DETAILS_CAPTION', $periodDays)); ?></caption>
        <thead>
            <tr>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_TYPE')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_MESSAGE')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_LOCATION')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_SOURCE')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_SEEN')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_FIRST_SEEN')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_LAST_SEEN')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_ORIGIN')); ?></th>
                <th scope="col"><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_ACTIONS')); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
            <tr data-hc-fe-row="<?php echo $row['id']; ?>" data-hc-fe-current="<?php echo $row['state']; ?>">
                <td><span class="badge bg-info text-dark"><?php echo $e($row['type']); ?></span></td>
                <td class="text-break">
                    <?php echo $e($row['message']); ?>
                    <?php foreach ($badges as $badge) : ?>
                        <span class="badge <?php echo $badge['class']; ?>" data-hc-fe-visible="<?php echo implode(' ', $badge['visible']); ?>"<?php echo $hidden($badge['visible'], $row['state']); ?>><?php echo $e(Text::_($badge['label'])); ?></span>
                    <?php endforeach; ?>
                    <?php if ($row['stack'] !== '') : ?>
                        <details class="mt-1">
                            <summary><?php echo $e(Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_STACK')); ?></summary>
                            <pre class="small mb-0"><?php echo $e($row['stack']); ?></pre>
                        </details>
                    <?php endif; ?>
                </td>
                <td class="text-break"><?php echo $e($row['location']); ?></td>
                <td><?php echo $e($row['source']); ?></td>
                <td><?php echo $e($row['seen']); ?></td>
                <td><?php echo $e($row['first_seen']); ?></td>
                <td><?php echo $e($row['last_seen']); ?></td>
                <td><?php echo $e($row['origin']); ?></td>
                <td class="text-nowrap">
                    <?php foreach ($buttons as $button) : ?>
                        <button type="button" class="btn btn-sm <?php echo $button['class']; ?>" data-hc-fe-id="<?php echo $row['id']; ?>" data-hc-fe-state="<?php echo $button['state']; ?>" data-hc-fe-visible="<?php echo implode(' ', $button['visible']); ?>"<?php echo $hidden($button['visible'], $row['state']); ?>><?php echo $e(Text::_($button['label'])); ?></button>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
