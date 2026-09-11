<?php
/**
 * Sets the credit each course carries, and whether it appears on a transcript.
 *
 * The courses listed are those of the year groups a transcript covers, as
 * chosen on Transcript Setup, because those are the only courses a
 * transcript can print. Courses belong to one school year in Gibbon, so
 * credit is set per year and a change here never rewrites an earlier
 * year's record.
 *
 * A coloured row above the headings holds a filter on the course name and
 * on year group, and a credit to apply to every course the filter shows.
 * The all or none box beside the Show on Transcript heading does the same
 * for the tick boxes. Save at the foot writes every course listed.
 *
 * Switching a course off is how a school keeps its homerooms and its pastoral
 * courses off the printed transcript, while still listing a course such as
 * Theory of Knowledge that is studied but carries no credit.
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
use Gibbon\Module\AcademicRecords\Domain\CourseCreditGateway;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_courseCredits.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Course Credits'));
echo '<h2>' . __('Course Credits') . '</h2>';

$creditGateway = $container->get(CourseCreditGateway::class);
$transcriptGateway = $container->get(TranscriptGateway::class);
$settingGateway = $container->get(SettingGateway::class);

$gibbonSchoolYearID = $_GET['gibbonSchoolYearID'] ?? $session->get('gibbonSchoolYearID');
$page->navigator->addSchoolYearNavigation($gibbonSchoolYearID);

/* -----------------------------------------------------
   Year groups a transcript covers
----------------------------------------------------- */

$transcriptYearGroups = normalizeRequestList((string) ($settingGateway->getSettingByScope('Academic Records', 'transcriptYearGroups', true)['value'] ?? ''));

$yearGroupOptions = [];
foreach ($transcriptGateway->selectYearGroupOptions() as $yearGroup) {
    $id = (string) $yearGroup['gibbonYearGroupID'];

    if (empty($transcriptYearGroups) || in_array($id, $transcriptYearGroups, true)) {
        $yearGroupOptions[$id] = (string) $yearGroup['name'];
    }
}

if (empty($transcriptYearGroups)) {
    $setupURL = $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_transcriptSetup.php';

    echo Format::alert(
        __('No year groups are chosen on Transcript Setup yet, so every course in the school year is listed. Choose the year groups a transcript covers to shorten this list.')
        . ' <a href="' . htmlspecialchars($setupURL) . '">' . __('Open Transcript Setup') . '</a>',
        'warning'
    );
}

// A course belongs here if any of its year groups is one a transcript covers.
$courses = array_values(array_filter($creditGateway->selectCoursesBySchoolYear((string) $gibbonSchoolYearID), function ($course) use ($yearGroupOptions) {
    foreach (normalizeRequestList((string) ($course['gibbonYearGroupIDList'] ?? '')) as $id) {
        if (isset($yearGroupOptions[normalizeYearGroupID($id)])) {
            return true;
        }
    }

    return false;
}));

if (empty($courses)) {
    echo Format::alert(__('There are no courses in this school year for the year groups a transcript covers.'), 'error');
    return;
}

echo Format::alert(
    __('Credit is the amount one term of the course is worth. A course with no credit is still printed, but claims nothing, which suits a course that is studied and not graded. A course with Show on Transcript unticked never reaches a transcript.'),
    'message'
);

/* -----------------------------------------------------
   The table
----------------------------------------------------- */

$themeColour = $session->has('themeColour') ? $session->get('themeColour') : 'purple';

// The classes Gibbon gives the controls inside its bulk action panel.
$inputClass = 'rounded-md min-w-0 border py-2 px-2 placeholder:text-gray-500 sm:text-sm sm:leading-5 text-gray-900 focus:ring-1 focus:ring-inset focus:ring-blue-500';
$selectClass = 'rounded-md min-w-16 border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5';
$buttonClass = 'rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle items-center font-semibold shadow-sm border border-gray-800 bg-gray-800 hover:bg-gray-900 text-white';
$barCell = 'bg-' . htmlspecialchars($themeColour) . '-600 p-1 pt-2';

$form = Form::create(
    'courseCredits',
    $session->get('absoluteURL') . '/modules/Academic Records/academicRecords_courseCreditsProcess.php'
);
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);

// Separate borders, so the coloured row can round its top corners.
$html = '<table class="w-full colorOddEven" cellspacing="0" style="border-collapse: separate; border-spacing: 0;">';

$html .= '<tr>';
// The boxes are the same widths as the items in the rows beneath them.
$html .= '<td class="' . $barCell . ' rounded-tl-md"><input type="text" id="filterName" class="' . $inputClass . ' w-full" autocomplete="off" placeholder="' . __('Filter by course name') . '"/></td>';
$html .= '<td class="' . $barCell . '"><select id="filterYearGroup" class="' . $selectClass . ' w-32">';
$html .= '<option value="">' . __('All') . '</option>';
foreach ($yearGroupOptions as $id => $name) {
    $html .= '<option value="' . htmlspecialchars($id) . '">' . htmlspecialchars($name) . '</option>';
}
$html .= '</select></td>';
$html .= '<td class="' . $barCell . ' whitespace-nowrap"><div class="flex items-center gap-2">'
    . '<input type="number" id="applyCredit" class="' . $inputClass . ' w-24" min="0" max="99" step="0.25" autocomplete="off" placeholder="' . __('Credit Value') . '"/>'
    . '<button type="button" id="applyAll" class="' . $buttonClass . '">' . __('Apply to all') . '</button>'
    . '</div></td>';
