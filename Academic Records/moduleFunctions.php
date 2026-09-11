<?php

use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\DataSet;
use Gibbon\Services\Format;

require_once __DIR__ . '/includes/TestwiseYear.php';
require_once __DIR__ . '/includes/TestwiseValues.php';

function queryEligibleReportingGrades(
    PDO $connection,
    QueryCriteria $criteria,
    int $cycleID,
    array $filters = []
): DataSet {

    $baseSQL = "
        FROM gibbonReportingValue rv
        JOIN gibbonReportingCriteria rc
            ON rc.gibbonReportingCriteriaID = rv.gibbonReportingCriteriaID
        JOIN gibbonReportingCriteriaType rct
            ON rct.gibbonReportingCriteriaTypeID = rc.gibbonReportingCriteriaTypeID
        JOIN gibbonCourseClass cc
            ON cc.gibbonCourseClassID = rv.gibbonCourseClassID
        JOIN gibbonCourse c
            ON c.gibbonCourseID = cc.gibbonCourseID
        JOIN gibbonPerson p
            ON p.gibbonPersonID = rv.gibbonPersonIDStudent
        WHERE
            rv.gibbonReportingCycleID = :cycleID
            AND rct.valueType = 'Grade Scale'
            AND rc.target = 'Per Student'
    ";

    $params = ['cycleID' => $cycleID];
    $extraWhere = '';

    // Year Group Filter
    if (($filters['filterYearGroups'] ?? 'N') === 'Y' && !empty($filters['gibbonYearGroupIDList'])) {

        $ygConditions = [];

        foreach ($filters['gibbonYearGroupIDList'] as $i => $ygID) {
            $key = "yg$i";
            $ygConditions[] = "FIND_IN_SET(:$key, c.gibbonYearGroupIDList)";
            $params[$key] = normalizeYearGroupID($ygID);
        }

        $extraWhere .= " AND (" . implode(' OR ', $ygConditions) . ")";
    }

    // Student Filter
    if (($filters['filterStudents'] ?? 'N') === 'Y' && !empty($filters['studentIDs'])) {

        $studentConditions = [];

        foreach ($filters['studentIDs'] as $i => $id) {
            $key = "student$i";
            $studentConditions[] = "rv.gibbonPersonIDStudent = :$key";
            $params[$key] = (int) $id;
        }

        $extraWhere .= " AND (" . implode(' OR ', $studentConditions) . ")";
    }

    // Subject Filter
    if (($filters['filterSubjects'] ?? 'N') === 'Y' && !empty($filters['subjectIDs'])) {

        $subjectConditions = [];

        foreach ($filters['subjectIDs'] as $i => $id) {
            $key = "subject$i";
            $subjectConditions[] = "c.gibbonCourseID = :$key";
            $params[$key] = (int) $id;
        }

        $extraWhere .= " AND (" . implode(' OR ', $subjectConditions) . ")";
    }

    $selectSQL = "
        SELECT
            rv.gibbonReportingValueID,
            p.surname,
            p.preferredName,
            c.nameShort AS courseName,
            cc.nameShort AS className,
            rc.name AS criteriaName,
            rv.value
        $baseSQL
        $extraWhere
    ";

    $orderBy = " ORDER BY p.surname, p.preferredName, c.nameShort, rc.name ";

    $page = max(1, (int) $criteria->getPage());
    $pageSize = (int) $criteria->getPageSize();
    $offset = ($page - 1) * $pageSize;

    $limitSQL = " LIMIT :limit OFFSET :offset ";

    $stmt = $connection->prepare($selectSQL . $orderBy . $limitSQL);

    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['studentName'] = $row['preferredName'] . ' ' . $row['surname'];
    }

    $countStmt = $connection->prepare("SELECT COUNT(*) $baseSQL $extraWhere");
    foreach ($params as $key => $value) {
        $countStmt->bindValue(":$key", $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    return new DataSet(
        $rows,
        $total,
        $criteria->getPage(),
        $criteria->getPageSize()
    );
}

function getReportingCycles($connection): array
{
    $sql = "
        SELECT
            rc.gibbonReportingCycleID,
            rc.name AS cycleName,
            sy.name AS schoolYearName,
            sy.sequenceNumber,
            rc.dateStart
        FROM gibbonReportingCycle rc
        JOIN gibbonSchoolYear sy
            ON sy.gibbonSchoolYearID = rc.gibbonSchoolYearID
        ORDER BY sy.sequenceNumber DESC, rc.dateStart DESC, rc.gibbonReportingCycleID DESC
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute();

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[$row['gibbonReportingCycleID']] =
            '(' . $row['schoolYearName'] . ') ' . $row['cycleName'];
    }

    return $results;
}

function getYearGroupsByReportingCycle($connection, int $cycleID): array
{
    $sql = "
        SELECT gibbonYearGroupIDList
        FROM gibbonReportingCycle
        WHERE gibbonReportingCycleID = :cycleID
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute(['cycleID' => $cycleID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['gibbonYearGroupIDList'])) return [];

    $ids = explode(',', $row['gibbonYearGroupIDList']);

    $placeholders = [];
    $params = [];

    foreach ($ids as $i => $id) {
        $key = "yg$i";
        $placeholders[] = ":$key";
        $params[$key] = (int) trim($id);
    }

    $sql = "
        SELECT gibbonYearGroupID, name
        FROM gibbonYearGroup
        WHERE gibbonYearGroupID IN (" . implode(',', $placeholders) . ")
        ORDER BY sequenceNumber
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[$row['gibbonYearGroupID']] = $row['name'];
    }

    return $results;
}

