<?php
/**
 * Reads reporting cycle grades and stores them as Internal Assessment columns.
 *
 * The gateway is keyed on gibbonReportingValue, which is where the grades
 * come from. Everything it writes goes to gibbonInternalAssessmentColumn and
 * gibbonInternalAssessmentEntry, with the term recorded through the stored
 * grade index.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Domain;

use PDO;
use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\Traits\TableAware;
use Gibbon\Module\AcademicRecords\Domain\Traits\BindsInList;

class AcademicRecordsGateway extends QueryableGateway
{
    use TableAware;
    use BindsInList;

    private static $tableName = 'gibbonReportingValue';
    private static $primaryKey = 'gibbonReportingValueID';

    /**
     * Index of the stored grade columns this module writes.
     *
     * @var StoredGradeGateway
     */
    private $storedGradeGateway;

    /**
     * @var SettingGateway
     */
    private $settingGateway;

    /**
     * The container shares one Connection, so index writes issued through
     * the stored grade gateway join the transaction opened here.
     *
     * @param Connection         $db                 Shared database connection.
     * @param StoredGradeGateway $storedGradeGateway Stored grade index.
     * @param SettingGateway     $settingGateway     Module settings.
     */
    public function __construct(Connection $db, StoredGradeGateway $storedGradeGateway, SettingGateway $settingGateway)
    {
        parent::__construct($db);

        $this->storedGradeGateway = $storedGradeGateway;
        $this->settingGateway = $settingGateway;
    }

    /**
     * The result shape the Store Grades page expects when a run cannot start.
     *
     * The keys match Gibbon's importer.twig.html, which renders the summary.
     *
     * @param string $lastError    What went wrong, already translated.
     * @param bool   $buildSucceeded True when the plan was built and the
     *                             failure came while writing, so the
     *                             summary marks only the database step.
     *
     * @return array
     */
    public static function failureResult(string $lastError, bool $buildSucceeded = false): array
    {
        return [
            'importSuccess' => true,
            'buildSuccess' => $buildSucceeded,
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
            'lastError' => $lastError,
            'summary' => ['eligibleRows' => 0],
        ];
    }

    /* ---------------------------------------------------------
       QUERY ELIGIBLE REPORTING GRADES (Step 2 + Dry Run)
    --------------------------------------------------------- */

    /**
     * The paged preview on Store Grades step 2.
     *
     * @param QueryCriteria $criteria Paging from the DataTable.
     * @param int           $cycleID  gibbonReportingCycleID.
     * @param array         $filters  Request values from step 1.
     *
     * @return \Gibbon\Domain\DataSet
     */
    public function queryEligibleReportingGrades(
        QueryCriteria $criteria,
        int $cycleID,
        array $filters = []
    ) {
        $query = $this->applyGradeFilters($this->newQuery(), $cycleID, $filters);

        $criteria->sortBy(self::GRADE_ORDER);

        return $this->runQuery($query, $criteria);
    }

    /**
     * Every eligible row, for the plan.
     *
     * A plain select rather than runQuery(), which would also count the
     * filtered rows and the whole table for a paging footer nobody reads.
     *
     * @param int   $cycleID gibbonReportingCycleID.
     * @param array $filters Request values from step 1.
     *
     * @return array
     */
    public function selectEligibleReportingGradesForDryRun(int $cycleID, array $filters = []): array
    {
        $query = $this->applyGradeFilters($this->newSelect(), $cycleID, $filters)
            ->orderBy(self::GRADE_ORDER);

        return $this->runSelect($query)->fetchAll();
    }

    /**
     * The order both readers return rows in, so the preview and the plan
     * agree on which of two rows for the same cell is the later one.
     */
    private const GRADE_ORDER = ['p.surname', 'p.preferredName', 'c.nameShort', 'rc.name'];

    /**
     * The eligible grades of a cycle, with the step 1 filters applied.
     *
     * @param \Aura\SqlQuery\Common\SelectInterface $query   Fresh select.
     * @param int                                   $cycleID gibbonReportingCycleID.
     * @param array                                 $filters Request values from step 1.
     *
     * @return \Aura\SqlQuery\Common\SelectInterface
     */
    private function applyGradeFilters($query, int $cycleID, array $filters)
    {
        $query
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

        return $query;
    }

    /* ---------------------------------------------------------
       STORE / DRY RUN
    --------------------------------------------------------- */

    /**
     * Plan the store, and carry it out when asked to.
     *
     * The dry run and the live run share this one method, so what the dry
     * run reports is exactly what the live run writes.
     *
     * @param int   $cycleID gibbonReportingCycleID to read grades from.
     * @param array $filters Request values from the Store Grades wizard.
     * @param int   $actorID Who is storing.
     * @param bool  $isLive  True writes to the database inside one
     *                       transaction. False only plans.
     *
     * @return array Summary in the shape importer.twig.html expects, plus a
     *               payload for the Data panel.
     */
    public function dryRunStoreReportingGrades(int $cycleID, array $filters, int $actorID, bool $isLive = false): array
    {
        $db = $this->db();

        $cycle = $this->storedGradeGateway->getCycleContext($cycleID);

        if (empty($cycle)) {
            return ['summary' => ['eligibleRows' => 0]];
        }

        $cycleShort = trim(($cycle['nameShort'] ?? '') !== '' ? $cycle['nameShort'] : ($cycle['name'] ?? ''));
        $schoolYear = (string) ($cycle['schoolYearName'] ?? '');

        $columnName = mb_substr(trim($schoolYear . ' ' . $cycleShort . ' Final Grade'), 0, 30);
        $columnDescription = 'Stored Final Grade from ' . $schoolYear . ' ' . $cycleShort;

        $type = (string) $this->settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType');
        $viewStudents = $this->settingGateway->getSettingByScope('Academic Records', 'viewableStudents') ?: 'Y';
        $viewParents  = $this->settingGateway->getSettingByScope('Academic Records', 'viewableParents') ?: 'Y';

        if ($type === '') {
            return self::failureResult('Academic Records Settings are incomplete: Internal Assessment Type is required.');
        }

        /* ---------------------------
           Term

           Stored grades are recorded against a school year term. Transcripts
           read that term. The reporting cycle dates cannot supply it, because
           a cycle often runs after the term it reports on has closed.
        --------------------------- */

        $schoolYearID = (string) ($cycle['gibbonSchoolYearID'] ?? '');
        $term = $this->resolveTerm($filters, $schoolYearID);

        if (empty($term)) {
            return self::failureResult('Store as Term is required, and must be a term of the school year this reporting cycle belongs to.');
        }

        $termID = (string) $term['gibbonSchoolYearTermID'];
        $termName = (string) $term['name'];

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

        // Read the existing columns and entries in two queries, rather than
        // one per class and one per student. A full cycle is thousands of
        // rows, and the plan only needs to look each one up.
        $columnsByClass = $this->selectColumnsByClassKeyed(array_keys($groupedRows), $columnName, $type);
        $entriesByColumn = $this->selectEntriesKeyed(array_map(function ($column) {
            return (int) $column['gibbonInternalAssessmentColumnID'];
        }, $columnsByClass));

        if ($isLive) {
            $db->beginTransaction();
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

                $column = $columnsByClass[$classID] ?? [];
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

                        // Connection::insert() returns lastInsertId, which is
                        // what runInsert() passes back.
                        $columnID = (int) $this->runInsert($insert);

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

                // Record the term this column belongs to, so transcripts can
                // place it without reading the column name or the cycle dates.
                if ($isLive && $columnID > 0) {
                    $this->storedGradeGateway->saveIndex([
                        'columnID' => $columnID,
                        'schoolYearID' => $schoolYearID,
                        'termID' => $termID,
                        'cycleID' => $cycleID,
                        'classID' => $classID,
                        'actorID' => $actorID,
                    ]);
                }

                foreach ($classData['students'] as $studentData) {

                    $studentID = (int) $studentData['studentID'];
                    $desiredAttainment = $studentData['values']['attainmentValue'];
                    $desiredEffort = $studentData['values']['effortValue'];
                    $existingAttainment = null;
                    $existingEffort = null;
                    $plannedAction = 'insert';

                    $existing = $entriesByColumn[$columnID][$studentID] ?? null;

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
                            'targetTerm' => $termName,
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
                $db->commit();
            }

        } catch (\Throwable $e) {

            if ($isLive) {
                try {
                    $db->rollBack();
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

    /**
     * The chosen term, checked against the school year of the reporting cycle.
     *
     * @param array  $filters      Request values from the Store Grades wizard.
     * @param string $schoolYearID The school year of the reporting cycle.
     *
     * @return array The gibbonSchoolYearTerm row, or empty when the value is
     *               missing or belongs to another school year.
     */
    private function resolveTerm(array $filters, string $schoolYearID): array
    {
        $termID = trim((string) ($filters['gibbonSchoolYearTermID'] ?? ''));

        if ($termID === '' || !ctype_digit($termID) || $schoolYearID === '') {
            return [];
        }

        // Compared as integers, because the request value arrives without the
        // leading zeros the database stores.
        foreach ($this->storedGradeGateway->selectTermsBySchoolYear($schoolYearID) as $term) {
            if ((int) $term['gibbonSchoolYearTermID'] === (int) $termID) {
                return $term;
            }
        }

        return [];
    }

    /**
     * The stored grade column of each class, keyed by gibbonCourseClassID.
     *
     * A column is matched on class, name and type, which is how the store
     * finds the column it wrote on an earlier run of the same cycle.
     *
     * @param array  $classIDs   Classes in the plan.
     * @param string $columnName Column name the store uses for this cycle.
     * @param string $type       Internal Assessment Type this module owns.
     *
     * @return array
     */
    private function selectColumnsByClassKeyed(array $classIDs, string $columnName, string $type): array
    {
        if (empty($classIDs)) {
            return [];
        }

        // A bound IN list, not FIND_IN_SET. The ID columns are ZEROFILL, and
        // FIND_IN_SET would compare the padded string form.
        [$placeholders, $bindings] = $this->inList($classIDs, 'cls', true);

        $select = $this->newSelect()
            ->from('gibbonInternalAssessmentColumn')
            ->cols([
                'gibbonInternalAssessmentColumnID',
                'gibbonCourseClassID',
                'attainment',
                'gibbonScaleIDAttainment',
                'effort',
                'gibbonScaleIDEffort',
            ])
            ->where('gibbonCourseClassID IN (' . $placeholders . ')')
            ->where('name = :name')
            ->where('type = :type')
            ->orderBy(['gibbonInternalAssessmentColumnID'])
            ->bindValues($bindings)
            ->bindValue('name', $columnName, PDO::PARAM_STR)
            ->bindValue('type', $type, PDO::PARAM_STR);

        $keyed = [];

        foreach ($this->runSelect($select)->fetchAll() as $column) {
            $classID = (int) $column['gibbonCourseClassID'];

            // The first column wins where a class somehow has two.
            if (!isset($keyed[$classID])) {
                $keyed[$classID] = $column;
            }
        }

        return $keyed;
    }

    /**
     * Every entry of the given columns, keyed by column then student.
     *
     * @param array $columnIDs gibbonInternalAssessmentColumnID values.
     *
     * @return array
     */
    private function selectEntriesKeyed(array $columnIDs): array
    {
        $columnIDs = array_filter(array_map('intval', $columnIDs));

        if (empty($columnIDs)) {
            return [];
        }

        [$placeholders, $bindings] = $this->inList($columnIDs, 'col', true);

        $select = $this->newSelect()
            ->from('gibbonInternalAssessmentEntry')
            ->cols([
                'gibbonInternalAssessmentEntryID',
                'gibbonInternalAssessmentColumnID',
                'gibbonPersonIDStudent',
                'attainmentValue',
                'effortValue',
            ])
            ->where('gibbonInternalAssessmentColumnID IN (' . $placeholders . ')')
            ->orderBy(['gibbonInternalAssessmentEntryID'])
            ->bindValues($bindings);

        $keyed = [];

        foreach ($this->runSelect($select)->fetchAll() as $entry) {
            $columnID = (int) $entry['gibbonInternalAssessmentColumnID'];
            $studentID = (int) $entry['gibbonPersonIDStudent'];

            if (!isset($keyed[$columnID][$studentID])) {
                $keyed[$columnID][$studentID] = $entry;
            }
        }

        return $keyed;
    }

    private function normalizeYearGroupID($id): string
    {
        return normalizeYearGroupID($id);
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
