<?php
/**
 * Maps Gibbon year groups to the year values GL Assessment expect.
 *
 * The region rule only suggests a value. Whatever is confirmed here is what
 * the student export sends, so an international school running a different
 * year scale can correct every row by hand.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\YearGroupMapGateway;
use Gibbon\Module\AcademicRecords\Testwise\Year;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_testwiseYearGroups.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Testwise Year Groups'));
echo '<h2>' . __('Testwise Year Groups') . '</h2>';

$settingGateway = $container->get(SettingGateway::class);
$mapGateway = $container->get(YearGroupMapGateway::class);

$settingsURL = $session->get('absoluteURL')
    . '/index.php?q=/modules/Academic Records/academicRecords_settings.php';

$region = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'testwiseRegion', true)['value'] ?? ''));

if ($region === '') {
    echo Format::alert(
        __('No Testwise Region is set yet. Set the region in Academic Records Settings first, so this page can suggest the right prefix for each year group.'),
        'error'
    );
    echo '<p><a href="' . htmlspecialchars($settingsURL) . '">' . __('Open Academic Records Settings') . '</a></p>';
    return;
}

$yearGroups = $mapGateway->selectYearGroups();

if (empty($yearGroups)) {
    echo Format::alert(__('No year groups were found in Gibbon.'), 'error');
    return;
}

$storedMap = $mapGateway->selectMapKeyed();

$regionNames = Year::regionOptions();
$regionLabel = $regionNames[$region] ?? $region;

echo Format::alert(
    __('The region is {region}. Check every row before you save. ', ['region' => '<b>' . htmlspecialchars($regionLabel) . '</b>']),
    'message'
);

$form = Form::create(
    'testwiseYearGroups',
    $session->get('absoluteURL') . '/modules/Academic Records/academicRecords_testwiseYearGroupsProcess.php'
);
$form->addHiddenValue('address', $session->get('address'));

$suggestions = Year::suggestYearMap($region, $yearGroups);

$unconfirmed = 0;
$html = '<table class="w-full colorOddEven" cellspacing="0">';
$html .= '<tr class="head">';
$html .= '<th>' . __('Year Group') . '</th>';
$html .= '<th>' . __('Short Name') . '</th>';
$html .= '<th>' . __('Suggested') . '</th>';
$html .= '<th>' . __('Export As') . '</th>';
$html .= '</tr>';

foreach ($yearGroups as $yearGroup) {
    $yearGroupID = (string) $yearGroup['gibbonYearGroupID'];
    $name = (string) $yearGroup['name'];
    $nameShort = (string) $yearGroup['nameShort'];

    $suggested = $suggestions[$yearGroupID] ?? null;
    $stored = $storedMap[$yearGroupID] ?? null;
    $confirmed = $stored !== null;

    if (!$confirmed) {
        $unconfirmed++;
    }

    $value = $stored ?? ($suggested ?? '');
    $rowClass = $confirmed ? '' : ' style="background-color: #fdf6e3;"';

    $html .= '<tr' . $rowClass . '>';
    $html .= '<td>' . htmlspecialchars($name);

    if (!$confirmed) {
        $html .= '<br/><span class="text-xxs italic">' . __('Not confirmed yet') . '</span>';
    }

    $html .= '</td>';
    $html .= '<td>' . htmlspecialchars($nameShort) . '</td>';
    $html .= '<td>' . ($suggested !== null ? htmlspecialchars($suggested) : '<span class="text-xxs italic">' . __('No number in the name') . '</span>') . '</td>';
    // autocomplete is off because a browser restoring its own remembered values
    // on a reload would quietly replace the suggestions shown here.
    $html .= '<td><input type="text" class="w-24 uppercase" maxlength="4" autocomplete="off" name="testwiseYear[' . htmlspecialchars($yearGroupID) . ']" value="' . htmlspecialchars($value) . '"/></td>';
    $html .= '</tr>';
}

$html .= '</table>';

$form->addRow()->addContent($html);

if ($unconfirmed > 0) {
    $form->addRow()->addContent(
        Format::alert(
            __('{count} year groups have not been confirmed yet. Check their values and save to confirm them.', ['count' => $unconfirmed]),
            'warning'
        )
    );
}

$form->addRow()->addContent(
    '<span class="text-xs italic">'
    . __('Each value must be a P, S or Y followed by a number, such as Y7, P4 or S3. Leave a value empty to clear it and go back to the suggestion.')
    . '</span>'
);

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__('Save Year Groups'));

echo $form->getOutput();