function getCriteriaTypesByReportingCycle(
    $connection,
    int $cycleID,
    array $yearGroupIDs = [],
    bool $yearGroupFilterOn = false,
    array $subjectIDs = [],
    bool $subjectFilterOn = false,
    array $studentIDs = [],
    bool $studentFilterOn = false
): array {
    $params = ['cycleID' => $cycleID];
    $yearGroupCondition = '';
    $subjectCondition = '';
    $studentCondition = '';

    if ($yearGroupFilterOn) {

        if (!empty($yearGroupIDs)) {
            $conditions = [];

            foreach (array_values($yearGroupIDs) as $i => $id) {
                $key = "yg$i";
                $conditions[] = "FIND_IN_SET(:$key, c.gibbonYearGroupIDList)";
                $params[$key] = normalizeYearGroupID($id);
            }

            $yearGroupCondition = " AND (" . implode(' OR ', $conditions) . ")";
        }
    }

    if ($subjectFilterOn) {

        if (!empty($subjectIDs)) {
            $subjectPlaceholders = [];

            foreach (array_values($subjectIDs) as $i => $id) {
                $key = "subject$i";
                $subjectPlaceholders[] = ":$key";
                $params[$key] = (int) $id;
            }

            $subjectCondition = " AND c.gibbonCourseID IN (" . implode(',', $subjectPlaceholders) . ")";
        }
    }

    if ($studentFilterOn) {

        if (!empty($studentIDs)) {
            $studentPlaceholders = [];

            foreach (array_values($studentIDs) as $i => $id) {
                $key = "student$i";
                $studentPlaceholders[] = ":$key";
                $params[$key] = (int) $id;
            }

            $studentCondition = " AND EXISTS (
                SELECT 1
                FROM gibbonReportingValue rv
                WHERE rv.gibbonReportingCycleID = rc.gibbonReportingCycleID
                  AND rv.gibbonReportingCriteriaID = rc.gibbonReportingCriteriaID
                  AND rv.gibbonPersonIDStudent IN (" . implode(',', $studentPlaceholders) . ")
            )";
        }
    }

    $sql = "
        SELECT DISTINCT
            rct.gibbonReportingCriteriaTypeID,
            rct.name,
            rct.gibbonScaleID
        FROM gibbonReportingCriteria rc
        JOIN gibbonReportingCriteriaType rct
            ON rct.gibbonReportingCriteriaTypeID = rc.gibbonReportingCriteriaTypeID
        JOIN gibbonCourse c
            ON c.gibbonCourseID = rc.gibbonCourseID
        WHERE rc.gibbonReportingCycleID = :cycleID
          AND rc.target = 'Per Student'
          AND rct.valueType = 'Grade Scale'
          AND rct.active = 'Y'
          $yearGroupCondition
          $subjectCondition
          $studentCondition
        ORDER BY rct.name
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[$row['gibbonReportingCriteriaTypeID']] = [
            'name' => $row['name'],
            'scaleID' => $row['gibbonScaleID'],
        ];
    }

    return $results;
}

function getScaleValues($connection, int $scaleID): array
{
    $sql = "
        SELECT value
        FROM gibbonScaleGrade
        WHERE gibbonScaleID = :scaleID
        ORDER BY sequenceNumber
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute(['scaleID' => $scaleID]);

    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $v = $row['value'];
        if ($v === '' || $v === null) {
            $values['__BLANK__'] = '(Blank)';
        } else {
            $values[$v] = $v;
        }
    }

    return $values;
}

function normalizeYearGroupID($id): string
{
    $id = trim((string) $id);

    if (ctype_digit($id) && strlen($id) >= 3) {
        return $id;
    }

    if (ctype_digit($id)) {
        return str_pad($id, 3, '0', STR_PAD_LEFT);
    }

    return $id;
}

/**
 * Read a request value that may arrive as an array, a list or a single value.
 *
 * Shared by every page that takes a set of IDs from a form or a query string.
 *
 * @param mixed $value Raw request value.
 *
 * @return array
 */
function normalizeRequestList($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value), function ($item) {
            return trim($item) !== '';
        }));
    }

    if ($value === null) {
        return [];
    }

    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    if (strpos($value, ',') !== false) {
        return array_values(array_filter(array_map('trim', explode(',', $value)), function ($item) {
            return $item !== '';
        }));
    }

    return [$value];
}

/**
 * Pad a school year term ID to the width the database stores.
 *
 * Request values arrive without the leading zeros, so they do not match
 * option keys read from gibbonSchoolYearTerm until they are padded.
 *
 * @param mixed $id Raw request value.
 *
 * @return string
 */
function normalizeSchoolYearTermID($id): string
{
    $id = trim((string) $id);

    if ($id === '' || !ctype_digit($id)) {
        return $id;
    }

    return strlen($id) >= 5 ? $id : str_pad($id, 5, '0', STR_PAD_LEFT);
}

function getSubjectsByYearGroups(
    $connection,
    array $yearGroupIDs,
    int $cycleID,
    array $studentIDs = [],
    bool $studentFilterOn = false
): array
{
    if (empty($yearGroupIDs) || empty($cycleID)) return [];

    $conditions = [];
    $params = ['cycleID' => $cycleID];
    $studentCondition = '';

    foreach (array_values($yearGroupIDs) as $i => $id) {
        $key = "yg$i";
        $conditions[] = "FIND_IN_SET(:$key, c.gibbonYearGroupIDList)";
        $params[$key] = normalizeYearGroupID($id);
    }

    if ($studentFilterOn && !empty($studentIDs)) {
        $studentPlaceholders = [];

        foreach (array_values($studentIDs) as $i => $id) {
            $key = "student$i";
            $studentPlaceholders[] = ":$key";
            $params[$key] = (int) $id;
        }

        $studentCondition = " AND EXISTS (
            SELECT 1
            FROM gibbonReportingValue rv
            WHERE rv.gibbonReportingCycleID = rc.gibbonReportingCycleID
              AND rv.gibbonCourseClassID IS NOT NULL
              AND rv.gibbonPersonIDStudent IN (" . implode(',', $studentPlaceholders) . ")
              AND rv.gibbonReportingCriteriaID = rc.gibbonReportingCriteriaID
        )";
    }

    $sql = "
        SELECT DISTINCT
            c.gibbonCourseID,
            c.nameShort
        FROM gibbonReportingCriteria rc
        JOIN gibbonCourse c
            ON c.gibbonCourseID = rc.gibbonCourseID
        WHERE rc.gibbonReportingCycleID = :cycleID
          AND rc.target = 'Per Student'
          AND (" . implode(' OR ', $conditions) . ")
          $studentCondition
        ORDER BY c.nameShort
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[$row['gibbonCourseID']] = $row['nameShort'];
    }

    return $results;
}

