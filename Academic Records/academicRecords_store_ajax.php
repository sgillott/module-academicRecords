<?php
/**
 * Refreshes the downstream filters on Store Grades step 1.
 *
 * Called by the inline script on that page whenever an upstream filter
 * changes. Returns the subjects, the student picker contents and the
 * criteria table for the current selection.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Module\AcademicRecords\Domain\StoreFilterGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_store.php')) {
    exit;
}

header('Content-Type: application/json');

$filterGateway = $container->get(StoreFilterGateway::class);
$cycleID = (int) ($_GET['cycleID'] ?? 0);

if (($_GET['type'] ?? '') !== 'dependentFilters') {
    echo json_encode([]);
    exit;
}

$filterYearGroups = ($_GET['filterYearGroups'] ?? 'N') === 'Y';
$filterSubjects = ($_GET['filterSubjects'] ?? 'N') === 'Y';
$filterStudents = ($_GET['filterStudents'] ?? 'N') === 'Y';

$selectedYearGroups = normalizeRequestList($_GET['yearGroups'] ?? []);
$selectedSubjects = normalizeRequestList($_GET['subjects'] ?? []);
$selectedStudentIDs = normalizeRequestList($_GET['studentIDs'] ?? []);
$selectedCriteriaTypeIDs = normalizeRequestList($_GET['criteriaTypeIDs'] ?? []);

$valueSelections = [];
foreach ($_GET as $key => $value) {
    if (strpos((string) $key, 'values_') === 0) {
        $valueSelections[$key] = normalizeRequestList($value);
    }
}

$cycleYearGroups = $filterGateway->selectYearGroupsByCycle($cycleID);
$effectiveYearGroups = $filterYearGroups
    ? $selectedYearGroups
    : array_keys($cycleYearGroups);

if (empty($effectiveYearGroups)) {
    $effectiveYearGroups = array_keys($cycleYearGroups);
}

$subjects = $filterGateway->selectSubjectsByYearGroups(
    $effectiveYearGroups,
    $cycleID,
    $selectedStudentIDs,
    $filterStudents
);
$criteriaTypes = $filterGateway->selectCriteriaTypesByCycle(
    $cycleID,
    $effectiveYearGroups,
    $filterYearGroups,
    $selectedSubjects,
    $filterSubjects,
    $selectedStudentIDs,
    $filterStudents
);

$studentGateway = $container->get(StudentGateway::class);
$studentCriteria = $studentGateway->newQueryCriteria()->sortBy(['surname', 'preferredName']);
$students = $studentGateway
    ->queryStudentsBySchoolYear($studentCriteria, $session->get('gibbonSchoolYearID'))
    ->toArray();

$studentSelection = buildStudentSelectionData($students, $effectiveYearGroups, $selectedStudentIDs);

echo json_encode([
    'subjects' => $subjects,
    'students' => $studentSelection,
    'criteriaHtml' => renderCriteriaTableHtml(
        $filterGateway,
        $criteriaTypes,
        $selectedCriteriaTypeIDs,
        $valueSelections,
        []
    ),
]);
exit;
