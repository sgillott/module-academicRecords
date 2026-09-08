<?php

use Gibbon\Module\AcademicRecords\Domain\CAT4MappingGateway;

require_once __DIR__.'/moduleFunctions.php';

$cat4ID = (int)($_POST['cat4ID'] ?? 0);
$gcseID = (int)($_POST['gcseID'] ?? 0);
$ibID   = (int)($_POST['ibID'] ?? 0);

$URL = $session->get('absoluteURL')
.'/index.php?q=/modules/Academic Records/academicRecords_cat4Mapping.php'
.'&cat4ID='.$cat4ID
.'&gcseID='.$gcseID
.'&ibID='.$ibID;

if (!isActionAccessible($guid,$connection2,'/modules/Academic Records/academicRecords_cat4Mapping.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

$gateway = $container->get(CAT4MappingGateway::class);
$personID = (int) $session->get('gibbonPersonID');

$headers = is_array($_POST['header'] ?? null) ? $_POST['header'] : [];
$active  = is_array($_POST['active'] ?? null) ? $_POST['active'] : [];
$studentMatchFields = is_array($_POST['studentMatchField'] ?? null) ? $_POST['studentMatchField'] : [];
$studentIdentifierHeaderPatterns = is_array($_POST['studentIdentifierHeaderPattern'] ?? null) ? $_POST['studentIdentifierHeaderPattern'] : [];
$dateHeaderPatterns = is_array($_POST['dateHeaderPattern'] ?? null) ? $_POST['dateHeaderPattern'] : [];

function saveMapping(
    $gateway,
    int $assessmentID,
    array $headers,
    array $active,
    array $studentMatchFields,
    array $studentIdentifierHeaderPatterns,
    array $dateHeaderPatterns,
    string $namespace,
    int $personID
): void
{
    if ($assessmentID <= 0 || !isset($headers[$namespace]) || !is_array($headers[$namespace])) {
        return;
    }

    $fields = [];

    foreach ($headers[$namespace] as $fieldID => $header) {

        $fieldID = (int)$fieldID;
        $header = trim((string)$header);

        if ($fieldID <= 0 || $header === '') {
            continue;
        }

        $fields[] = [
            'fieldID' => $fieldID,
            'pattern' => $header,
            'active'  => (($active[$namespace][$fieldID] ?? 'N') === 'Y') ? 'Y' : 'N',
        ];
    }

    $mappingID = $gateway->ensureDefaultMapping($assessmentID, $personID);

    if ($mappingID <= 0) {
        throw new \RuntimeException('Unable to determine CAT4 mapping ID for assessment ' . $assessmentID);
    }

    $existingMapping = $gateway->getMapping($mappingID) ?? [];
    $assessment = $gateway->getAssessment($assessmentID);
    $assessmentName = trim((string) ($assessment['name'] ?? $existingMapping['name'] ?? 'External Assessment'));
    $studentMatchField = (string) ($studentMatchFields[$namespace] ?? ($existingMapping['studentMatchField'] ?? 'studentID'));
    $studentIdentifierHeaderPattern = trim((string) ($studentIdentifierHeaderPatterns[$namespace] ?? ($existingMapping['studentIdentifierHeaderPattern'] ?? 'Student ID')));
    $dateHeaderPattern = trim((string) ($dateHeaderPatterns[$namespace] ?? ($existingMapping['dateHeaderPattern'] ?? 'Date of test')));

    if (!in_array($studentMatchField, ['studentID', 'gibbonPersonID', 'username'], true)) {
        $studentMatchField = 'studentID';
    }

    if ($studentIdentifierHeaderPattern === '') {
        $studentIdentifierHeaderPattern = 'Student ID';
    }

    if ($dateHeaderPattern === '') {
        $dateHeaderPattern = 'Date of test';
    }

    $gateway->upsertMapping([
        'gibbonExternalAssessmentID' => $assessmentID,
        'name' => (string) ($existingMapping['name'] ?? ($assessmentName . ' Default Mapping')),
        'studentMatchField' => $studentMatchField,
        'studentIdentifierHeaderPattern' => $studentIdentifierHeaderPattern,
        'dateHeaderPattern' => $dateHeaderPattern,
        'importCategories' => (string) ($existingMapping['importCategories'] ?? '[]'),
        'active' => (string) (($existingMapping['active'] ?? 'Y') === 'N' ? 'N' : 'Y'),
        'timestampUpdated' => date('Y-m-d H:i:s'),
        'gibbonPersonIDUpdated' => $personID,
    ], $mappingID);

    $gateway->replaceMappingFields($mappingID, $fields);
}

try {
    saveMapping(
        $gateway,
        $cat4ID,
        $headers,
        $active,
        $studentMatchFields,
        $studentIdentifierHeaderPatterns,
        $dateHeaderPatterns,
        'cat4',
        $personID
    );
    saveMapping(
        $gateway,
        $gcseID,
        $headers,
        $active,
        $studentMatchFields,
        $studentIdentifierHeaderPatterns,
        $dateHeaderPatterns,
        'gcse',
        $personID
    );
    saveMapping(
        $gateway,
        $ibID,
        $headers,
        $active,
        $studentMatchFields,
        $studentIdentifierHeaderPatterns,
        $dateHeaderPatterns,
        'ib',
        $personID
    );
} catch (\Throwable $e) {
    header("Location: {$URL}&return=error2");
    exit;
}

header("Location: {$URL}&return=success0");
exit;
