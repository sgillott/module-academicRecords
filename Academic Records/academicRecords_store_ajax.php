<?php
include '../../gibbon.php';
require_once './moduleFunctions.php';

use Gibbon\Domain\Students\StudentGateway;

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_store.php')) {
    exit;
}

header('Content-Type: application/json');

$cycleID = (int)($_GET['cycleID'] ?? 0);
$type = $_GET['type'] ?? '';

function parseArrayParam($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('trim', $value), function ($item) {
            return $item !== '';
        }));
    }

    if ($value === null || $value === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), function ($item) {
        return $item !== '';
    }));
}

if ($type === 'criteria') {
    echo json_encode(getCriteriaTypesByReportingCycle($connection2, $cycleID));
    exit;
}

if ($type === 'values') {
    $criteria = $_GET['criteria'] ?? [];
    $yearGroups = $_GET['yearGroups'] ?? [];
    echo json_encode(
        getDistinctReportingValuesGrouped($connection2, $cycleID, $criteria, $yearGroups)
    );
    exit;
}

if ($type === 'dependentFilters') {
    $filterYearGroups = ($_GET['filterYearGroups'] ?? 'N') === 'Y';
    $filterSubjects = ($_GET['filterSubjects'] ?? 'N') === 'Y';
    $filterStudents = ($_GET['filterStudents'] ?? 'N') === 'Y';

    $selectedYearGroups = parseArrayParam($_GET['yearGroups'] ?? []);
    $selectedSubjects = parseArrayParam($_GET['subjects'] ?? []);
    $selectedStudentIDs = parseArrayParam($_GET['studentIDs'] ?? []);
    $selectedCriteriaTypeIDs = parseArrayParam($_GET['criteriaTypeIDs'] ?? []);

    $valueSelections = [];
    foreach ($_GET as $key => $value) {
        if (strpos((string) $key, 'values_') === 0) {
            $valueSelections[$key] = parseArrayParam($value);
        }
    }

    $cycleYearGroups = getYearGroupsByReportingCycle($connection2, $cycleID);
    $effectiveYearGroups = $filterYearGroups
        ? $selectedYearGroups
        : array_keys($cycleYearGroups);

    if (empty($effectiveYearGroups)) {
        $effectiveYearGroups = array_keys($cycleYearGroups);
    }

    $subjects = getSubjectsByYearGroups(
        $connection2,
        $effectiveYearGroups,
        $cycleID,
        $selectedStudentIDs,
        $filterStudents
    );
    $criteriaTypes = getCriteriaTypesByReportingCycle(
        $connection2,
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
            $connection2,
            $criteriaTypes,
            $selectedCriteriaTypeIDs,
            $valueSelections,
            []
        ),
    ]);
    exit;
}

echo json_encode([]);