function buildStudentSelectionData(array $students, array $yearGroupIDs, array $studentIDs = []): array
{
    $source = [];
    $dest = [];
    $formGroups = [];

    foreach ($students as $student) {
        if (!in_array($student['gibbonYearGroupID'], $yearGroupIDs)) {
            continue;
        }

        $label = Format::name('', $student['preferredName'], $student['surname'], 'Student', true)
            . ' - ' . $student['formGroup'];

        $personID = (string) $student['gibbonPersonID'];
        $formGroups[$personID] = $student['formGroup'];

        if (in_array($personID, array_map('strval', $studentIDs), true) || in_array((int) $personID, $studentIDs, true)) {
            $dest[$personID] = $label;
        } else {
            $source[$personID] = $label;
        }
    }

    return [
        'source' => $source,
        'destination' => $dest,
        'formGroups' => $formGroups,
    ];
}

function renderCriteriaTableHtml(
    $connection,
    array $criteriaTypes,
    array $criteriaTypeIDs = [],
    array $valueSelections = [],
    array $valueSelectionsSaved = []
): string {
    if (empty($criteriaTypes)) {
        return '<div id="criteriaTableContainer"><div class="criteriaTable">'
            . '<div class="criteriaRow"><div class="criteriaCell criteriaCell-right">'
            . htmlspecialchars(__('No criteria are available for the current filter combination.'))
            . '</div></div></div></div>';
    }

    $html = '<div id="criteriaTableContainer"><div class="criteriaTable">';
    $html .= '<div class="criteriaHeaderRow">';
    $html .= '<div class="criteriaHeader-left"><strong>' . htmlspecialchars(__('Criteria')) . '</strong></div>';
    $html .= '<div class="criteriaHeader-right"><strong>' . htmlspecialchars(__('Values to store')) . '</strong></div>';
    $html .= '</div>';

    foreach ($criteriaTypes as $typeID => $typeData) {
        $typeID = (string) $typeID;
        $values = getScaleValues($connection, (int) $typeData['scaleID']);
        $blankLabel = $values['__BLANK__'] ?? '(Blank)';
        unset($values['__BLANK__']);
        $isChecked = empty($criteriaTypeIDs) || in_array($typeID, array_map('strval', $criteriaTypeIDs), true);

        $html .= '<div class="criteriaRow criteriaRow-tight">';
        $html .= '<div class="criteriaCell criteriaCell-left">';
        $html .= '<label>';
        $html .= '<input class="criteria-toggle" data-type="' . htmlspecialchars($typeID) . '" type="checkbox" name="criteriaTypeIDs[]" value="' . htmlspecialchars($typeID) . '"'
            . ($isChecked ? ' checked' : '') . '> ';
        $html .= htmlspecialchars($typeData['name']);
        $html .= '</label>';
        $html .= '</div>';

        $checkedValues =
            $valueSelections["values_{$typeID}"] ??
            $valueSelectionsSaved["valuesSaved_{$typeID}"] ??
            array_keys($values);

        $checkedValues = array_map('strval', $checkedValues);

        $html .= '<div class="criteriaCell criteriaCell-right">';
        $html .= '<div class="criteria-values criteria-values-' . htmlspecialchars($typeID) . '">';

        foreach ($values as $valueKey => $valueLabel) {
            $valueKey = (string) $valueKey;
            $isValueChecked = in_array($valueKey, $checkedValues, true);

            $html .= '<label>';
            $html .= '<input type="checkbox" name="values_' . htmlspecialchars($typeID) . '[]" value="' . htmlspecialchars($valueKey) . '"'
                . ($isValueChecked ? ' checked' : '')
                . ($isChecked ? '' : ' disabled')
                . '> ';
            $html .= htmlspecialchars($valueLabel);
            $html .= '</label>';
        }

        $isBlankChecked = in_array('__BLANK__', $checkedValues, true);
        $html .= '<label>';
        $html .= '<input type="checkbox" name="values_' . htmlspecialchars($typeID) . '[]" value="__BLANK__"'
            . ($isBlankChecked ? ' checked' : '')
            . ($isChecked ? '' : ' disabled')
            . '> ';
        $html .= htmlspecialchars($blankLabel);
        $html .= '</label>';

        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }

    $html .= '</div></div>';

    return $html;
}

function getStudentDataExportHeaders(): array
{
    // These must match the GL Assessment import template exactly. GL reject the
    // file and name the offending columns if any header differs.
    return [
        'Forename*',
        'Surname*',
        'Unique Identifier*',
        'Date Of Birth*',
        'Gender*',
        'Nationality',
        'Custom 1',
        'Custom 2',
        'English as an additional language',
        'External Reference',
        'Year',
        'Ethnicity',
        'SEND',
        'Group',
        'Date Joined School',
    ];
}

/**
 * Rows for the GL Assessment student export.
 *
 * @param PDO                   $connection      Database connection.
 * @param string|null           $joinedAfterDate Optional Y-m-d lower bound on
 *                                               p.dateStart. Null exports
 *                                               everyone enrolled in the
 *                                               current school year.
 * @param array<string,string>  $yearMap         gibbonYearGroupID to Testwise
 *                                               year, from
 *                                               buildTestwiseYearMap(). A year
 *                                               group missing from the map
 *                                               falls back to its Gibbon name.
 *
 * @return array
 */
