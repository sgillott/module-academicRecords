<?php
/**
 * Saves the credit and the transcript switch for every course in the year.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Module\AcademicRecords\Domain\CourseCreditGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$gibbonSchoolYearID = (string) ($_POST['gibbonSchoolYearID'] ?? '');
$credits = is_array($_POST['creditPerTerm'] ?? null) ? $_POST['creditPerTerm'] : [];
$shown = is_array($_POST['showOnTranscript'] ?? null) ? $_POST['showOnTranscript'] : [];

$URL = $session->get('absoluteURL')
    . '/index.php?q=/modules/Academic Records/academicRecords_courseCredits.php'
    . '&gibbonSchoolYearID=' . rawurlencode($gibbonSchoolYearID);

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_courseCredits.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

if ($gibbonSchoolYearID === '') {
    header("Location: {$URL}&return=error1");
    exit;
}

$creditGateway = $container->get(CourseCreditGateway::class);

// Only courses of this school year are written, so a tampered field name
// cannot reach a course in another year.
$actorID = (int) $session->get('gibbonPersonID');
$partialFail = false;

foreach ($creditGateway->selectCoursesBySchoolYear($gibbonSchoolYearID) as $course) {
    $courseID = (string) $course['gibbonCourseID'];

    // A course not in the request was not on the page, so it is left alone.
    if (!array_key_exists($courseID, $credits) && !array_key_exists($courseID, $shown)) {
        continue;
    }

    $credit = trim((string) ($credits[$courseID] ?? ''));

    if ($credit !== '' && (!is_numeric($credit) || (float) $credit < 0 || (float) $credit > 99)) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    // The hidden field before the checkbox sends N, and a ticked box replaces
    // it with Y, so an unticked box still arrives and can switch a course off.
    $show = ((string) ($shown[$courseID] ?? 'Y')) === 'N' ? 'N' : 'Y';

    $saved = $creditGateway->saveCourseCredit(
        $courseID,
        $credit !== '' ? (string) (float) $credit : null,
        $show,
        $actorID
    );

    $partialFail = !$saved || $partialFail;
}

$URL .= $partialFail ? '&return=error2' : '&return=success0';
header("Location: {$URL}");
exit;
