<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\Module\Healthcheck\Administrator\Helper\HealthCheckHelper;

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $app->getDocument()->getWebAssetManager();
$wa->useScript('core')
    ->useScript('bootstrap.dropdown');

/*
 * Register and use the filter and async assets. The async script fills any quick-icon carrying a
 * data-url from com_ajax; mod_quickicon's script is intentionally not used here as it expects a
 * different response shape and would render "undefined" for these icons.
 */
$wa->registerAndUseScript('mod_healthcheck.filter', 'mod_healthcheck/healthcheck-filter.js', [], ['defer' => true])
    ->registerAndUseScript('mod_healthcheck.async', 'mod_healthcheck/healthcheck-async.js', [], ['defer' => true])
    ->registerAndUseScript('mod_healthcheck.onclick', 'mod_healthcheck/healthcheck-onclick.js', [], ['defer' => true])
    ->registerAndUseStyle('mod_healthcheck.general', 'mod_healthcheck/healthcheck.css');

// Label for the close button of the code modal opened by the async script.
Text::script('JCLOSE');

$leading_html = HTMLHelper::_('healthchecks.leadings', $leading);
$footer_html  = HTMLHelper::_('healthchecks.footers', $footer);

// One panel per plugin section. Each item is rendered once; items the user cannot access render as ''
// and are left out of both the body and the header summary. Panels with nothing left are skipped.
$panels = [];
$types  = ['gauges' => ['gauge', 'nav'], 'buttons' => ['button', 'nav'], 'lists' => ['list', 'mt-3'], 'tables' => ['table', 'div']];

foreach (HealthCheckHelper::groupBySection($gauges, $buttons, $lists, $tables, Text::_('MOD_HEALTHCHECK_SECTION_OTHER')) as $section) {
    $kept = ['title' => $section['title'], 'gauges' => [], 'buttons' => [], 'lists' => [], 'tables' => []];
    $body = '';

    foreach ($types as $type => [$helper, $wrapper]) {
        $html = '';

        foreach ($section[$type] as $item) {
            $itemHtml = HTMLHelper::_('healthchecks.' . $helper, $item);

            if ($itemHtml !== '') {
                $kept[$type][] = $item;
                $html         .= $itemHtml;
            }
        }

        if ($html === '') {
            continue;
        }

        $body .= match ($wrapper) {
            'nav'   => '<ul class="nav flex-wrap">' . $html . '</ul>',
            'div'   => '<div class="mt-3">' . $html . '</div>',
            default => '<ul class="mt-3">' . $html . '</ul>',
        };
    }

    if ($body !== '') {
        $panels[] = ['section' => $kept, 'body' => $body];
    }
}

$has_data    = $panels !== [];
$accordionId = HealthCheckHelper::accordionId((int) $module->id);
?>
<?php if ($has_data) : ?>
    <!-- Filter Buttons -->
    <div class="healthcheck-filters p-3 d-flex align-items-flex-start justify-content-between">
        <div class="btn-group btn-group-sm" role="group" aria-label="<?php echo Text::_('MOD_HEALTHCHECK_FILTER_LABEL'); ?>">
            <button type="button" class="btn btn-outline-primary active healthcheck-filter-btn" data-filter="all">
                <?php echo Text::_('MOD_HEALTHCHECK_FILTER_ALL'); ?>
            </button>
            <button type="button" class="btn btn-outline-success healthcheck-filter-btn" data-filter="healthy">
                <?php echo Text::_('MOD_HEALTHCHECK_FILTER_HEALTHY'); ?>
            </button>
            <button type="button" class="btn btn-outline-warning healthcheck-filter-btn" data-filter="warning">
                <?php echo Text::_('MOD_HEALTHCHECK_FILTER_WARNING'); ?>
            </button>
            <button type="button" class="btn btn-outline-danger healthcheck-filter-btn" data-filter="critical">
                <?php echo Text::_('MOD_HEALTHCHECK_FILTER_CRITICAL'); ?>
            </button>
        </div>
        <button type="button" class="btn btn-sm btn-primary">
            <span class="icon-refresh" aria-hidden="true"></span>
            <?php echo Text::_('MOD_HEALTHCHECK_REFRESH'); ?>
        </button>
    </div>
<?php endif; ?>
<?php if (!empty($leading_html)) : ?>
    <div class="health-checks module-leadingtext px-3 pb-3">
        <?php echo $leading_html; ?>
    </div>
<?php endif; ?>
<nav class="quick-icons health-checks px-3 pb-3" aria-label="<?php echo Text::_('MOD_HEALTHCHECK_NAV_LABEL') . ' ' . htmlspecialchars($module->title, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($has_data) : ?>
        <?php echo HTMLHelper::_('bootstrap.startAccordion', $accordionId, ['parent' => false]); ?>
        <?php foreach ($panels as $index => $panel) : ?>
            <?php echo HTMLHelper::_(
                'bootstrap.addSlide',
                $accordionId,
                LayoutHelper::render('joomla.healthchecks.section_header', ['title' => $panel['section']['title'], 'summary' => HealthCheckHelper::summarise($panel['section'])]),
                $accordionId . '-' . $index,
                'healthcheck-section'
            ); ?>
            <?php echo $panel['body']; ?>
            <?php echo HTMLHelper::_('bootstrap.endSlide'); ?>
        <?php endforeach; ?>
        <?php echo HTMLHelper::_('bootstrap.endAccordion'); ?>
    <?php else : ?>
        <?php echo LayoutHelper::render('joomla.content.emptystate_module', [
            'textPrefix' => 'MOD_HEALTHCHECK',
            'icon'       => 'fa fa-heart-pulse',
            'title'      => 'MOD_HEALTHCHECK_NO_MATCHING_RESULTS'
        ]); ?>
    <?php endif; ?>
</nav>
<?php if (!empty($footer_html)) : ?>
    <div class="health-checks module-footertext px-3 pb-3">
        <?php echo $footer_html; ?>
    </div>
<?php endif; ?>