function getStudentDataExportRows(PDO $connection, ?string $joinedAfterDate = null, array $yearMap = []): array
{
    $joinedAfterDate = trim((string) $joinedAfterDate);

    $dateFilter = $joinedAfterDate !== ''
        ? "AND p.dateStart IS NOT NULL AND p.dateStart > :joinedAfterDate"
        : '';

    $sql = "
        SELECT
            p.gibbonPersonID,
            p.firstName,
            p.preferredName,
            p.surname,
            p.studentID,
            p.username,
            p.dob,
            p.gender,
            p.countryOfBirth,
            p.languageFirst,
            p.ethnicity,
            p.dateStart,
            yg.gibbonYearGroupID,
            yg.name AS yearName,
            yg.nameShort AS yearNameShort,
            fg.nameShort AS formGroup,
            GROUP_CONCAT(DISTINCT CASE
                WHEN pdt.document = 'Passport' AND pd.country IS NOT NULL AND pd.country <> ''
                    THEN pd.country
                ELSE NULL
            END SEPARATOR '||') AS passportCountries,
            MAX(CASE WHEN ind.nameShort = 'EAL' THEN 1 ELSE 0 END) AS hasEAL,
            MAX(CASE WHEN ind.nameShort = 'SEN' THEN 1 ELSE 0 END) AS hasSEND
        FROM gibbonPerson p
        JOIN gibbonStudentEnrolment se
            ON se.gibbonPersonID = p.gibbonPersonID
        JOIN gibbonSchoolYear sy
            ON sy.gibbonSchoolYearID = se.gibbonSchoolYearID
        JOIN gibbonYearGroup yg
            ON yg.gibbonYearGroupID = se.gibbonYearGroupID
        JOIN gibbonFormGroup fg
            ON fg.gibbonFormGroupID = se.gibbonFormGroupID
        LEFT JOIN gibbonPersonalDocument pd
            ON pd.foreignTable = 'gibbonPerson'
            AND pd.foreignTableID = p.gibbonPersonID
            AND pd.country IS NOT NULL
            AND pd.country <> ''
        LEFT JOIN gibbonPersonalDocumentType pdt
            ON pdt.gibbonPersonalDocumentTypeID = pd.gibbonPersonalDocumentTypeID
        LEFT JOIN gibbonINPersonDescriptor ipd
            ON ipd.gibbonPersonID = p.gibbonPersonID
        LEFT JOIN gibbonINDescriptor ind
            ON ind.gibbonINDescriptorID = ipd.gibbonINDescriptorID
        WHERE sy.status = 'Current'
          AND p.status = 'Full'
          {$dateFilter}
        GROUP BY
            p.gibbonPersonID,
            p.firstName,
            p.preferredName,
            p.surname,
            p.studentID,
            p.username,
            p.dob,
            p.gender,
            p.countryOfBirth,
            p.languageFirst,
            p.ethnicity,
            p.dateStart,
            yg.gibbonYearGroupID,
            yg.name,
            yg.nameShort,
            yg.sequenceNumber,
            fg.nameShort
        ORDER BY yg.sequenceNumber, fg.nameShort, p.surname, p.firstName
    ";

    $stmt = $connection->prepare($sql);
    $stmt->execute($joinedAfterDate !== '' ? ['joinedAfterDate' => $joinedAfterDate] : []);

    $rows = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            resolveStudentExportForename($row),
            formatStudentExportValue($row['surname'] ?? ''),
            resolveStudentExportIdentifier($row),
            formatStudentExportDate($row['dob'] ?? null),
            mapStudentExportGender($row['gender'] ?? ''),
            testwiseValidNationality(resolveStudentExportNationality($row)),
            '',
            '',
            resolveStudentExportEAL($row),
            '',
            formatStudentExportValue($yearMap[(string) ($row['gibbonYearGroupID'] ?? '')] ?? ($row['yearName'] ?? '')),
            testwiseValidEthnicity((string) ($row['ethnicity'] ?? '')),
            !empty($row['hasSEND']) ? testwiseValidSend('Yes') : '',
            formatStudentExportValue($row['formGroup'] ?? ''),
            resolveStudentExportJoinDate($row),
        ];
    }

    return $rows;
}

/**
 * Work out the Testwise year to export for every year group.
 *
 * A confirmed mapping always wins. Where none exists the region rule supplies
 * a value, and where the rule cannot read a number the year group is left out
 * of the map so the caller can report it.
 *
 * @param string               $region     One of the TESTWISE_REGION_* values.
 * @param array                $yearGroups Rows from YearGroupMapGateway::selectYearGroups().
 * @param array<string,string> $storedMap  Rows from YearGroupMapGateway::selectMapKeyed().
 *
 * @return array<string,string> gibbonYearGroupID to Testwise year.
 */
function buildTestwiseYearMap(string $region, array $yearGroups, array $storedMap): array
{
    $map = [];
    $suggestions = testwiseSuggestYearMap($region, $yearGroups);

    foreach ($yearGroups as $yearGroup) {
        $yearGroupID = (string) $yearGroup['gibbonYearGroupID'];
        $stored = trim((string) ($storedMap[$yearGroupID] ?? ''));

        if ($stored !== '' && testwiseIsValidYear($stored)) {
            $map[$yearGroupID] = $stored;
            continue;
        }

        if (!empty($suggestions[$yearGroupID])) {
            $map[$yearGroupID] = $suggestions[$yearGroupID];
        }
    }

    return $map;
}

