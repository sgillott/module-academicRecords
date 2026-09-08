<?php
namespace Gibbon\Module\AcademicRecords\Domain;

use PDO;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;

class AcademicRecordsGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonReportingValue';
    private static $primaryKey = 'gibbonReportingValueID';

    /* ---------------------------------------------------------
       QUERY ELIGIBLE REPORTING GRADES (Step 2 + Dry Run)
    --------------------------------------------------------- */

    public function queryEligibleReportingGrades(
        QueryCriteria $criteria,
        int $cycleID,
        array $filters = []
    ) {
        $query = $this->newQuery()
            ->from('gibbonReportingValue AS rv')
            ->cols([
                'rv.gibbonReportingValueID',
                'rv.gibbonCourseClassID',
                'rv.gibbonReportingCriteriaID',
                'rv.timestampCreated',
                'rv.timestampModified',
                "CONCAT(p.preferredName, ' ', p.surname) AS studentName",
                'p.surname',
                'p.preferredName',
                'p.gibbonPersonID AS gibbonPersonIDStudent',
                'c.nameShort AS courseName',
                'cc.nameShort AS className',
                'rc.name AS criteriaName',
                'rct.gibbonReportingCriteriaTypeID AS criteriaTypeID',
                'rct.name AS criteriaTypeName',
                'rct.gibbonScaleID AS scaleID',
                'rv.value',
            ])
            ->innerJoin('gibbonReportingCriteria AS rc', 'rc.gibbonReportingCriteriaID = rv.gibbonReportingCriteriaID')
            ->innerJoin('gibbonReportingCriteriaType AS rct', 'rct.gibbonReportingCriteriaTypeID = rc.gibbonReportingCriteriaTypeID')
            ->innerJoin('gibbonCourseClass AS cc', 'cc.gibbonCourseClassID = rv.gibbonCourseClassID')
            ->innerJoin('gibbonCourse AS c', 'c.gibbonCourseID = cc.gibbonCourseID')
            ->innerJoin('gibbonPerson AS p', 'p.gibbonPersonID = rv.gibbonPersonIDStudent')
            ->where('rv.gibbonReportingCycleID = :cycleID')
            ->where("rct.valueType = 'Grade Scale'")
            ->where("rc.target = 'Per Student'")
            ->bindValue('cycleID', $cycleID, PDO::PARAM_INT);

        /* ---------------------------
           Year Group Filter
        --------------------------- */

        if (
            ($filters['filterYearGroups'] ?? 'N') === 'Y'
            && !empty($filters['gibbonYearGroupIDList'])
            && is_array($filters['gibbonYearGroupIDList'])
        ) {
            $conditions = [];
            foreach (array_values($filters['gibbonYearGroupIDList']) as $i => $ygID) {
                $key = "yg{$i}";
                $conditions[] = "FIND_IN_SET(:$key, c.gibbonYearGroupIDList)";
                $query->bindValue($key, $this->normalizeYearGroupID($ygID), PDO::PARAM_STR);
            }
            if (!empty($conditions)) {
                $query->where('(' . implode(' OR ', $conditions) . ')');
            }
        }

        /* ---------------------------
           Student Filter
        --------------------------- */

        if (
            ($filters['filterStudents'] ?? 'N') === 'Y'
            && !empty($filters['studentIDs'])
            && is_array($filters['studentIDs'])
        ) {
            $conditions = [];
            foreach (array_values($filters['studentIDs']) as $i => $id) {
                $key = "stu{$i}";
                $conditions[] = "rv.gibbonPersonIDStudent = :$key";
                $query->bindValue($key, (int) $id, PDO::PARAM_INT);
            }
            if (!empty($conditions)) {
                $query->where('(' . implode(' OR ', $conditions) . ')');
            }
        }

        /* ---------------------------
           Subject Filter
        --------------------------- */

        if (
            ($filters['filterSubjects'] ?? 'N') === 'Y'
            && !empty($filters['subjectIDs'])
            && is_array($filters['subjectIDs'])
        ) {
            $conditions = [];
            foreach (array_values($filters['subjectIDs']) as $i => $id) {
                $key = "sub{$i}";
                $conditions[] = "c.gibbonCourseID = :$key";
                $query->bindValue($key, (int) $id, PDO::PARAM_INT);
            }
            if (!empty($conditions)) {
                $query->where('(' . implode(' OR ', $conditions) . ')');
            }
        }

        /* ---------------------------
           Criteria Type Filter
        --------------------------- */

        if (!empty($filters['criteriaTypeIDs']) && is_array($filters['criteriaTypeIDs'])) {
            $placeholders = [];
            foreach (array_values($filters['criteriaTypeIDs']) as $i => $typeID) {
                $key = "ct{$i}";
                $placeholders[] = ":$key";
                $query->bindValue($key, (int) $typeID, PDO::PARAM_INT);
            }
            if (!empty($placeholders)) {
                $query->where('rct.gibbonReportingCriteriaTypeID IN (' . implode(',', $placeholders) . ')');
            }
        }

        /* ---------------------------
           Criteria Value Filter (values_{typeID}[])
        --------------------------- */

        if (!empty($filters['criteriaTypeIDs']) && is_array($filters['criteriaTypeIDs'])) {
            $orBlocks = [];

            foreach (array_values($filters['criteriaTypeIDs']) as $typeIDRaw) {
                $typeID = (int) $typeIDRaw;
                $valueKey = 'values_' . (string) $typeIDRaw;

                if (empty($filters[$valueKey]) || !is_array($filters[$valueKey])) {
                    continue;
                }

                $parts = [];
                $hasBlank = false;

                foreach (array_values($filters[$valueKey]) as $i => $val) {
                    if ($val === '__BLANK__') {
                        $hasBlank = true;
                        continue;
                    }
                    $p = "v{$typeID}_{$i}";
                    $parts[] = "rv.value = :$p";
                    $query->bindValue($p, (string) $val, PDO::PARAM_STR);
                }

                if ($hasBlank) {
                    $parts[] = "(rv.value IS NULL OR rv.value = '')";
                }

                if (!empty($parts)) {
                    $t = "tval{$typeID}";
                    $query->bindValue($t, $typeID, PDO::PARAM_INT);
                    $orBlocks[] = '(rct.gibbonReportingCriteriaTypeID = :' . $t . ' AND (' . implode(' OR ', $parts) . '))';
                }
            }

            if (!empty($orBlocks)) {
                $query->where('(' . implode(' OR ', $orBlocks) . ')');
            }
        }

        $criteria->sortBy(['p.surname', 'p.preferredName', 'c.nameShort', 'rc.name']);

        return $this->runQuery($query, $criteria);
    }

    public function selectEligibleReportingGradesForDryRun(int $cycleID, array $filters = []): array
    {
        $criteria = $this->newQueryCriteria(true)->pageSize(1000000);
        return $this->queryEligibleReportingGrades($criteria, $cycleID, $filters)->toArray();
    }

    /* ---------------------------------------------------------
       STORE / DRY RUN
    --------------------------------------------------------- */

    public function dryRunStoreReportingGrades(int $cycleID, array $filters, int $actorID): array
    {
        // NOTE: In this environment $this->db() returns a Gibbon\Database\Connection wrapper, not PDO.
        $pdo = $this->db();

        $cycleSelect = $this->newSelect()
            ->from('gibbonReportingCycle AS rc')
            ->cols([
                'rc.name',
                'rc.nameShort',
                'rc.dateEnd',
                'sy.name AS schoolYearName',
            ])
            ->innerJoin('gibbonSchoolYear AS sy', 'sy.gibbonSchoolYearID = rc.gibbonSchoolYearID')
            ->where('rc.gibbonReportingCycleID = :cycleID')
            ->bindValue('cycleID', $cycleID, PDO::PARAM_INT);

        $cycle = $this->runSelect($cycleSelect)->fetch();

        if (!$cycle) {
            return ['summary' => ['eligibleRows' => 0]];
        }

        $cycleShort = trim(($cycle['nameShort'] ?? '') !== '' ? $cycle['nameShort'] : ($cycle['name'] ?? ''));
        $schoolYear = (string) ($cycle['schoolYearName'] ?? '');
        $completeDate = $cycle['dateEnd'] ?? null;

        $columnName = mb_substr(trim($schoolYear . ' ' . $cycleShort . ' Final Grade'), 0, 30);
        $columnDescription = 'Stored Final Grade from ' . $schoolYear . ' ' . $cycleShort;

        $type = $this->getSetting('internalAssessmentType');
        $viewStudents = $this->getSetting('viewableStudents') ?: 'Y';
        $viewParents  = $this->getSetting('viewableParents') ?: 'Y';

        if (empty($type)) {
            return [
                'importSuccess' => true,
                'buildSuccess' => false,
                'databaseSuccess' => false,
                'rows' => 0,
                'rowerrors' => 1,
                'errors' => 1,
                'warnings' => 0,
                'inserts' => 0,
                'inserts_skipped' => 0,
                'updates' => 0,
                'updates_skipped' => 0,
                'columnsToCreate' => 0,
                'noChange' => 0,
                'lastError' => 'Academic Records Settings are incomplete: Internal Assessment Type is required.',
                'summary' => ['eligibleRows' => 0],
            ];
        }

        $rows = $this->selectEligibleReportingGradesForDryRun($cycleID, $filters);

        $planWarnings = [];
        $groupedRows = [];
        foreach ($rows as $row) {
            $classID = (int) $row['gibbonCourseClassID'];
            $studentID = (int) $row['gibbonPersonIDStudent'];
            $targetField = $this->resolveTargetField(
                (string) ($row['criteriaTypeName'] ?? ''),
                (string) ($row['criteriaName'] ?? '')
            );
            $sourceDate = $this->resolveSourceDate($row);

            $groupedRows[$classID]['rows'][] = $row;
            $groupedRows[$classID]['fieldScales'][$targetField] = (int) ($row['scaleID'] ?? 0);
            $groupedRows[$classID]['sourceDates'][] = $sourceDate;

            if (!isset($groupedRows[$classID]['students'][$studentID])) {
                $groupedRows[$classID]['students'][$studentID] = [
                    'studentID' => $studentID,
                    'studentName' => (string) ($row['studentName'] ?? ''),
                    'courseName' => (string) ($row['courseName'] ?? ''),
                    'className' => (string) ($row['className'] ?? ''),
                    'values' => [
                        'attainmentValue' => null,
                        'effortValue' => null,
                    ],
                    'sourceRows' => [],
                ];
            }

            if ($groupedRows[$classID]['students'][$studentID]['values'][$targetField] !== null) {
                $planWarnings[] = 'Multiple reporting rows were found for the same class/student/target field combination (' . $classID . ':' . $studentID . ':' . $targetField . '). The last matching row will be used.';
            }

            $groupedRows[$classID]['students'][$studentID]['values'][$targetField] = (string) ($row['value'] ?? '');
            $groupedRows[$classID]['students'][$studentID]['sourceRows'][] = $row + ['targetField' => $targetField];
        }

        $groupingID = (int) $this->runSelect(
            $this->newSelect()
                ->from('gibbonInternalAssessmentColumn')
                ->cols(['COALESCE(MAX(groupingID),0)+1 AS nextGrouping'])
        )->fetchColumn();

        if ($groupingID <= 0) {
            $groupingID = 1;
        }

        $columnsToCreate = 0;
        $entriesToInsert = 0;
        $entriesToUpdate = 0;
        $entriesNoChange = 0;
        $storagePlan = [];

        $isLive = ($filters['step'] ?? 3) == 4;

        if ($isLive) {
            $pdo->beginTransaction();
        }

        try {

            foreach ($groupedRows as $classID => $classData) {
                $classSourceDates = array_values(array_filter(array_unique($classData['sourceDates'] ?? [])));
                $completeDate = $cycle['dateEnd'] ?? null;

                if (!empty($classSourceDates)) {
                    sort($classSourceDates);
                    $completeDate = end($classSourceDates);

                    if (count($classSourceDates) > 1) {
                        $planWarnings[] = 'Multiple source dates were found for class ' . $classID . '. The most recent reporting row date (' . $completeDate . ') will be used as the Internal Assessment complete date.';
                    }
                }

                $requestedAttainmentScaleID = (int) ($classData['fieldScales']['attainmentValue'] ?? 0);
                $requestedEffortScaleID = (int) ($classData['fieldScales']['effortValue'] ?? 0);
                $hasRequestedAttainment = $requestedAttainmentScaleID > 0;
                $hasRequestedEffort = $requestedEffortScaleID > 0;

                // Existing column?
                $column = $this->runSelect(
                    $this->newSelect()
                        ->from('gibbonInternalAssessmentColumn')
                        ->cols([
                            'gibbonInternalAssessmentColumnID',
                            'attainment',
                            'gibbonScaleIDAttainment',
                            'effort',
                            'gibbonScaleIDEffort',
                        ])
                        ->where('gibbonCourseClassID = :classID')
                        ->where('name = :name')
                        ->where('type = :type')
                        ->bindValue('classID', $classID, PDO::PARAM_INT)
                        ->bindValue('name', $columnName, PDO::PARAM_STR)
                        ->bindValue('type', $type, PDO::PARAM_STR)
                )->fetch();

                $columnID = (int) ($column['gibbonInternalAssessmentColumnID'] ?? 0);

                $existingAttainmentEnabled = ($column['attainment'] ?? 'N') === 'Y';
                $existingEffortEnabled = ($column['effort'] ?? 'N') === 'Y';
                $existingAttainmentScaleID = (int) ($column['gibbonScaleIDAttainment'] ?? 0);
                $existingEffortScaleID = (int) ($column['gibbonScaleIDEffort'] ?? 0);

                $finalAttainmentEnabled = $hasRequestedAttainment || $existingAttainmentEnabled;
                $finalEffortEnabled = $hasRequestedEffort || $existingEffortEnabled;
                $finalAttainmentScaleID = $hasRequestedAttainment ? $requestedAttainmentScaleID : $existingAttainmentScaleID;
                $finalEffortScaleID = $hasRequestedEffort ? $requestedEffortScaleID : $existingEffortScaleID;

                if ($columnID <= 0) {

                    $columnsToCreate++;

                    if ($isLive) {
                        $insert = $this->newInsert()
                            ->into('gibbonInternalAssessmentColumn')
                            ->cols([
                                'gibbonCourseClassID' => $classID,
                                'groupingID' => $groupingID,
                                'name' => $columnName,
                                'description' => $columnDescription,
                                'type' => $type,
                                'attachment' => '',
                                'attainment' => $finalAttainmentEnabled ? 'Y' : 'N',
                                'gibbonScaleIDAttainment' => $finalAttainmentEnabled ? $finalAttainmentScaleID : null,
                                'effort' => $finalEffortEnabled ? 'Y' : 'N',
                                'gibbonScaleIDEffort' => $finalEffortEnabled ? $finalEffortScaleID : null,
                                'comment' => 'N',
                                'uploadedResponse' => 'N',
                                'complete' => 'Y',
                                'completeDate' => $completeDate,
                                'viewableStudents' => $viewStudents,
                                'viewableParents' => $viewParents,
                                'gibbonPersonIDCreator' => $actorID,
                                'gibbonPersonIDLastEdit' => $actorID,
                            ]);

                        $this->runInsert($insert);

                        // IMPORTANT:
                        // Gibbon\Database\Connection does not support lastInsertId().
                        // Re-select the inserted row deterministically using the natural key.
                        $columnID = (int) $this->runSelect(
                            $this->newSelect()
                                ->from('gibbonInternalAssessmentColumn')
                                ->cols(['gibbonInternalAssessmentColumnID'])
                                ->where('gibbonCourseClassID = :classID2')
                                ->where('name = :name2')
                                ->where('type = :type2')
                                ->orderBy(['gibbonInternalAssessmentColumnID DESC'])
                                ->limit(1)
                                ->bindValue('classID2', $classID, PDO::PARAM_INT)
                                ->bindValue('name2', $columnName, PDO::PARAM_STR)
                                ->bindValue('type2', $type, PDO::PARAM_STR)
                        )->fetchColumn();

                        if ($columnID <= 0) {
                            throw new \RuntimeException('Failed to determine gibbonInternalAssessmentColumnID after insert.');
                        }
                    }
                } elseif ($isLive) {
                    $needsColumnUpdate =
                        (($column['attainment'] ?? 'N') !== ($finalAttainmentEnabled ? 'Y' : 'N'))
                        || ((int) ($column['gibbonScaleIDAttainment'] ?? 0) !== ($finalAttainmentEnabled ? $finalAttainmentScaleID : 0))
                        || (($column['effort'] ?? 'N') !== ($finalEffortEnabled ? 'Y' : 'N'))
                        || ((int) ($column['gibbonScaleIDEffort'] ?? 0) !== ($finalEffortEnabled ? $finalEffortScaleID : 0));

                    if ($needsColumnUpdate) {
                        $update = $this->newUpdate()
                            ->table('gibbonInternalAssessmentColumn')
                            ->cols([
                                'attainment' => $finalAttainmentEnabled ? 'Y' : 'N',
                                'gibbonScaleIDAttainment' => $finalAttainmentEnabled ? $finalAttainmentScaleID : null,
                                'effort' => $finalEffortEnabled ? 'Y' : 'N',
                                'gibbonScaleIDEffort' => $finalEffortEnabled ? $finalEffortScaleID : null,
                                'comment' => 'N',
                                'gibbonPersonIDLastEdit' => $actorID,
                            ])
                            ->where('gibbonInternalAssessmentColumnID = :columnID')
                            ->bindValue('columnID', $columnID, PDO::PARAM_INT);

                        $this->runUpdate($update);
                    }
                }

                foreach ($classData['students'] as $studentData) {

                    $studentID = (int) $studentData['studentID'];
                    $desiredAttainment = $studentData['values']['attainmentValue'];
                    $desiredEffort = $studentData['values']['effortValue'];
                    $existingAttainment = null;
                    $existingEffort = null;
                    $plannedAction = 'insert';

                    // Existing entry?
                    $existing = $this->runSelect(
                        $this->newSelect()
                            ->from('gibbonInternalAssessmentEntry')
                            ->cols(['gibbonInternalAssessmentEntryID', 'attainmentValue', 'effortValue'])
                            ->where('gibbonInternalAssessmentColumnID = :col')
                            ->where('gibbonPersonIDStudent = :stu')
                            ->bindValue('col', $columnID, PDO::PARAM_INT)
                            ->bindValue('stu', $studentID, PDO::PARAM_INT)
                    )->fetch();

                    if (!$existing) {

                        $entriesToInsert++;
                        $plannedAction = 'insert';

                        if ($isLive && $columnID > 0) {
                            $insertData = [
                                'gibbonInternalAssessmentColumnID' => $columnID,
                                'gibbonPersonIDStudent' => $studentID,
                                'gibbonPersonIDLastEdit' => $actorID,
                            ];

                            if ($desiredAttainment !== null) {
                                $insertData['attainmentValue'] = $desiredAttainment;
                            }

                            if ($desiredEffort !== null) {
                                $insertData['effortValue'] = $desiredEffort;
                            }

                            $insert = $this->newInsert()
                                ->into('gibbonInternalAssessmentEntry')
                                ->cols($insertData);

                            $this->runInsert($insert);
                        }

                    } else {
                        $existingAttainment = (string) ($existing['attainmentValue'] ?? '');
                        $existingEffort = (string) ($existing['effortValue'] ?? '');
                        $updateData = ['gibbonPersonIDLastEdit' => $actorID];
                        $hasFieldChanges = false;

                        if ($desiredAttainment !== null && $existingAttainment !== $desiredAttainment) {
                            $updateData['attainmentValue'] = $desiredAttainment;
                            $hasFieldChanges = true;
                        }

                        if ($desiredEffort !== null && $existingEffort !== $desiredEffort) {
                            $updateData['effortValue'] = $desiredEffort;
                            $hasFieldChanges = true;
                        }

                        if ($hasFieldChanges) {
                            $entriesToUpdate++;
                            $plannedAction = 'update';

                            if ($isLive) {
                                $update = $this->newUpdate()
                                    ->table('gibbonInternalAssessmentEntry')
                                    ->cols($updateData)
                                    ->where('gibbonInternalAssessmentEntryID = :id')
                                    ->bindValue('id', (int) $existing['gibbonInternalAssessmentEntryID'], PDO::PARAM_INT);

                                $this->runUpdate($update);
                            }

                        } else {
                            $entriesNoChange++;
                            $plannedAction = 'noChange';
                        }
                    }

                    foreach ($studentData['sourceRows'] as $sourceRow) {
                        $targetField = (string) ($sourceRow['targetField'] ?? 'attainmentValue');

                        $storagePlan[] = [
                            'courseClassID' => $classID,
                            'courseName' => (string) ($sourceRow['courseName'] ?? ''),
                            'className' => (string) ($sourceRow['className'] ?? ''),
                            'studentID' => $studentID,
                            'studentName' => (string) ($sourceRow['studentName'] ?? ''),
                            'criteriaTypeID' => (int) ($sourceRow['criteriaTypeID'] ?? 0),
                            'criteriaTypeName' => (string) ($sourceRow['criteriaTypeName'] ?? ''),
                            'criteriaName' => (string) ($sourceRow['criteriaName'] ?? ''),
                            'sourceReportingValue' => (string) ($sourceRow['value'] ?? ''),
                            'sourceDate' => $this->resolveSourceDate($sourceRow),
                            'targetColumnName' => $columnName,
                            'targetCompleteDate' => $completeDate,
                            'targetColumnConfig' => [
                                'attainment' => $finalAttainmentEnabled ? 'Y' : 'N',
                                'effort' => $finalEffortEnabled ? 'Y' : 'N',
                                'comment' => 'N',
                            ],
                            'targetField' => $targetField,
                            'existingTargetValue' => $targetField === 'effortValue' ? $existingEffort : $existingAttainment,
                            'plannedAction' => $plannedAction,
                        ];
                    }
                }
            }

            if ($isLive) {
                $pdo->commit();
            }

        } catch (\Throwable $e) {

            if ($isLive) {
                try {
                    $pdo->rollBack();
                } catch (\Throwable $t) {
                    // ignore rollback failures
                }
            }

            throw $e;
        }

        return [
            'importSuccess' => true,
            'buildSuccess' => true,
            'databaseSuccess' => true,
            'rows' => count($rows),
            'rowerrors' => 0,
            'errors' => 0,
            'warnings' => 0,
            'inserts' => $entriesToInsert,
            'inserts_skipped' => 0,
            'updates' => $entriesToUpdate,
            'updates_skipped' => $entriesNoChange,
            'columnsToCreate' => $columnsToCreate,
            'noChange' => $entriesNoChange,
            'lastError' => '',
            'payload' => [
                'transferFormat' => 'Form POST fields / hidden inputs (not JSON)',
                'filtersReceived' => $filters,
                'eligibleReportingRows' => $rows,
                'storagePlan' => $storagePlan,
                'warnings' => array_values(array_unique($planWarnings)),
            ],
            'summary' => [
                'eligibleRows' => count($rows),
                'columnsToCreate' => $columnsToCreate,
                'entriesToInsert' => $entriesToInsert,
                'entriesToUpdate' => $entriesToUpdate,
                'noChange' => $entriesNoChange,
            ],
        ];
    }

    private function getSetting(string $name): ?string
    {
        $select = $this->newSelect()
            ->from('gibbonSetting')
            ->cols(['value'])
            ->where("scope = 'Academic Records'")
            ->where('name = :name')
            ->bindValue('name', $name, PDO::PARAM_STR);

        $val = $this->runSelect($select)->fetchColumn();
        return $val !== false ? (string) $val : null;
    }

    private function normalizeYearGroupID($id): string
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

    private function resolveTargetField(string $criteriaTypeName, string $criteriaName): string
    {
        $needle = mb_strtolower(trim($criteriaTypeName . ' ' . $criteriaName));

        if (strpos($needle, 'effort') !== false) {
            return 'effortValue';
        }

        return 'attainmentValue';
    }

    private function resolveSourceDate(array $row): ?string
    {
        $timestamp = $row['timestampModified'] ?? $row['timestampCreated'] ?? null;

        if (empty($timestamp)) {
            return null;
        }

        return substr((string) $timestamp, 0, 10) ?: null;
    }
}
