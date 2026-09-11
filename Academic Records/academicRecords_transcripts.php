<?php
/**
 * Generates transcripts for students, using a Gibbon Reports template.
 *
 * The page only chooses who gets a transcript. The layout, the archive and
 * the distribution all stay with the Reports module, so a transcript appears
 * in Reports > Archive alongside every other report once it is generated.
 *
 * Choose who to work with first: tick year groups, and move students across
 * to narrow it to some of them. Leave the students empty to take everyone in
 * the ticked year groups. The list below shows the result. A coloured row
 * above the headings holds a Class of and a Graduation Date to apply to all,
 * an all or none switch for GPA, and Download all. Every row has its own
 * values and a Print box, and Generate at the foot saves and runs.
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
use Gibbon\Tables\Action;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_transcripts.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Generate Transcripts'));
$page->stylesheets->add('module-academicRecords', 'modules/Academic Records/css/module.css');

echo '<h2>' . __('Generate Transcripts') . '</h2>';

$transcriptGateway = $container->get(TranscriptGateway::class);
$studentGateway = $container->get(StudentGateway::class);

$gibbonSchoolYearID = $session->get('gibbonSchoolYearID');

/* -----------------------------------------------------
   Reports available
----------------------------------------------------- */

$reports = $transcriptGateway->selectTranscriptReports((string) $gibbonSchoolYearID);

if (empty($reports)) {
    $reportsURL = $session->get('absoluteURL') . '/index.php?q=/modules/Reports/reports_manage.php';

    echo Format::alert(
        __('There are no transcript reports for this school year yet. A transcript is defined in the Reports module, as an active report using a template built on the Student Enrolment context.'),
        'warning'
    );
    echo '<p><a href="' . htmlspecialchars($reportsURL) . '">' . __('Open Reports, Manage Reports') . '</a></p>';
    return;
}

$reportOptions = [];
foreach ($reports as $report) {
    $reportOptions[(string) $report['gibbonReportID']] = (string) $report['name'];
}

$gibbonReportID = (string) ($_GET['gibbonReportID'] ?? array_key_first($reportOptions));

if (!isset($reportOptions[$gibbonReportID])) {
    $gibbonReportID = (string) array_key_first($reportOptions);
}

/* -----------------------------------------------------
   Selection
----------------------------------------------------- */

$allYearGroups = [];
foreach ($transcriptGateway->selectYearGroupOptions() as $yearGroup) {
    $allYearGroups[(string) $yearGroup['gibbonYearGroupID']] = (string) $yearGroup['name'];
}

$yearGroupIDs = array_values(array_filter(normalizeRequestList($_GET['gibbonYearGroupIDList'] ?? []), function ($id) use ($allYearGroups) {
    return isset($allYearGroups[(string) $id]);
}));
$studentIDs = normalizeRequestList($_GET['studentIDs'] ?? []);

$students = $studentGateway
    ->queryStudentsBySchoolYear($studentGateway->newQueryCriteria()->sortBy(['surname', 'preferredName']), $gibbonSchoolYearID)
    ->toArray();

// With no year group ticked the picker offers the whole school, so a single
// student can be found without knowing their year group first.
$selectionYearGroups = !empty($yearGroupIDs) ? $yearGroupIDs : array_keys($allYearGroups);
$studentSelection = buildStudentSelectionData($students, $selectionYearGroups, $studentIDs);

$action = $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_transcripts.php';

$form = Form::create('transcriptFilters', $action, 'get');
$form->addHiddenValue('q', '/modules/Academic Records/academicRecords_transcripts.php');

$row = $form->addRow();
$row->addLabel('gibbonReportID', __('Report'))
    ->description(__('The transcript definition, from the Reports module.'));
$row->addSelect('gibbonReportID')
    ->fromArray($reportOptions)
    ->selected($gibbonReportID)
    ->required();

$row = $form->addRow();
$row->addLabel('gibbonYearGroupIDList', __('Year Groups'))
    ->description(__('Tick a year group to cover every student in it. The student list refreshes as you tick.'));
$row->addCheckbox('gibbonYearGroupIDList[]')
    ->fromArray($allYearGroups)
    ->checked($yearGroupIDs)
    ->addCheckAllNone();

$col = $form->addRow()->addColumn();
$col->addLabel('studentIDs', __('Students'))
    ->description(__('Move students across to cover only those. Leave this empty to cover every student in the ticked year groups.'));

