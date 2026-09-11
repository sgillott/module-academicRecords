<?php
/**
 * Renders transcripts through the Gibbon Reports engine.
 *
 * This is the same sequence that Reports uses for a single report, with one
 * difference: the students come from this module's own selection rather than
 * from a year group. Nothing in the Reports module is changed. Its classes
 * are loaded by registering its namespace, the way core loads Activities from
 * the parent dashboard.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Services\ModuleLoader;
use Gibbon\Module\Reports\ArchiveFile;
use Gibbon\Module\Reports\ReportBuilder;
use Gibbon\Module\Reports\Renderer\MpdfRenderer;
use Gibbon\Module\Reports\Renderer\TcpdfRenderer;
use Gibbon\Module\Reports\Domain\ReportArchiveEntryGateway;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$_POST = $container->get(Validator::class)->sanitize($_POST);

$URL = $session->get('absoluteURL')
    . '/index.php?q=/modules/Academic Records/academicRecords_transcripts.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_transcripts.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

$gibbonReportID = (string) ($_POST['gibbonReportID'] ?? '');
$personIDs = normalizeRequestList($_POST['gibbonPersonID'] ?? []);
$status = ($_POST['status'] ?? 'Draft') === 'Final' ? 'Final' : 'Draft';
$graduationYears = is_array($_POST['graduationYear'] ?? null) ? $_POST['graduationYear'] : [];
$graduationDates = is_array($_POST['graduationDate'] ?? null) ? $_POST['graduationDate'] : [];
$gpaFlags = is_array($_POST['showGPA'] ?? null) ? $_POST['showGPA'] : [];

// Back to the same report and the same selection, so the list shows the result.
$URL .= '&gibbonReportID=' . rawurlencode($gibbonReportID);
foreach (normalizeRequestList($_POST['gibbonYearGroupIDList'] ?? '') as $yearGroupID) {
    $URL .= '&gibbonYearGroupIDList[]=' . rawurlencode($yearGroupID);
}
foreach (normalizeRequestList($_POST['studentIDs'] ?? '') as $studentID) {
    $URL .= '&studentIDs[]=' . rawurlencode($studentID);
}

if ($gibbonReportID === '' || empty($personIDs)) {
    header("Location: {$URL}&return=error1");
    exit;
}

$transcriptGateway = $container->get(TranscriptGateway::class);
$report = $transcriptGateway->getReportForGeneration($gibbonReportID);

if (empty($report) || $report['context'] !== 'Student Enrolment') {
    header("Location: {$URL}&return=error2");
    exit;
}

$actorID = (int) $session->get('gibbonPersonID');

$reject = function () use ($URL) {
    header("Location: {$URL}&return=error1");
    exit;
};

$validYear = function (string $year) {
    return $year === '' || preg_match('/^\d{4}$/', $year);
};

$validDate = function (string $date) {
    return $date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
};

/* -----------------------------------------------------
   Row values

   Saved for every ticked row before the run, because the transcript reads
   them while it renders.
----------------------------------------------------- */

foreach ($personIDs as $personID) {
    $year = trim((string) ($graduationYears[$personID] ?? ''));

    // The date field sends YYYY-MM-DD. Anything typed in the school's own
    // display format is converted here first, using Gibbon's own helper.
    $date = Format::dateConvert(trim((string) ($graduationDates[$personID] ?? '')));

    if (!$validYear($year) || !$validDate($date)) {
        $reject();
    }

    $showGPA = ((string) ($gpaFlags[$personID] ?? 'N')) === 'Y' ? 'Y' : 'N';

    // Nothing set means nothing is held against the student, so the row is
    // removed rather than kept as a set of empty values.
    if ($year === '' && $date === '' && $showGPA === 'N') {
        $transcriptGateway->deleteStudentValues($personID);
        continue;
    }

    $transcriptGateway->saveStudentValues(
        $personID,
        $year !== '' ? $year : null,
        $date !== '' ? $date : null,
        $showGPA,
        $actorID
    );
}

/* -----------------------------------------------------
   Reports engine
----------------------------------------------------- */

ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);

$moduleLoader = $container->get(ModuleLoader::class);

if (!$moduleLoader->registerModuleNamespace('Reports')) {
    header("Location: {$URL}&return=error2");
    exit;
}

// The template engine only carries the current module's template folder, so
// the Reports folder is added here. Without it the stock sections a template
// uses, such as the logo and the page number, cannot be found.
$twig = $container->get('twig');
$reportsTemplatePath = $session->get('absolutePath') . '/modules/Reports/templates';

if (is_dir($reportsTemplatePath)) {
    $twig->getLoader()->prependPath($reportsTemplatePath);
}

// Reports are cached apart from the rest of the system.
$cachePath = $session->has('cachePath') ? $session->get('cachePath') . '/reports' : '/uploads/cache';
$twig->setCache($session->get('absolutePath') . $cachePath);

$reportBuilder = $container->get(ReportBuilder::class);
$archiveFile = $container->get(ArchiveFile::class);
$archiveEntryGateway = $container->get(ReportArchiveEntryGateway::class);

$template = $reportBuilder->buildTemplate($report['gibbonReportTemplateID'], $status === 'Draft');
$renderer = $container->get($template->getData('flags') == 1 ? MpdfRenderer::class : TcpdfRenderer::class);

$enrolments = $transcriptGateway->selectEnrolmentsKeyed($personIDs, (string) $report['gibbonSchoolYearID']);

$generated = 0;
$skipped = 0;
$failed = 0;

foreach ($personIDs as $personID) {
    $enrolment = $enrolments[$personID] ?? null;

    // A student with no enrolment in the report's school year has nothing for
    // the engine to work from, so they are counted and passed over.
    if ($enrolment === null) {
        $skipped++;
        continue;
    }

    $ids = [
        'gibbonStudentEnrolmentID' => $enrolment['gibbonStudentEnrolmentID'],
        'gibbonReportingCycleID' => $report['gibbonReportingCycleID'],
    ];

    try {
        $reports = $reportBuilder->buildReportSingle($template, $report, $ids);

        $path = $archiveFile->getSingleFilePath(
            $gibbonReportID,
            $enrolment['gibbonYearGroupID'],
            $enrolment['gibbonStudentEnrolmentID']
        );

        $renderer->render($template, $reports, $session->get('absolutePath') . $report['archivePath'] . '/' . $path);

        $archiveEntryGateway->insertAndUpdate([
            'reportIdentifier' => $report['name'],
            'gibbonReportID' => $gibbonReportID,
            'gibbonReportArchiveID' => $report['gibbonReportArchiveID'],
            'gibbonSchoolYearID' => $report['gibbonSchoolYearID'],
            'gibbonYearGroupID' => $enrolment['gibbonYearGroupID'],
            'gibbonFormGroupID' => $enrolment['gibbonFormGroupID'],
            'gibbonPersonID' => $personID,
            'type' => 'Single',
            'status' => $status,
            'filePath' => $path,
        ], [
            'status' => $status,
            'timestampModified' => date('Y-m-d H:i:s'),
            'filePath' => $path,
        ]);

        $generated++;
    } catch (\Throwable $e) {
        $failed++;
    }
}

$URL .= '&generated=' . $generated . '&skipped=' . $skipped . '&failed=' . $failed;
$URL .= $failed > 0 ? '&return=error3' : '&return=success0';

header("Location: {$URL}");
exit;
