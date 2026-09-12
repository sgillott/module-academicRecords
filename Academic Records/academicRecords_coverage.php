<?php
/**
 * Shows which classes have a stored grade for which term of a school year.
 *
 * This is the check to make before a transcript run: every class that a
 * transcript can print, with a cell for each term saying whether a grade
 * has been stored for it, for how many of the students, and when. A gap
 * in a term that has started is a class Store Grades has not reached.
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
use Gibbon\Module\AcademicRecords\Domain\StoredGradeGateway;
use Gibbon\Module\AcademicRecords\Domain\YearGroupMapGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_coverage.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Stored Grade Coverage'));
$page->stylesheets->add('module-academicRecords', 'modules/Academic Records/css/module.css');

$settingGateway = $container->get(SettingGateway::class);
$storedGradeGateway = $container->get(StoredGradeGateway::class);

$gibbonSchoolYearID = (string) ($_GET['gibbonSchoolYearID'] ?? $session->get('gibbonSchoolYearID'));
$page->navigator->addSchoolYearNavigation($gibbonSchoolYearID);

echo '<h2>' . __('Stored Grade Coverage') . '</h2>';

$internalAssessmentType = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType', true)['value'] ?? ''));

if ($internalAssessmentType === '') {
    echo academicRecordsSetupWarning(
        __('No Internal Assessment Type is set yet. Set it in Academic Records Settings first, so this page knows which columns this module owns.'),
        __('Go to Academic Records Settings'),
        $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_settings.php'
    );
    return;
}

/* -----------------------------------------------------
   Filters
----------------------------------------------------- */

$yearGroupOptions = [];
foreach ($container->get(YearGroupMapGateway::class)->selectYearGroups() as $yearGroup) {
    $yearGroupOptions[(string) $yearGroup['gibbonYearGroupID']] = (string) $yearGroup['name'];
}

$gibbonYearGroupID = normalizeYearGroupID($_GET['gibbonYearGroupID'] ?? '');
$transcriptOnly = ($_GET['transcriptOnly'] ?? 'Y') !== 'N';

$form = Form::create('coverageFilters', $session->get('absoluteURL') . '/index.php', 'get');
$form->setTitle(__('Filter'));
$form->addHiddenValue('q', '/modules/Academic Records/academicRecords_coverage.php');
$form->addHiddenValue('gibbonSchoolYearID', $gibbonSchoolYearID);

$row = $form->addRow();
$row->addLabel('gibbonYearGroupID', __('Year Group'));
$row->addSelect('gibbonYearGroupID')
    ->fromArray($yearGroupOptions)
    ->selected($gibbonYearGroupID)
    ->placeholder(__('All'));

$row = $form->addRow();
$row->addLabel('transcriptOnly', __('Only courses on transcripts'))
    ->description(__('A course switched off on the Course Credits page never reaches a transcript, so a gap in it does not matter.'));
$row->addYesNo('transcriptOnly')
    ->selected($transcriptOnly ? 'Y' : 'N');

$row = $form->addRow();
$row->addSearchSubmit($session, __('Clear'), ['gibbonSchoolYearID']);

echo $form->getOutput();

/* -----------------------------------------------------
   The matrix
----------------------------------------------------- */

$terms = $storedGradeGateway->selectTermsBySchoolYear($gibbonSchoolYearID);

if (empty($terms)) {
    echo Format::alert(__('This school year has no terms, so there is nothing to place a stored grade against.'), 'warning');
    return;
}

$coverage = $storedGradeGateway->selectCoverageKeyed($gibbonSchoolYearID, $internalAssessmentType);
$today = date('Y-m-d');