$multi = $col->addMultiSelect('studentIDs');
$multi->addSortableAttribute(__('Form Group'), $studentSelection['formGroups']);
$multi->source()->fromArray($studentSelection['source']);
$multi->destination()->fromArray($studentSelection['destination']);

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__('Choose Students'));

echo $form->getOutput();

// A change of year group reloads the page, so the student picker is rebuilt
// for the new year groups. Gibbon's picker only gathers its chosen students
// when a submit button is clicked, so they are gathered here first, and any
// still in scope survive the reload.
?>
<script>
(function () {
    var form = document.getElementById('transcriptFilters');
    if (!form) return;

    var reload = function () {
        var chosen = document.getElementById('studentIDs');
        if (chosen) {
            Array.prototype.forEach.call(chosen.options, function (option) { option.selected = true; });
        }
        form.submit();
    };

    form.querySelectorAll('input[name="gibbonYearGroupIDList[]"]').forEach(function (box) {
        box.addEventListener('change', reload);
    });
})();
</script>
<?php

/* -----------------------------------------------------
   Who the run covers
----------------------------------------------------- */

if (!empty($studentIDs)) {
    $chosen = array_map('strval', $studentIDs);
} elseif (!empty($yearGroupIDs)) {
    $chosen = [];
    foreach ($students as $student) {
        if (in_array((string) $student['gibbonYearGroupID'], $yearGroupIDs, true)) {
            $chosen[] = (string) $student['gibbonPersonID'];
        }
    }
} else {
    $chosen = [];
}

if (empty($chosen)) {
    echo Format::alert(
        __('Tick a year group, or move students across, and then choose students.'),
        'message'
    );
    return;
}

$report = $transcriptGateway->getReportForGeneration($gibbonReportID);

if (empty($report)) {
    echo Format::alert(__('The chosen report is missing its template or its archive.'), 'error');
    return;
}

$studentsByID = [];
foreach ($students as $student) {
    $studentsByID[(string) $student['gibbonPersonID']] = $student;
}

$enrolments = $transcriptGateway->selectEnrolmentsKeyed($chosen, (string) $report['gibbonSchoolYearID']);
$overrides = $transcriptGateway->selectStudentValuesKeyed($chosen);
$archives = $transcriptGateway->selectArchiveEntriesKeyed($gibbonReportID, $chosen);

$rows = [];
foreach ($chosen as $personID) {
    if (!isset($studentsByID[$personID])) {
        continue;
    }

    $rows[] = $studentsByID[$personID] + [
        'personID' => $personID,
        'hasEnrolment' => isset($enrolments[$personID]),
        'override' => $overrides[$personID] ?? [],
        'archive' => $archives[$personID] ?? [],
    ];
}



/* -----------------------------------------------------
   Students in this run

   The table is built by hand, so the coloured row can sit above the column
   headings with each of its controls over the column it acts on. The row
   and its controls take their classes from Gibbon's own bulk action panel,
   so they look the same as the one on the class enrolment page.
----------------------------------------------------- */

$themeColour = $session->has('themeColour') ? $session->get('themeColour') : 'purple';
$processURL = $session->get('absoluteURL') . '/modules/Academic Records/academicRecords_transcriptsProcess.php';
$downloadURL = $session->get('absoluteURL') . '/modules/Academic Records/academicRecords_transcriptsDownload.php';

// The classes Gibbon gives the controls inside its bulk action panel.
$inputClass = 'rounded-md min-w-0 border py-2 px-2 placeholder:text-gray-500 sm:text-sm sm:leading-5 text-gray-900 focus:ring-1 focus:ring-inset focus:ring-blue-500';
$buttonClass = 'rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle items-center font-semibold shadow-sm border border-gray-800 bg-gray-800 hover:bg-gray-900 text-white';
$barCell = 'bg-' . htmlspecialchars($themeColour) . '-600 p-1 pt-2';

$generateForm = Form::create('transcriptGenerate', $processURL);
$generateForm->setTitle(__('Students in this run'));
$generateForm->addHiddenValue('address', $session->get('address'));
$generateForm->addHiddenValue('gibbonReportID', $gibbonReportID);
$generateForm->addHiddenValue('gibbonYearGroupIDList', implode(',', $yearGroupIDs));
$generateForm->addHiddenValue('studentIDs', implode(',', $studentIDs));

$hasAnyArchive = false;

// Separate borders, so the coloured row can round its top corners.
$html = '<table class="w-full colorOddEven" cellspacing="0" style="border-collapse: separate; border-spacing: 0;">';

