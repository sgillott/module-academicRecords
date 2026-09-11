<?php
/**
 * Downloads every generated transcript for the listed students as one zip.
 *
 * The PDFs are the ones the Reports archive already holds, so this reads
 * them; it never renders anything. One file per student, named after the
 * student, which is how they are usually sent on to universities.
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
use Gibbon\Domain\Students\StudentGateway;
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

$URL .= '&gibbonReportID=' . rawurlencode($gibbonReportID);
foreach (normalizeRequestList($_POST['gibbonYearGroupIDList'] ?? '') as $yearGroupID) {
    $URL .= '&gibbonYearGroupIDList[]=' . rawurlencode($yearGroupID);
}
foreach (normalizeRequestList($_POST['studentIDs'] ?? '') as $studentID) {
    $URL .= '&studentIDs[]=' . rawurlencode($studentID);
}

if ($gibbonReportID === '' || empty($personIDs) || !class_exists('ZipArchive')) {
    header("Location: {$URL}&return=error1");
    exit;
}

$transcriptGateway = $container->get(TranscriptGateway::class);
$report = $transcriptGateway->getReportForGeneration($gibbonReportID);

if (empty($report)) {
    header("Location: {$URL}&return=error2");
    exit;
}

$archives = $transcriptGateway->selectArchiveEntriesKeyed($gibbonReportID, $personIDs);

if (empty($archives)) {
    header("Location: {$URL}&return=error1");
    exit;
}

// Names for the files inside the zip.
$studentGateway = $container->get(StudentGateway::class);
$names = [];

foreach ($studentGateway->queryStudentsBySchoolYear($studentGateway->newQueryCriteria(), $report['gibbonSchoolYearID'])->toArray() as $student) {
    $names[(string) $student['gibbonPersonID']] = Format::name('', (string) $student['preferredName'], (string) $student['surname'], 'Student', true);
}

$zipPath = tempnam(sys_get_temp_dir(), 'transcripts');
$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    header("Location: {$URL}&return=error2");
    exit;
}

$added = 0;
$basePath = $session->get('absolutePath') . $report['archivePath'] . '/';

foreach ($archives as $personID => $entry) {
    $pdf = $basePath . $entry['filePath'];

    if (!is_file($pdf)) {
        continue;
    }

    // Letters, digits, spaces and a few safe marks only, so the name inside
    // the zip cannot carry a path.
    $name = preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $names[(string) $personID] ?? $personID);
    $name = trim($name) !== '' ? trim($name) : (string) $personID;

    $zip->addFile($pdf, $name . ' - ' . $report['name'] . '.pdf');
    $added++;
}

$zip->close();

if ($added === 0) {
    @unlink($zipPath);
    header("Location: {$URL}&return=error1");
    exit;
}

$downloadName = preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $report['name']) . ' - ' . date('Y-m-d') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: private, no-store');

readfile($zipPath);
@unlink($zipPath);
exit;