/**
 * Year groups that have students but no confirmed Testwise mapping.
 *
 * Used to warn before an export, because an unconfirmed year group either
 * exports a guess or falls back to its raw Gibbon name.
 *
 * @param PDO                  $connection Database connection.
 * @param array<string,string> $storedMap  Rows from YearGroupMapGateway::selectMapKeyed().
 *
 * @return array List of year group names, in teaching order.
 */
function getUnconfirmedExportYearGroups(PDO $connection, array $storedMap): array
{
    $sql = "SELECT DISTINCT yg.gibbonYearGroupID, yg.name, yg.sequenceNumber
            FROM gibbonYearGroup yg
            JOIN gibbonStudentEnrolment se
                ON se.gibbonYearGroupID = yg.gibbonYearGroupID
            JOIN gibbonSchoolYear sy
                ON sy.gibbonSchoolYearID = se.gibbonSchoolYearID
            JOIN gibbonPerson p
                ON p.gibbonPersonID = se.gibbonPersonID
            WHERE sy.status = 'Current'
              AND p.status = 'Full'
            ORDER BY yg.sequenceNumber, yg.name";

    $stmt = $connection->prepare($sql);
    $stmt->execute();

    $unconfirmed = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $yearGroupID = (string) $row['gibbonYearGroupID'];
        $stored = trim((string) ($storedMap[$yearGroupID] ?? ''));

        if ($stored === '' || !testwiseIsValidYear($stored)) {
            $unconfirmed[] = (string) $row['name'];
        }
    }

    return $unconfirmed;
}

/**
 * The single forename to export.
 *
 * GL reject a Forename holding more than one name. Gibbon's preferredName is
 * the name a student goes by and is almost always a single name, so it is used
 * ahead of firstName, which often holds every given name. Only the first word
 * is exported either way.
 *
 * @param array $row Student row from the export query.
 *
 * @return string
 */