// The boxes are the same widths as the ones in the rows beneath them. The
// date cell spans Graduation Date, GPA and Last Generated, so the row does
// not force those columns apart on a narrow screen.
$html .= '<tr>';
$html .= '<td colspan="2" class="' . $barCell . ' rounded-tl-md"></td>';
$html .= '<td class="' . $barCell . '"><input type="number" id="applyGraduationYear" class="' . $inputClass . ' w-24" min="1900" max="2200" step="1" autocomplete="off" placeholder="' . __('Class of') . '"/></td>';
$html .= '<td colspan="3" class="' . $barCell . '"><div class="flex items-center gap-2">'
    . '<input type="date" id="applyGraduationDate" class="' . $inputClass . ' w-40" autocomplete="off"/>'
    . '<button type="button" id="applyAll" class="' . $buttonClass . '">' . __('Apply to all') . '</button>'
    . '</div></td>';
$html .= '<td class="' . $barCell . '"><button type="submit" formaction="' . htmlspecialchars($downloadURL) . '" id="downloadAll" class="' . $buttonClass . ' inline-flex items-center gap-1">'
    . icon('solid', 'download', 'size-5') . __('Download all') . '</button></td>';
$html .= '<td class="' . $barCell . ' rounded-tr-md"></td>';
$html .= '</tr>';

$html .= '<tr class="head">';
$html .= '<th>' . __('Student') . '</th>';
$html .= '<th>' . __('Form Group') . '</th>';
$html .= '<th>' . __('Class of') . '</th>';
$html .= '<th>' . __('Graduation Date') . '</th>';
$html .= '<th class="text-center whitespace-nowrap">' . __('GPA') . ' <input type="checkbox" id="gpaAll" class="ml-1 align-middle"/></th>';
$html .= '<th>' . __('Last Generated') . '</th>';
$html .= '<th>' . __('Action') . '</th>';
$html .= '<th class="text-center whitespace-nowrap">' . __('Print') . ' <input type="checkbox" id="printAll" class="ml-1 align-middle" checked/></th>';
$html .= '</tr>';

foreach ($rows as $row) {
    $personID = htmlspecialchars($row['personID']);
    $archive = $row['archive'];

    $html .= '<tr' . ($row['hasEnrolment'] ? '' : ' class="error"') . '>';

    $html .= '<td>' . Format::name('', (string) $row['preferredName'], (string) $row['surname'], 'Student', true);
    if (!$row['hasEnrolment']) {
        $html .= '<br/><span class="text-xxs italic">' . __('No enrolment in the report school year, so this student is skipped') . '</span>';
    }
    $html .= '</td>';

    $html .= '<td>' . htmlspecialchars((string) ($row['formGroup'] ?? '')) . '</td>';

    // Both values are worked out when the transcript is built. A value here
    // overrides that, for a student who repeats, skips or leaves early, and
    // is saved when the run starts. The date field is the browser's own.
    $html .= '<td><input type="number" class="w-24 rowGraduationYear" min="1900" max="2200" step="1" autocomplete="off" placeholder="' . __('Auto') . '"'
        . ' name="graduationYear[' . $personID . ']"'
        . ' value="' . htmlspecialchars((string) ($row['override']['graduationYear'] ?? '')) . '"/></td>';
    $html .= '<td><input type="date" class="w-40 rowGraduationDate" autocomplete="off"'
        . ' name="graduationDate[' . $personID . ']"'
        . ' value="' . htmlspecialchars((string) ($row['override']['graduationDate'] ?? '')) . '"/></td>';

    // The hidden field means an unticked box still arrives, so GPA can be
    // switched off again.
    $html .= '<td class="text-center"><input type="hidden" name="showGPA[' . $personID . ']" value="N"/>'
        . '<input type="checkbox" class="rowGpa" name="showGPA[' . $personID . ']" value="Y"'
        . ((($row['override']['showGPA'] ?? 'N') === 'Y') ? ' checked' : '') . '/></td>';

    $html .= '<td>';
    if (!empty($archive)) {
        $html .= Format::dateTimeReadable((string) $archive['timestampModified'])
            . ' <span class="tag ml-2 ' . ($archive['status'] === 'Final' ? 'success' : 'dull') . '">' . __((string) $archive['status']) . '</span>';
    }
    $html .= '</td>';

    // The same action buttons a Gibbon table would draw. View and download
    // go through the Reports archive, so its access rules apply.
    $html .= '<td>';
    if (!empty($archive)) {
        $hasAnyArchive = true;

        // Icon only. The label becomes the hover title.
        $view = (new Action('view', __('View')))
            ->directLink()
            ->displayLabel(false)
            ->addParam('action', 'view')
            ->addParam('gibbonPersonID', $row['personID'])
            ->addParam('gibbonReportArchiveEntryID', $archive['gibbonReportArchiveEntryID'])
            ->setURL('/modules/Reports/archive_byStudent_download.php');

        $download = (new Action('download', __('Download')))
            ->directLink()
            ->displayLabel(false)
            ->setIcon('download')
            ->addParam('gibbonPersonID', $row['personID'])
            ->addParam('gibbonReportArchiveEntryID', $archive['gibbonReportArchiveEntryID'])
            ->setURL('/modules/Reports/archive_byStudent_download.php');

        $html .= '<div class="flex gap-2">' . $view->getOutput() . $download->getOutput() . '</div>';
    }
    $html .= '</td>';

    $html .= '<td class="text-center"><input type="checkbox" class="rowPrint" name="gibbonPersonID[]" value="' . $personID . '" checked/></td>';
    $html .= '</tr>';
}

