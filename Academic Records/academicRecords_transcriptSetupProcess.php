<?php
/**
 * Saves the three Transcript Setup sections.
 *
 * Each section posts its own form, and says which one it is in a hidden
 * action field, so saving one section never rewrites the others.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\CourseCreditGateway;
use Gibbon\Module\AcademicRecords\Domain\StoredGradeGateway;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$URL = $session->get('absoluteURL')
    . '/index.php?q=/modules/Academic Records/academicRecords_transcriptSetup.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_transcriptSetup.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$actorID = (int) $session->get('gibbonPersonID');
$partialFail = false;

/* -----------------------------------------------------
   Year Groups
----------------------------------------------------- */

if ($action === 'options') {
    $submitted = normalizeRequestList($_POST['transcriptYearGroups'] ?? []);
    $gpaMethod = (string) ($_POST['transcriptGpaMethod'] ?? '');

    if (!in_array($gpaMethod, ['creditWeighted', 'simpleMean'], true)) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    $transcriptGateway = $container->get(TranscriptGateway::class);

    // Only year groups that exist may be stored, so a tampered field cannot
    // put an unknown ID into the setting.
    $validYearGroupIDs = [];

    foreach ($transcriptGateway->selectYearGroupOptions() as $yearGroup) {
        $validYearGroupIDs[(string) $yearGroup['gibbonYearGroupID']] = true;
    }

    $chosen = array_values(array_filter($submitted, function ($yearGroupID) use ($validYearGroupIDs) {
        return isset($validYearGroupIDs[(string) $yearGroupID]);
    }));

    $settingGateway = $container->get(SettingGateway::class);

    $savedYearGroups = $settingGateway->updateSettingByScope('Academic Records', 'transcriptYearGroups', implode(',', $chosen));
    $savedMethod = $settingGateway->updateSettingByScope('Academic Records', 'transcriptGpaMethod', $gpaMethod);

    $URL .= ($savedYearGroups === false || $savedMethod === false) ? '&return=error2' : '&return=success0';
    header("Location: {$URL}");
    exit;
}

/* -----------------------------------------------------
   Grade to Credit
----------------------------------------------------- */