function resolveStudentExportForename(array $row): string
{
    $candidates = [
        trim((string) ($row['preferredName'] ?? '')),
        trim((string) ($row['firstName'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        // Split on whitespace only. Hyphenated and apostrophed names such as
        // Anne-Marie or O'Neill are single names and must stay whole.
        $parts = preg_split('/\s+/', $candidate);

        if (!empty($parts[0])) {
            return formatStudentExportValue($parts[0]);
        }
    }

    return '';
}

/**
 * The date joined school, dropped when it cannot be true.
 *
 * GL reject a row whose joining date falls on or before the date of birth.
 * Gibbon allows a start date that predates a student's birth, usually from a
 * bad import, so an impossible date is exported blank instead.
 *
 * @param array $row Student row from the export query.
 *
 * @return string
 */
function resolveStudentExportJoinDate(array $row): string
{
    $joined = formatStudentExportDate($row['dateStart'] ?? null);
    $dob = trim((string) ($row['dob'] ?? ''));

    if ($joined === '' || $dob === '') {
        return $joined;
    }

    $joinedDate = substr(trim((string) $row['dateStart']), 0, 10);

    return $joinedDate > substr($dob, 0, 10) ? $joined : '';
}

function resolveStudentExportIdentifier(array $row): string
{
    $studentID = trim((string) ($row['studentID'] ?? ''));
    if ($studentID !== '') {
        return $studentID;
    }

    $username = trim((string) ($row['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }

    return (string) ($row['gibbonPersonID'] ?? '');
}

/**
 * Format a date for the GL Assessment export.
 *
 * GL expect DD/MM/YYYY and reject MM/DD/YYYY. The source is always a MySQL
 * Y-m-d date column, so it is read explicitly rather than left to
 * DateTimeImmutable to guess a format from.
 *
 * @param string|null $value A Y-m-d date, or null.
 *
 * @return string DD/MM/YYYY, or an empty string if there is no usable date.
 */
function formatStudentExportDate(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));

    return $date === false ? '' : $date->format('d/m/Y');
}

function mapStudentExportGender(string $gender): string
{
    $gender = trim($gender);

    return match ($gender) {
        'M' => 'Male',
        'F' => 'Female',
        'Other' => 'Other',
        default => 'Unspecified',
    };
}

function resolveStudentExportNationality(array $row): string
{
    $sourceCountry = '';
    $passportCountries = trim((string) ($row['passportCountries'] ?? ''));

    if ($passportCountries !== '') {
        $sourceCountry = trim((string) explode('||', $passportCountries)[0]);
    }

    if ($sourceCountry === '') {
        $sourceCountry = trim((string) ($row['countryOfBirth'] ?? ''));
    }

    if ($sourceCountry === '') {
        return '';
    }

    $mapped = mapCountryToNationality($sourceCountry);

    return $mapped !== '' ? $mapped : $sourceCountry;
}

function resolveStudentExportEAL(array $row): string
{
    if (!empty($row['hasEAL'])) {
        return 'Yes';
    }

    $languageFirst = strtolower(trim((string) ($row['languageFirst'] ?? '')));
    if ($languageFirst === '' || $languageFirst === 'english') {
        return 'No';
    }

    return 'Yes';
}

function formatStudentExportValue(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function mapCountryToNationality(string $country): string
{
    $lookup = [
        'afghanistan' => 'Afghan',
        'albania' => 'Albanian',
        'algeria' => 'Algerian',
        'argentina' => 'Argentine',
        'armenia' => 'Armenian',
        'australia' => 'Australian',
        'austria' => 'Austrian',
        'azerbaijan' => 'Azerbaijani',
        'bahrain' => 'Bahraini',
        'bangladesh' => 'Bangladeshi',
        'belarus' => 'Belarusian',
        'belgium' => 'Belgian',
        'bolivia' => 'Bolivian',
        'bosnia and herzegovina' => 'Bosnian',
        'botswana' => 'Botswanan',
        'brazil' => 'Brazilian',
        'britain' => 'British',
        'bulgaria' => 'Bulgarian',
        'cambodia' => 'Cambodian',
        'cameroon' => 'Cameroonian',
        'canada' => 'Canadian',
        'chile' => 'Chilean',
        'china' => 'Chinese',
        'colombia' => 'Colombian',
        'croatia' => 'Croatian',
        'cuba' => 'Cuban',
        'cyprus' => 'Cypriot',
        'czech republic' => 'Czech',
        'denmark' => 'Danish',
        'dominican republic' => 'Dominican',
        'ecuador' => 'Ecuadorian',
        'egypt' => 'Egyptian',
        'england' => 'English',
        'eritrea' => 'Eritrean',
        'estonia' => 'Estonian',
        'ethiopia' => 'Ethiopian',
        'finland' => 'Finnish',
        'france' => 'French',
        'gambia' => 'Gambian',
        'georgia' => 'Georgian',
        'germany' => 'German',
        'ghana' => 'Ghanaian',
        'greece' => 'Greek',
        'guatemala' => 'Guatemalan',
        'hong kong' => 'Hong Konger',
        'hungary' => 'Hungarian',
        'iceland' => 'Icelandic',
        'india' => 'Indian',
        'indonesia' => 'Indonesian',
        'iran' => 'Iranian',
        'iraq' => 'Iraqi',
        'ireland' => 'Irish',
        'israel' => 'Israeli',
        'italy' => 'Italian',
        'ivory coast' => 'Ivorian',
        'jamaica' => 'Jamaican',
        'japan' => 'Japanese',
        'jordan' => 'Jordanian',
        'kazakhstan' => 'Kazakh',
        'kenya' => 'Kenyan',
        'kuwait' => 'Kuwaiti',
        'latvia' => 'Latvian',
        'lebanon' => 'Lebanese',
        'libya' => 'Libyan',
        'lithuania' => 'Lithuanian',
        'luxembourg' => 'Luxembourger',
        'malaysia' => 'Malaysian',
        'maldives' => 'Maldivan',
        'malta' => 'Maltese',
        'mauritius' => 'Mauritian',
        'mexico' => 'Mexican',
        'morocco' => 'Moroccan',
        'myanmar' => 'Burmese',
        'nepal' => 'Nepalese',
        'netherlands' => 'Dutch',
        'new zealand' => 'New Zealander',
        'nigeria' => 'Nigerian',
        'norway' => 'Norwegian',
        'oman' => 'Omani',
        'pakistan' => 'Pakistani',
        'palestine' => 'Palestinian',
        'peru' => 'Peruvian',
        'philippines' => 'Filipino',
        'poland' => 'Polish',
        'portugal' => 'Portuguese',
        'qatar' => 'Qatari',
        'romania' => 'Romanian',
        'russia' => 'Russian',
        'saudi arabia' => 'Saudi',
        'scotland' => 'Scottish',
        'senegal' => 'Senegalese',
        'serbia' => 'Serbian',
        'singapore' => 'Singaporean',
        'slovakia' => 'Slovak',
        'slovenia' => 'Slovenian',
        'somalia' => 'Somali',
        'south africa' => 'South African',
        'south korea' => 'South Korean',
        'spain' => 'Spanish',
        'sri lanka' => 'Sri Lankan',
        'sudan' => 'Sudanese',
        'sweden' => 'Swedish',
        'switzerland' => 'Swiss',
        'syria' => 'Syrian',
        'taiwan' => 'Taiwanese',
        'tanzania' => 'Tanzanian',
        'thailand' => 'Thai',
        'tunisia' => 'Tunisian',
        'turkey' => 'Turkish',
        'uganda' => 'Ugandan',
        'uk' => 'British',
        'ukraine' => 'Ukrainian',
        'united arab emirates' => 'Emirati',
        'united kingdom' => 'British',
        'united states' => 'American',
        'united states of america' => 'American',
        'uruguay' => 'Uruguayan',
        'uzbekistan' => 'Uzbek',
        'venezuela' => 'Venezuelan',
        'vietnam' => 'Vietnamese',
        'wales' => 'Welsh',
        'yemen' => 'Yemeni',
        'zambia' => 'Zambian',
        'zimbabwe' => 'Zimbabwean',
    ];

    $key = strtolower(trim($country));

    return $lookup[$key] ?? '';
}

function ar_buildModuleBackup(PDO $connection): array
{
    $settingsStmt = $connection->prepare(
        "SELECT scope, name, nameDisplay, description, value
         FROM gibbonSetting
         WHERE scope = 'Academic Records'
         ORDER BY name"
    );
    $settingsStmt->execute();
    $settings = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);

    $mappingStmt = $connection->prepare(
        "SELECT
            academicRecordsCAT4MappingID,
            gibbonExternalAssessmentID,
            name,
            studentMatchField,
            studentIdentifierHeaderPattern,
            dateHeaderPattern,
            importCategories,
            active,
            isDefault
         FROM academicRecordsCAT4Mapping
         ORDER BY gibbonExternalAssessmentID, academicRecordsCAT4MappingID"
    );
    $mappingStmt->execute();
    $mappings = $mappingStmt->fetchAll(PDO::FETCH_ASSOC);

    $fieldStmt = $connection->prepare(
        "SELECT
            academicRecordsCAT4MappingID,
            gibbonExternalAssessmentFieldID,
            headerPattern,
            active
         FROM academicRecordsCAT4MappingField
         ORDER BY academicRecordsCAT4MappingID, gibbonExternalAssessmentFieldID"
    );
    $fieldStmt->execute();
    $fields = $fieldStmt->fetchAll(PDO::FETCH_ASSOC);

    // Year group names travel with the mapping, because gibbonYearGroupID is
    // specific to one install and will not line up on another.
    $yearGroupStmt = $connection->prepare(
        "SELECT
            m.gibbonYearGroupID,
            yg.name AS yearGroupName,
            yg.nameShort AS yearGroupNameShort,
            m.testwiseYear
         FROM academicRecordsYearGroupMap m
         LEFT JOIN gibbonYearGroup yg
            ON yg.gibbonYearGroupID = m.gibbonYearGroupID
         ORDER BY yg.sequenceNumber, m.gibbonYearGroupID"
    );
    $yearGroupStmt->execute();
    $yearGroupMappings = $yearGroupStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'module' => 'Academic Records',
        'format' => 2,
        'exportedAt' => date('c'),
        'settings' => array_map(static function (array $row): array {
            return [
                'scope' => (string) ($row['scope'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'nameDisplay' => (string) ($row['nameDisplay'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'value' => (string) ($row['value'] ?? ''),
            ];
        }, $settings),
        'cat4Mappings' => array_map(static function (array $row): array {
            return [
                'academicRecordsCAT4MappingID' => (int) ($row['academicRecordsCAT4MappingID'] ?? 0),
                'gibbonExternalAssessmentID' => (int) ($row['gibbonExternalAssessmentID'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'studentMatchField' => (string) ($row['studentMatchField'] ?? 'studentID'),
                'studentIdentifierHeaderPattern' => (string) ($row['studentIdentifierHeaderPattern'] ?? 'Student ID'),
                'dateHeaderPattern' => (string) ($row['dateHeaderPattern'] ?? 'Date of test'),
                'importCategories' => (string) ($row['importCategories'] ?? '[]'),
                'active' => (string) ($row['active'] ?? 'Y'),
                'isDefault' => (string) ($row['isDefault'] ?? 'N'),
            ];
        }, $mappings),
        'cat4MappingFields' => array_map(static function (array $row): array {
            return [
                'academicRecordsCAT4MappingID' => (int) ($row['academicRecordsCAT4MappingID'] ?? 0),
                'gibbonExternalAssessmentFieldID' => (int) ($row['gibbonExternalAssessmentFieldID'] ?? 0),
                'headerPattern' => (string) ($row['headerPattern'] ?? ''),
                'active' => (string) ($row['active'] ?? 'Y'),
            ];
        }, $fields),
        'yearGroupMappings' => array_map(static function (array $row): array {
            return [
                'gibbonYearGroupID' => (string) ($row['gibbonYearGroupID'] ?? ''),
                'yearGroupName' => (string) ($row['yearGroupName'] ?? ''),
                'yearGroupNameShort' => (string) ($row['yearGroupNameShort'] ?? ''),
                'testwiseYear' => (string) ($row['testwiseYear'] ?? ''),
            ];
        }, $yearGroupMappings),
    ];
}

function ar_validateModuleBackup($payload): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    if (($payload['module'] ?? '') !== 'Academic Records') {
        return null;
    }

    // Restore whatever the file happens to hold. A block that is absent or
    // empty is left alone on restore, and an unusable row inside a block is
    // dropped, so a school that never set up CAT4 or Testwise year groups can
    // still move its settings between installs.
    $blocks = [
        'settings' => 'ar_isValidBackupSetting',
        'cat4Mappings' => 'ar_isValidBackupMapping',
        'cat4MappingFields' => 'ar_isValidBackupMappingField',
        'yearGroupMappings' => 'ar_isValidBackupYearGroup',
    ];

    $backup = [];

    foreach ($blocks as $key => $isValid) {
        $rows = is_array($payload[$key] ?? null) ? $payload[$key] : [];
        $backup[$key] = array_values(array_filter($rows, $isValid));
    }

    return $backup;
}

function ar_isValidBackupSetting($setting): bool
{
    return is_array($setting)
        && ($setting['scope'] ?? '') === 'Academic Records'
        && !empty($setting['name']);
}

function ar_isValidBackupMapping($mapping): bool
{
    return is_array($mapping)
        && !empty($mapping['gibbonExternalAssessmentID'])
        && !empty($mapping['name']);
}

function ar_isValidBackupMappingField($field): bool
{
    return is_array($field)
        && !empty($field['academicRecordsCAT4MappingID'])
        && !empty($field['gibbonExternalAssessmentFieldID']);
}

function ar_isValidBackupYearGroup($yearGroup): bool
{
    if (!is_array($yearGroup)) {
        return false;
    }

    $hasIdentity = !empty($yearGroup['yearGroupName'])
        || !empty($yearGroup['yearGroupNameShort'])
        || !empty($yearGroup['gibbonYearGroupID']);

    return $hasIdentity && testwiseIsValidYear((string) ($yearGroup['testwiseYear'] ?? ''));
}

function ar_upsertSettingRow(PDO $connection, array $setting): bool
{
    $sql = "
        INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
        VALUES (:scope, :name, :nameDisplay, :description, :value)
        ON DUPLICATE KEY UPDATE
            nameDisplay = VALUES(nameDisplay),
            description = VALUES(description),
            value = VALUES(value)
    ";

    $stmt = $connection->prepare($sql);

    return $stmt->execute([
        'scope' => (string) ($setting['scope'] ?? 'Academic Records'),
        'name' => (string) ($setting['name'] ?? ''),
        'nameDisplay' => (string) ($setting['nameDisplay'] ?? ''),
        'description' => (string) ($setting['description'] ?? ''),
        'value' => (string) ($setting['value'] ?? ''),
    ]);
}

function ar_restoreModuleBackup(PDO $connection, array $backup, int $actorID): bool
{
    $connection->beginTransaction();

    try {
        foreach ($backup['settings'] as $setting) {
            if (!ar_upsertSettingRow($connection, $setting)) {
                throw new RuntimeException('Failed to upsert module setting.');
            }
        }

        // Only replace a table when the backup actually carried rows for it.
        // A file with no CAT4 or no year group data leaves those tables alone
        // rather than emptying them, so restoring a partial backup can never
        // destroy configuration the file says nothing about.
        if (!empty($backup['cat4Mappings'])) {
            ar_restoreCat4Mappings($connection, $backup, $actorID);
        }

        if (!empty($backup['yearGroupMappings'])) {
            ar_restoreYearGroupMappings($connection, $backup);
        }

        $connection->commit();

        return true;
    } catch (Throwable $e) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        return false;
    }
}

function ar_restoreCat4Mappings(PDO $connection, array $backup, int $actorID): void
{
    $connection->exec('DELETE FROM academicRecordsCAT4MappingField');
        $connection->exec('DELETE FROM academicRecordsCAT4Mapping');

        $mappingIdMap = [];
        $insertMapping = $connection->prepare(
            "INSERT INTO academicRecordsCAT4Mapping
            (
                gibbonExternalAssessmentID,
                name,
                studentMatchField,
                studentIdentifierHeaderPattern,
                dateHeaderPattern,
                importCategories,
                active,
                isDefault,
                timestampCreated,
                timestampUpdated,
                gibbonPersonIDCreated,
                gibbonPersonIDUpdated
            )
            VALUES
            (
                :gibbonExternalAssessmentID,
                :name,
                :studentMatchField,
                :studentIdentifierHeaderPattern,
                :dateHeaderPattern,
                :importCategories,
                :active,
                :isDefault,
                :timestampCreated,
                :timestampUpdated,
                :gibbonPersonIDCreated,
                :gibbonPersonIDUpdated
            )"
        );

        foreach ($backup['cat4Mappings'] as $mapping) {
            $legacyID = (int) ($mapping['academicRecordsCAT4MappingID'] ?? 0);
            $timestamp = date('Y-m-d H:i:s');

            $insertMapping->execute([
                'gibbonExternalAssessmentID' => (int) ($mapping['gibbonExternalAssessmentID'] ?? 0),
                'name' => (string) ($mapping['name'] ?? ''),
                'studentMatchField' => (string) ($mapping['studentMatchField'] ?? 'studentID'),
                'studentIdentifierHeaderPattern' => (string) ($mapping['studentIdentifierHeaderPattern'] ?? 'Student ID'),
                'dateHeaderPattern' => (string) ($mapping['dateHeaderPattern'] ?? 'Date of test'),
                'importCategories' => (string) ($mapping['importCategories'] ?? '[]'),
                'active' => (($mapping['active'] ?? 'Y') === 'N') ? 'N' : 'Y',
                'isDefault' => (($mapping['isDefault'] ?? 'N') === 'Y') ? 'Y' : 'N',
                'timestampCreated' => $timestamp,
                'timestampUpdated' => $timestamp,
                'gibbonPersonIDCreated' => $actorID > 0 ? $actorID : null,
                'gibbonPersonIDUpdated' => $actorID > 0 ? $actorID : null,
            ]);

            $mappingIdMap[$legacyID] = (int) $connection->lastInsertId();
        }

        $insertField = $connection->prepare(
            "INSERT INTO academicRecordsCAT4MappingField
            (
                academicRecordsCAT4MappingID,
                gibbonExternalAssessmentFieldID,
                headerPattern,
                active
            )
            VALUES
            (
                :academicRecordsCAT4MappingID,
                :gibbonExternalAssessmentFieldID,
                :headerPattern,
                :active
            )"
        );

        foreach ($backup['cat4MappingFields'] as $field) {
            $legacyMappingID = (int) ($field['academicRecordsCAT4MappingID'] ?? 0);
            $newMappingID = $mappingIdMap[$legacyMappingID] ?? 0;

            if ($newMappingID <= 0) {
                continue;
            }

            $insertField->execute([
                'academicRecordsCAT4MappingID' => $newMappingID,
                'gibbonExternalAssessmentFieldID' => (int) ($field['gibbonExternalAssessmentFieldID'] ?? 0),
                'headerPattern' => (string) ($field['headerPattern'] ?? ''),
                'active' => (($field['active'] ?? 'Y') === 'N') ? 'N' : 'Y',
            ]);
    }
}

function ar_restoreYearGroupMappings(PDO $connection, array $backup): void
{
    $connection->exec('DELETE FROM academicRecordsYearGroupMap');

    // Match on the year group's own names first, so a backup restores onto
    // another install where the IDs differ. A year group that no longer
    // exists is skipped rather than failing the whole restore.
    $lookupStmt = $connection->prepare(
        "SELECT gibbonYearGroupID, name, nameShort FROM gibbonYearGroup"
    );
    $lookupStmt->execute();

    $byName = [];
    $byNameShort = [];
    $byID = [];

    foreach ($lookupStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowID = (string) $row['gibbonYearGroupID'];
        $byID[$rowID] = $rowID;
        $byName[strtolower(trim((string) $row['name']))] = $rowID;
        $byNameShort[strtolower(trim((string) $row['nameShort']))] = $rowID;
    }

    $insertYearGroup = $connection->prepare(
        "INSERT INTO academicRecordsYearGroupMap
            (gibbonYearGroupID, testwiseYear)
        VALUES
            (:gibbonYearGroupID, :testwiseYear)
        ON DUPLICATE KEY UPDATE testwiseYear = VALUES(testwiseYear)"
    );

    foreach ($backup['yearGroupMappings'] as $yearGroup) {
        $name = strtolower(trim((string) ($yearGroup['yearGroupName'] ?? '')));
        $nameShort = strtolower(trim((string) ($yearGroup['yearGroupNameShort'] ?? '')));
        $legacyID = (string) ($yearGroup['gibbonYearGroupID'] ?? '');

        $targetID = $byName[$name]
            ?? $byNameShort[$nameShort]
            ?? $byID[$legacyID]
            ?? null;

        if ($targetID === null) {
            continue;
        }

        $insertYearGroup->execute([
            'gibbonYearGroupID' => $targetID,
            'testwiseYear' => (string) ($yearGroup['testwiseYear'] ?? ''),
        ]);
    }
}