$html .= '<td class="' . $barCell . ' rounded-tr-md"></td>';
$html .= '</tr>';

$html .= '<tr class="head">';
$html .= '<th>' . __('Course') . '</th>';
$html .= '<th>' . __('Year Groups') . '</th>';
$html .= '<th>' . __('Credit per Term') . '</th>';
$html .= '<th class="text-center whitespace-nowrap">' . __('Show on Transcript') . ' <input type="checkbox" id="showAll" class="ml-1 align-middle"/></th>';
$html .= '</tr>';

foreach ($courses as $course) {
    $courseID = htmlspecialchars((string) $course['gibbonCourseID']);
    $credit = $course['creditPerTerm'];
    $shown = (($course['showOnTranscript'] ?? 'Y') !== 'N');

    // The year group IDs ride on the row, so the filter can read them
    // without another request.
    $yearGroupIDs = array_map('normalizeYearGroupID', normalizeRequestList((string) ($course['gibbonYearGroupIDList'] ?? '')));

    $html .= '<tr class="courseRow" data-name="' . htmlspecialchars(mb_strtolower((string) $course['name'] . ' ' . (string) $course['nameShort'])) . '"'
        . ' data-yeargroups="' . htmlspecialchars(implode(',', $yearGroupIDs)) . '">';
    $html .= '<td>' . htmlspecialchars((string) $course['name'])
        . '<br/><span class="text-xxs italic">' . htmlspecialchars((string) $course['nameShort']) . '</span></td>';
    $html .= '<td>' . htmlspecialchars((string) ($course['yearGroups'] ?? '')) . '</td>';
    $html .= '<td><input type="number" class="w-24 rowCredit" min="0" max="99" step="0.25" autocomplete="off" placeholder="' . __('None') . '"'
        . ' name="creditPerTerm[' . $courseID . ']"'
        . ' value="' . htmlspecialchars($credit !== null ? rtrim(rtrim((string) $credit, '0'), '.') : '') . '"/></td>';
    // The hidden field means an unticked box still arrives, so a course can
    // be switched off rather than simply going missing from the request.
    $html .= '<td class="text-center"><input type="hidden" name="showOnTranscript[' . $courseID . ']" value="N"/>'
        . '<input type="checkbox" class="rowShow" name="showOnTranscript[' . $courseID . ']" value="Y"' . ($shown ? ' checked' : '') . '/></td>';
    $html .= '</tr>';
}

$html .= '</table>';

$form->addRow()->addContent($html);

$form->addRow()->addContent(
    '<span class="text-xs italic">'
    . __('The filter only changes what is shown. Apply to all writes the credit into every course the filter shows, exactly as entered, including a blank. The box beside Show on Transcript ticks or clears every course the filter shows. Save writes every course listed.')
    . '</span>'
);

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__('Save Course Credits'));

echo $form->getOutput();

?>
<script>
(function () {
    var form = document.getElementById('courseCredits');
    if (!form) return;

    var rows = Array.prototype.slice.call(form.querySelectorAll('tr.courseRow'));
    var nameBox = document.getElementById('filterName');
    var yearBox = document.getElementById('filterYearGroup');

    var visibleRows = function () {
        return rows.filter(function (row) { return row.style.display !== 'none'; });
    };

    // Both filters apply together. A course shows if its name contains the
    // text and, when a year group is chosen, it sits in that year group.
    var applyFilter = function () {
        var text = (nameBox.value || '').toLowerCase().trim();
        var year = yearBox.value;

        rows.forEach(function (row) {
            var nameOK = text === '' || (row.getAttribute('data-name') || '').indexOf(text) !== -1;
            var yearOK = year === '' || (row.getAttribute('data-yeargroups') || '').split(',').indexOf(year) !== -1;
            row.style.display = (nameOK && yearOK) ? '' : 'none';
        });
    };

    nameBox.addEventListener('input', applyFilter);
    yearBox.addEventListener('change', applyFilter);

    document.getElementById('applyAll').addEventListener('click', function () {
        var credit = document.getElementById('applyCredit').value;
        visibleRows().forEach(function (row) {
            var box = row.querySelector('input.rowCredit');
            if (box) box.value = credit;
        });
    });

    var showAll = document.getElementById('showAll');
    showAll.addEventListener('change', function () {
        visibleRows().forEach(function (row) {
            var box = row.querySelector('input.rowShow');
            if (box) box.checked = showAll.checked;
        });
    });
})();
</script>