$classes = array_filter($storedGradeGateway->selectClassesBySchoolYear($gibbonSchoolYearID), function ($class) use ($gibbonYearGroupID, $transcriptOnly) {
    if ($transcriptOnly && ($class['showOnTranscript'] ?? 'Y') === 'N') {
        return false;
    }

    if ($gibbonYearGroupID === '') {
        return true;
    }

    return in_array($gibbonYearGroupID, array_map('normalizeYearGroupID', normalizeRequestList((string) ($class['gibbonYearGroupIDList'] ?? ''))), true);
});

if (empty($classes)) {
    echo Format::alert(__('There are no classes with students in this school year for the chosen filters.'), 'warning');
    return;
}

// A term counts once it has started. A gap in a term that has not begun is
// not a gap yet, and a term that has ended is judged the same as a running
// one, because a grade can be stored at any time after the fact.
$startedTermIDs = [];
foreach ($terms as $term) {
    if ((string) ($term['firstDay'] ?? '') !== '' && (string) $term['firstDay'] <= $today) {
        $startedTermIDs[(string) $term['gibbonSchoolYearTermID']] = true;
    }
}

$complete = 0;
$withGaps = 0;
$rowsHtml = '';

foreach ($classes as $class) {
    $classID = (string) $class['gibbonCourseClassID'];
    $students = (int) $class['students'];
    $hasGap = false;
    $cells = '';

    foreach ($terms as $term) {
        $termID = (string) $term['gibbonSchoolYearTermID'];
        $stored = $coverage[$classID][$termID] ?? null;

        if ($stored !== null) {
            $graded = (int) $stored['graded'];
            $state = $graded >= $students ? 'coverage-stored' : 'coverage-partial';
            $completed = (string) ($stored['completeDate'] ?? '');

            $cells .= '<td class="' . $state . '">'
                . $graded . ' / ' . $students
                . '<span class="coverage-detail">' . ($completed !== '' ? Format::date($completed) : '') . '</span>'
                . '</td>';

            if ($graded < $students) {
                $hasGap = true;
            }
        } elseif (isset($startedTermIDs[$termID])) {
            $cells .= '<td class="coverage-missing">' . __('Not stored') . '</td>';
            $hasGap = true;
        } else {
            $cells .= '<td class="coverage-future">' . __('Not started') . '</td>';
        }
    }

    if ($hasGap) {
        $withGaps++;
    } else {
        $complete++;
    }

    $rowsHtml .= '<tr' . (($class['showOnTranscript'] ?? 'Y') === 'N' ? ' class="coverage-hidden"' : '') . '>';
    $rowsHtml .= '<td>' . htmlspecialchars((string) $class['courseName'])
        . '<span class="coverage-detail">' . htmlspecialchars((string) $class['courseNameShort'] . '.' . (string) $class['classNameShort'])
        . (($class['showOnTranscript'] ?? 'Y') === 'N' ? ' &middot; ' . __('Not on transcript') : '')
        . '</span></td>';
    $rowsHtml .= '<td class="text-center">' . $students . '</td>';
    $rowsHtml .= $cells;
    $rowsHtml .= '</tr>';
}

echo Format::alert(
    __('{total} classes. {complete} have a grade stored for every student in every term that has started. {gaps} have a gap.', [
        'total' => count($classes),
        'complete' => $complete,
        'gaps' => $withGaps,
    ]),
    $withGaps > 0 ? 'warning' : 'success'
);

$html = '<table class="w-full colorOddEven coverage-table" cellspacing="0">';
$html .= '<tr class="head">';
$html .= '<th>' . __('Class') . '</th>';
$html .= '<th class="text-center">' . __('Students') . '</th>';
foreach ($terms as $term) {
    $html .= '<th class="text-center">' . htmlspecialchars((string) $term['name']) . '</th>';
}
$html .= '</tr>';
$html .= $rowsHtml;
$html .= '</table>';

echo $html;

echo '<p class="text-xs italic mt-2">'
    . __('Each cell shows how many of the students in the class have a stored grade for that term, and the date the stored column was completed. A class whose students changed after the store shows fewer graded than enrolled.')
    . '</p>';