$html .= '</table>';

$generateForm->addRow()->addContent($html);

$row = $generateForm->addRow();
$row->addContent(
    '<span class="text-xs italic">'
    . __('Print is ticked for every student. Untick any to leave out of the run. Apply to all copies the Class of and Graduation Date above into every row, exactly as entered, including blanks. Leave them empty to work them out from the year groups the student has ahead of them. Tick GPA for a student who needs grade points printed, such as one applying to an American university. Nothing is saved until Generate.')
    . '</span>'
);

$row = $generateForm->addRow();
$row->addLabel('status', __('Status'))
    ->description(__('Draft transcripts are watermarked. Final transcripts are not.'));
$row->addSelect('status')
    ->fromArray(['Draft' => __('Draft'), 'Final' => __('Final')])
    ->selected('Draft')
    ->required();

// A choice for this run, not for the student and not for the school. The
// same school prints both kinds in the same week: a snapshot with current
// marks for an accreditation visit, and a clean record without them.
$row = $generateForm->addRow();
$row->addLabel('includeInterim', __('Current Marks'))
    ->description(__('While a term is being taught and no grade has been stored, print the current Markbook average as a provisional grade, marked with an asterisk. No leaves those cells empty.'));
$row->addYesNo('includeInterim')
    ->selected('Y')
    ->required();

$row = $generateForm->addRow();
$row->addFooter();
$row->addSubmit(__('Generate Transcripts'));

echo $generateForm->getOutput();

?>
<script>
(function () {
    var form = document.getElementById('transcriptGenerate');
    if (!form) return;

    var all = function (selector) {
        return Array.prototype.slice.call(form.querySelectorAll(selector));
    };

    // Each all or none box drives its own column only.
    var bindAllNone = function (toggleID, rowSelector) {
        var toggle = document.getElementById(toggleID);
        if (!toggle) return;

        toggle.addEventListener('change', function () {
            all(rowSelector).forEach(function (box) { box.checked = toggle.checked; });
        });

        all(rowSelector).forEach(function (box) {
            box.addEventListener('change', function () {
                var boxes = all(rowSelector);
                var ticked = boxes.filter(function (b) { return b.checked; }).length;
                toggle.checked = ticked === boxes.length;
                toggle.indeterminate = ticked > 0 && ticked < boxes.length;
            });
        });
    };

    bindAllNone('printAll', 'input.rowPrint');
    bindAllNone('gpaAll', 'input.rowGpa');

    // Apply to all copies the two boxes in the coloured row into every row.
    var apply = document.getElementById('applyAll');
    if (apply) {
        apply.addEventListener('click', function () {
            var year = document.getElementById('applyGraduationYear').value;
            var date = document.getElementById('applyGraduationDate').value;
            all('input.rowGraduationYear').forEach(function (box) { box.value = year; });
            all('input.rowGraduationDate').forEach(function (box) { box.value = date; });
        });
    }

    <?php if (!$hasAnyArchive) { ?>
    var download = document.getElementById('downloadAll');
    if (download) {
        download.disabled = true;
        download.title = <?= json_encode(__('No transcripts have been generated for these students yet.')) ?>;
    }
    <?php } ?>
})();
</script>
