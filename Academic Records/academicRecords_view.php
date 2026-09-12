<?php
/**
 * Shows the academic record of one student on screen.
 *
 * The record is the same one a transcript prints: every school year the
 * transcript covers, every course from class enrolment, and the stored
 * grade for each term, with the credit it earned. It is built by the same
 * code as the PDF, so the two can never disagree. No PDF is made here; the
 * transcripts already generated for the student are listed at the foot,
 * through the Reports archive.
 *
 * Two actions share this page. View Academic Records_all shows any student.
 * View Academic Records_my shows only the students in a class the user
 * teaches this year, or in a form group they tutor.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Services\Format;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;
use Gibbon\Module\AcademicRecords\Transcript\RecordBuilder;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_view.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$highestAction = getHighestGroupedAction($guid, '/modules/Academic Records/academicRecords_view.php', $connection2);

if ($highestAction === false) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$canViewAll = $highestAction === 'View Academic Records_all';

$page->breadcrumbs->add(__('View Academic Records'));
$page->stylesheets->add('module-academicRecords', 'modules/Academic Records/css/module.css');

$transcriptGateway = $container->get(TranscriptGateway::class);

$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$staffID = (string) $session->get('gibbonPersonID');

$gibbonPersonID = (string) ($_GET['gibbonPersonID'] ?? '');
$gibbonFormGroupID = (string) ($_GET['gibbonFormGroupID'] ?? '');
$gibbonYearGroupID = normalizeYearGroupID($_GET['gibbonYearGroupID'] ?? '');
$includeInterim = ($_GET['includeInterim'] ?? 'Y') !== 'N';

/* -----------------------------------------------------
   Students the user may see

   Everyone who has ever been a student, so a record can be read after the
   student has graduated or left. The form group and year group filters
   narrow the list by each student's most recent enrolment, which for a
   current student is this year's.
----------------------------------------------------- */

$students = $transcriptGateway->selectStudentPickerRows($gibbonSchoolYearID);

if (!$canViewAll) {
    $allowed = $transcriptGateway->selectMyStudentIDs($staffID, $gibbonSchoolYearID);

    $students = array_values(array_filter($students, function ($student) use ($allowed) {
        return isset($allowed[(string) $student['gibbonPersonID']]);
    }));
}

$studentsByID = [];
$studentOptions = [];

foreach ($students as $student) {
    $personID = (string) $student['gibbonPersonID'];
    $studentsByID[$personID] = $student;

    if ($gibbonFormGroupID !== '' && (string) $student['gibbonFormGroupID'] !== $gibbonFormGroupID) {
        continue;
    }

    if ($gibbonYearGroupID !== '' && normalizeYearGroupID($student['gibbonYearGroupID']) !== $gibbonYearGroupID) {
        continue;
    }

    // The same label as Gibbon's own student select. A student who is not
    // here this year shows the school year of their last enrolment in place
    // of the form group, and sits in a group of their own.
    $name = Format::name('', (string) $student['preferredName'], (string) $student['surname'], 'Student', true, true);

    if (!empty($student['isCurrent'])) {
        $group = __('Current Students');
        $where = (string) $student['formGroup'];
    } elseif ((string) $student['status'] === 'Left') {
        $group = __('Former Students');
        $where = __('Left') . ' ' . (string) $student['schoolYearName'];
    } else {
        $group = __('Other Students');
        $where = __($student['status']) . ' ' . (string) $student['schoolYearName'];
    }

    $studentOptions[$group][$personID] = $name . ' (' . $where . ', ' . (string) $student['username'] . ')';
}

if (empty($studentsByID)) {
    echo Format::alert(
        $canViewAll
            ? __('There are no students enrolled in the current school year.')
            : __('You do not teach a class or tutor a form group this year, so there are no students to show.'),
        'warning'
    );
    return;
}

/* -----------------------------------------------------
   Picker, laid out the same as Manage Behaviour Records
----------------------------------------------------- */

$form = Form::createSearch();
$form->setFactory(DatabaseFormFactory::create($pdo));

$row = $form->addRow();
    $row->addLabel('gibbonPersonID', __('Student'));
    $row->addSelectPerson('gibbonPersonID')->fromArray($studentOptions)->selected($gibbonPersonID)->placeholder();

$row = $form->addRow();
    $row->addLabel('gibbonFormGroupID', __('Form Group'));
    $row->addSelectFormGroup('gibbonFormGroupID', $gibbonSchoolYearID)->selected($gibbonFormGroupID)->placeholder();

$row = $form->addRow();
    $row->addLabel('gibbonYearGroupID', __('Year Group'));
    $row->addSelectYearGroup('gibbonYearGroupID')->placeholder()->selected($gibbonYearGroupID);

$row = $form->addRow();
    $row->addLabel('includeInterim', __('Current Marks'))
        ->description(__('While a term is being taught and no grade has been stored, show the current Markbook average as a provisional grade, marked with an asterisk.'));
    $row->addYesNo('includeInterim')->selected($includeInterim ? 'Y' : 'N');

$row = $form->addRow()->addSearchSubmit($session, __('Clear Filters'));

echo $form->getOutput();

if ($gibbonPersonID === '') {
    if (empty($studentOptions)) {
        echo Format::alert(__('No student matches the chosen form group and year group.'), 'message');
    }
    return;
}

if (!isset($studentsByID[$gibbonPersonID])) {
    echo Format::alert(__('The selected record does not exist, or you do not have access to it.'), 'error');
    return;
}

/* -----------------------------------------------------
   The record
----------------------------------------------------- */

$student = $studentsByID[$gibbonPersonID];
$anchor = $transcriptGateway->getSchoolYear($gibbonSchoolYearID);

$record = (new RecordBuilder($container->get('db')))->build($gibbonPersonID, $anchor, $includeInterim);

// Archived transcripts, with the same View and Download links the Reports
// module draws, so its access rules apply.
$downloadURL = $session->get('absoluteURL') . '/modules/Reports/archive_byStudent_download.php';
$archives = [];

foreach ($transcriptGateway->selectArchivedTranscriptsByPerson($gibbonPersonID) as $entry) {
    $params = '?gibbonPersonID=' . rawurlencode($gibbonPersonID)
        . '&gibbonReportArchiveEntryID=' . rawurlencode((string) $entry['gibbonReportArchiveEntryID']);

    $archives[] = [
        'reportName' => (string) $entry['reportName'],
        'schoolYearName' => (string) $entry['schoolYearName'],
        'status' => (string) $entry['status'],
        'generated' => Format::dateTimeReadable((string) $entry['timestampModified']),
        'viewURL' => $downloadURL . $params . '&action=view',
        'downloadURL' => $downloadURL . $params,
    ];
}

echo $page->fetchFromTemplate('recordView.twig.html', [
    'studentName' => Format::name('', (string) $student['preferredName'], (string) $student['surname'], 'Student', true),
    'formGroup' => (string) ($student['formGroup'] ?? ''),
    'yearGroup' => (string) ($student['yearGroupName'] ?? ''),
    'isCurrent' => !empty($student['isCurrent']),
    'status' => (string) ($student['status'] ?? ''),
    'lastSchoolYear' => (string) ($student['schoolYearName'] ?? ''),
    'graduationDate' => $record['graduationDate'] !== '' ? Format::date($record['graduationDate']) : '',
    'includeInterim' => $includeInterim,
    'record' => $record,
    'archives' => $archives,
]);