if ($action === 'gradeCredit') {
    $scaleID = trim((string) ($_POST['gibbonScaleID'] ?? ''));
    $factors = is_array($_POST['creditFactor'] ?? null) ? $_POST['creditFactor'] : [];
    $minimums = is_array($_POST['percentMin'] ?? null) ? $_POST['percentMin'] : [];
    $maximums = is_array($_POST['percentMax'] ?? null) ? $_POST['percentMax'] : [];
    $points = is_array($_POST['gpaPoints'] ?? null) ? $_POST['gpaPoints'] : [];
    $letters = is_array($_POST['gpaLetter'] ?? null) ? $_POST['gpaLetter'] : [];

    if ($scaleID === '') {
        header("Location: {$URL}&return=error1");
        exit;
    }

    $creditGateway = $container->get(CourseCreditGateway::class);

    // Only grades of the chosen scale may be written.
    $validValues = [];

    foreach ($creditGateway->selectGradesByScale($scaleID) as $grade) {
        $validValues[(string) $grade['value']] = true;
    }

    $reject = function () use ($URL, $scaleID) {
        header("Location: {$URL}&gibbonScaleID=" . rawurlencode($scaleID) . '&return=error1');
        exit;
    };

    foreach ($validValues as $value => $unused) {
        $factor = trim((string) ($factors[$value] ?? ''));
        $percentMin = trim((string) ($minimums[$value] ?? ''));
        $percentMax = trim((string) ($maximums[$value] ?? ''));
        $gpaPoints = trim((string) ($points[$value] ?? ''));
        $gpaLetter = strtoupper(trim((string) ($letters[$value] ?? '')));

        // Nothing set at all means the row is removed, rather than stored as
        // a set of zeros that would look like a deliberate choice.
        if ($factor === '' && $percentMin === '' && $percentMax === '' && $gpaPoints === '' && $gpaLetter === '') {
            $partialFail = !$creditGateway->deleteGradeSetting($scaleID, (string) $value) || $partialFail;
            continue;
        }

        if ($factor !== '' && (!is_numeric($factor) || (float) $factor < 0 || (float) $factor > 1)) {
            $reject();
        }

        if ($gpaPoints !== '' && (!is_numeric($gpaPoints) || (float) $gpaPoints < 0 || (float) $gpaPoints > 9.99)) {
            $reject();
        }

        if (mb_strlen($gpaLetter) > 4) {
            $reject();
        }

        foreach ([$percentMin, $percentMax] as $percent) {
            if ($percent !== '' && (!is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100)) {
                $reject();
            }
        }

        // Half a range cannot be used, so both ends are required together.
        if (($percentMin === '') !== ($percentMax === '')) {
            $reject();
        }

        if ($percentMin !== '' && (float) $percentMin > (float) $percentMax) {
            $reject();
        }

        $partialFail = !$creditGateway->saveGradeSetting(
            $scaleID,
            (string) $value,
            $factor !== '' ? (string) (float) $factor : null,
            $percentMin !== '' ? (string) (float) $percentMin : null,
            $percentMax !== '' ? (string) (float) $percentMax : null,
            $gpaPoints !== '' ? (string) (float) $gpaPoints : null,
            $gpaLetter !== '' ? $gpaLetter : null
        ) || $partialFail;
    }

    $URL .= '&gibbonScaleID=' . rawurlencode($scaleID);
    $URL .= $partialFail ? '&return=error2' : '&return=success0';
    header("Location: {$URL}");
    exit;
}

/* -----------------------------------------------------
   Stored Grade Terms
----------------------------------------------------- */

if ($action !== 'storedGradeTerms') {
    header("Location: {$URL}&return=error1");
    exit;
}

$submitted = $_POST['termID'] ?? [];

if (!is_array($submitted)) {
    header("Location: {$URL}&return=error1");
    exit;
}

$settingGateway = $container->get(SettingGateway::class);
$storedGradeGateway = $container->get(StoredGradeGateway::class);

$internalAssessmentType = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType', true)['value'] ?? ''));

if ($internalAssessmentType === '') {
    header("Location: {$URL}&return=error1");
    exit;
}

// Only columns the page itself offered may be written, so a tampered field
// name cannot index a column this module does not own.
$candidates = [];

foreach ($storedGradeGateway->selectUnindexedColumns($internalAssessmentType) as $column) {
    $candidates[(string) $column['gibbonInternalAssessmentColumnID']] = $column;
}

foreach ($submitted as $columnID => $rawTermID) {
    $columnID = (string) $columnID;
    $termID = normalizeSchoolYearTermID((string) $rawTermID);

    // An empty value is the Skip option, so the column stays on the list.
    if ($termID === '' || !isset($candidates[$columnID])) {
        continue;
    }

    $column = $candidates[$columnID];
    $schoolYearID = (string) $column['gibbonSchoolYearID'];

    $validTermIDs = [];
    foreach ($storedGradeGateway->selectTermsBySchoolYear($schoolYearID) as $term) {
        $validTermIDs[(string) $term['gibbonSchoolYearTermID']] = true;
    }

    if (!isset($validTermIDs[$termID])) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    $saved = $storedGradeGateway->saveIndex([
        'columnID' => (int) $columnID,
        'schoolYearID' => $schoolYearID,
        'termID' => $termID,
        'cycleID' => null,
        'classID' => (int) $column['gibbonCourseClassID'],
        'actorID' => $actorID,
    ]);

    $partialFail = !$saved || $partialFail;
}

$URL .= $partialFail ? '&return=error2' : '&return=success0';
header("Location: {$URL}");
exit;
