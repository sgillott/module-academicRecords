<?php
/**
 * Writes a spreadsheet of past grades into Internal Assessment records.
 *
 * The grades of the years before Gibbon was in use cannot come through the
 * Store Grades wizard, because there is no reporting cycle behind them. The
 * file supplies them instead, and this class puts them where Store Grades
 * would have put them: one Internal Assessment column per class and term,
 * one entry per student, and one stored grade index row so a transcript can
 * find the term the column belongs to.
 *
 * The dry run and the live run share this one method, so what the dry run
 * reports is exactly what the live run writes.
 *
 * Three passes. The first resolves each row on its own. The second answers
 * what one row cannot: whether two rows collide, and which scale each class
 * sits on. Only then is anything written.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Historic;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\CourseCreditGateway;
use Gibbon\Module\AcademicRecords\Domain\HistoricGradeGateway;
use Gibbon\Module\AcademicRecords\Domain\StoredGradeGateway;

class Importer
{
    /**
     * The longest an Internal Assessment column name may be.
     */
    const NAME_LIMIT = 30;

    /**
     * @var Connection
     */
    private $db;

    /**
     * @var HistoricGradeGateway
     */
    private $gateway;

    /**
     * @var StoredGradeGateway
     */
    private $storedGradeGateway;

    /**
     * @var CourseCreditGateway
     */
    private $creditGateway;

    /**
     * @var SettingGateway
     */
    private $settingGateway;

    /**
     * @param Connection           $db                 Shared connection.
     * @param HistoricGradeGateway $gateway            Import reads and writes.
     * @param StoredGradeGateway   $storedGradeGateway The stored grade index.
     * @param CourseCreditGateway  $creditGateway      Credit settings.
     * @param SettingGateway       $settingGateway     Module settings.
     */
    public function __construct(
        Connection $db,
        HistoricGradeGateway $gateway,
        StoredGradeGateway $storedGradeGateway,
        CourseCreditGateway $creditGateway,
        SettingGateway $settingGateway
    ) {
        $this->db = $db;
        $this->gateway = $gateway;
        $this->storedGradeGateway = $storedGradeGateway;
        $this->creditGateway = $creditGateway;
        $this->settingGateway = $settingGateway;
    }

    /**
     * Run the import.
     *
     * @param array  $rows           Rows keyed by canonical field name.
     * @param string $defaultScaleID The scale chosen on the first step.
     * @param int    $actorID        Who is importing.
     * @param bool   $liveRun        True writes inside one transaction.
     *
     * @return array success, canLiveRun, message, warnings, summary, rows
     *               and context.
     */
    public function run(array $rows, string $defaultScaleID, int $actorID, bool $liveRun): array
    {
        $type = trim((string) $this->settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType'));

        if ($type === '') {
            return $this->failure(__('Academic Records Settings are incomplete: Internal Assessment Type is required.'));
        }

        if ($defaultScaleID === '') {
            return $this->failure(__('A default grade scale is required.'));
        }

        $scaleValues = $this->gateway->selectScaleValuesKeyed();

        if (empty($scaleValues[$defaultScaleID])) {
            return $this->failure(__('The chosen grade scale has no grades, so no value in the file could be matched.'));
        }

        $results = $this->resolveRows($rows);

        $this->markDuplicates($results);
        $scalesByClass = $this->resolveScales($results, $defaultScaleID, $scaleValues);
        $this->normaliseValues($results, $scalesByClass, $scaleValues);
        $this->checkCredit($results, $scalesByClass);

        $plan = $this->buildPlan($results, $scalesByClass);

        // A blocking reason stops the writing, not just the button that
        // asks for it, so a request that reaches step four another way
        // still only plans.
        $write = $liveRun && $this->countBlocking($results) === 0;

        $outcome = $this->applyPlan($plan, $results, $type, $actorID, $write);

        return $this->summarise($results, $plan, $outcome, $scalesByClass, $scaleValues, $defaultScaleID, $liveRun);
    }

    /**
     * How many rows failed for a reason that stops the whole import.
     *
     * @param array $results Resolved rows.
     *
     * @return int
     */
    private function countBlocking(array $results): int
    {
        $blocking = RowResolver::blockingReasons();
        $count = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'skipped' && in_array($result['reasonCode'], $blocking, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Pass one: resolve every row on its own.
     *
     * @param array $rows Rows keyed by canonical field name.
     *
     * @return array
     */
    private function resolveRows(array $rows): array
    {
        $studentIDs = [];
        $yearNames = [];

        foreach ($rows as $row) {
            $studentID = trim((string) ($row['studentID'] ?? ''));
            $year = trim((string) ($row['year'] ?? ''));

            if ($studentID !== '') {
                $studentIDs[$studentID] = true;
            }
            if ($year !== '') {
                $yearNames[HistoricGradeGateway::foldKey($year)] = true;
            }
        }

        $schoolYears = $this->gateway->selectSchoolYearsKeyed();

        $schoolYearIDs = [];
        foreach (array_keys($yearNames) as $name) {
            if (isset($schoolYears[$name])) {
                $schoolYearIDs[] = (string) $schoolYears[$name]['gibbonSchoolYearID'];
            }
        }

        $personIDs = $this->gateway->selectPersonIDsByStudentID(array_keys($studentIDs));
        $people = array_values($personIDs);

        $resolver = new RowResolver([
            'schoolYears' => $schoolYears,
            'terms' => $this->gateway->selectTermsKeyed($schoolYearIDs),
            'personIDs' => $personIDs,
            'yearGroups' => $this->gateway->selectYearGroupsKeyed($people, $schoolYearIDs),
            'courses' => $this->gateway->selectCoursesKeyed($schoolYearIDs),
            'enrolments' => $this->gateway->selectClassEnrolmentsKeyed($people, $schoolYearIDs),
        ]);

        $results = [];
        $rowNumber = 1;

        foreach ($rows as $row) {
            $rowNumber++;
            $results[] = $resolver->resolve($row, $rowNumber);
        }

        return $results;
    }

    /**
     * Pass two: two rows that name the same student, class and term.
     *
     * The file cannot say which of them is the record, and picking one
     * silently would hide the fault, so every row of the group is held
     * back and the import stops until the file is corrected.
     *
     * @param array $results Resolved rows, changed in place.
     *
     * @return void
     */
    private function markDuplicates(array &$results): void
    {
        $seen = [];

        foreach ($results as $index => $result) {
            if ($result['status'] !== 'ready') {
                continue;
            }

            $key = $result['gibbonPersonID'] . '|' . $result['gibbonCourseClassID'] . '|' . $result['gibbonSchoolYearTermID'];
            $seen[$key][] = $index;
        }

        foreach ($seen as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $rowNumbers = [];
            $grades = [];

            foreach ($indexes as $index) {
                $rowNumbers[] = $results[$index]['rowNumber'];
                $grades[$results[$index]['grade']] = true;
            }

            $agree = count($grades) === 1
                ? __('They hold the same grade, so delete all but one.')
                : __('They hold different grades, so only the file can say which is right.');

            foreach ($indexes as $index) {
                $results[$index]['status'] = 'skipped';
                $results[$index]['reasonCode'] = RowResolver::REASON_DUPLICATE;
                $results[$index]['reason'] = __('Rows {rows} all give {subject} for student {id} in {term}.', [
                    'rows' => implode(', ', $rowNumbers),
                    'subject' => $results[$index]['courseName'],
                    'id' => $results[$index]['studentID'],
                    'term' => $results[$index]['termName'],
                ]) . ' ' . $agree;
            }
        }
    }

    /**
     * Pass two: the scale each class sits on.
     *
     * The file names no scale. Where every grade a class carries is in the
     * chosen scale, that is the scale. Where it is not, as for a pass or
     * fail course among graded ones, the one active scale that does hold
     * all of them is used instead. Anything less certain is not guessed.
     *
     * @param array  $results        Resolved rows, changed in place.
     * @param string $defaultScaleID The scale chosen on the first step.
     * @param array  $scaleValues    Grades of every active scale.
     *
     * @return array [classID] => ['scaleID' => string, 'substituted' => bool].
     */
    private function resolveScales(array &$results, string $defaultScaleID, array $scaleValues): array
    {
        $valuesByClass = [];

        foreach ($results as $result) {
            if ($result['status'] !== 'ready') {
                continue;
            }

            $valuesByClass[$result['gibbonCourseClassID']][HistoricGradeGateway::foldKey($result['grade'])] = $result['grade'];
        }

        $scalesByClass = [];
        $failures = [];

        foreach ($valuesByClass as $classID => $values) {
            $wanted = array_keys($values);

            if ($this->scaleHolds($scaleValues[$defaultScaleID] ?? [], $wanted)) {
                $scalesByClass[$classID] = ['scaleID' => $defaultScaleID, 'substituted' => false];
                continue;
            }

            $candidates = [];

            foreach ($scaleValues as $scaleID => $grades) {
                if ($this->scaleHolds($grades, $wanted)) {
                    $candidates[] = (string) $scaleID;
                }
            }

            if (count($candidates) === 1) {
                $scalesByClass[$classID] = ['scaleID' => $candidates[0], 'substituted' => true];
                continue;
            }

            $failures[$classID] = [
                'values' => array_values($values),
                'candidates' => count($candidates),
            ];
        }

        foreach ($results as $index => $result) {
            if ($result['status'] !== 'ready' || !isset($failures[$result['gibbonCourseClassID']])) {
                continue;
            }

            $failure = $failures[$result['gibbonCourseClassID']];

            $results[$index]['status'] = 'skipped';
            $results[$index]['reasonCode'] = RowResolver::REASON_NO_SCALE;
            $results[$index]['reason'] = $failure['candidates'] === 0
                ? __('No active grade scale holds every grade this class uses ({values}), so the column has no scale to sit on.', [
                    'values' => implode(', ', $failure['values']),
                ])
                : __('{count} active grade scales hold every grade this class uses ({values}), so the file cannot say which one is meant. Choose it as the default scale and import this class on its own.', [
                    'count' => $failure['candidates'],
                    'values' => implode(', ', $failure['values']),
                ]);
        }

        return $scalesByClass;
    }

    /**
     * Does one scale hold every one of these values?
     *
     * @param array $grades Grades of the scale, keyed by folded value.
     * @param array $wanted Folded values the class uses.
     *
     * @return bool
     */
    private function scaleHolds(array $grades, array $wanted): bool
    {
        if (empty($grades)) {
            return false;
        }

        foreach ($wanted as $value) {
            if (!isset($grades[$value])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Write each grade the way its scale spells it.
     *
     * A file that says pass where the scale says Pass must still earn the
     * credit the school set against Pass, and that lookup is exact.
     *
     * @param array $results       Resolved rows, changed in place.
     * @param array $scalesByClass Scale chosen for each class.
     * @param array $scaleValues   Grades of every active scale.
     *
     * @return void
     */
    private function normaliseValues(array &$results, array $scalesByClass, array $scaleValues): void
    {
        foreach ($results as $index => $result) {
            if ($result['status'] !== 'ready') {
                continue;
            }

            $scaleID = $scalesByClass[$result['gibbonCourseClassID']]['scaleID'] ?? '';
            $grade = $scaleValues[$scaleID][HistoricGradeGateway::foldKey($result['grade'])] ?? [];

            $results[$index]['value'] = !empty($grade) ? (string) $grade['value'] : $result['grade'];
            $results[$index]['gibbonScaleIDAttainment'] = $scaleID;

            if (!empty($grade) && (string) $grade['value'] !== $result['grade']) {
                $results[$index]['warnings'][] = __('The grade {given} was stored as {stored}, which is how the {scale} scale spells it.', [
                    'given' => $result['grade'],
                    'stored' => (string) $grade['value'],
                    'scale' => (string) $grade['name'],
                ]);
            }

            if (($scalesByClass[$result['gibbonCourseClassID']]['substituted'] ?? false) === true) {
                $results[$index]['warnings'][] = __('This class was stored on the {scale} scale rather than the default, because the default does not hold every grade the class uses.', [
                    'scale' => (string) ($grade['name'] ?? $scaleID),
                ]);
            }
        }
    }

    /**
     * Check the Credit Awarded column against what the module would work out.
     *
     * A disagreement is reported and the row still imports, because the
     * credit a transcript prints is worked out from the course and the
     * grade, never from the file.
     *
     * @param array $results       Resolved rows, changed in place.
     * @param array $scalesByClass Scale chosen for each class.
     *
     * @return void
     */
    private function checkCredit(array &$results, array $scalesByClass): void
    {
        $courseIDs = [];

        foreach ($results as $result) {
            if ($result['status'] === 'ready') {
                $courseIDs[$result['gibbonCourseID']] = true;
            }
        }

        if (empty($courseIDs)) {
            return;
        }

        $courseCredit = $this->creditGateway->selectCourseCreditKeyed(array_keys($courseIDs));
        $gradeCredit = $this->creditGateway->selectGradeCreditKeyed();

        foreach ($results as $index => $result) {
            if ($result['status'] !== 'ready') {
                continue;
            }

            $credit = $courseCredit[$result['gibbonCourseID']] ?? [];

            if (empty($credit) || $credit['creditPerTerm'] === null) {
                $results[$index]['warnings'][] = __('{subject} has no credit set on the Course Credits page, so this grade will print with no credit.', [
                    'subject' => $result['courseName'],
                ]);
                continue;
            }

            if (($credit['showOnTranscript'] ?? 'Y') === 'N') {
                $results[$index]['warnings'][] = __('{subject} is set not to show on a transcript, so this grade is stored but will not print.', [
                    'subject' => $result['courseName'],
                ]);
            }

            if ($result['credit'] === '' || !is_numeric($result['credit'])) {
                continue;
            }

            $scaleID = $scalesByClass[$result['gibbonCourseClassID']]['scaleID'] ?? '';
            $factor = $gradeCredit[$scaleID][$result['value'] ?? $result['grade']] ?? 0.0;
            $computed = (float) $credit['creditPerTerm'] * (float) $factor;

            if (abs($computed - (float) $result['credit']) >= 0.005) {
                $results[$index]['warnings'][] = __('The file awards {given} credit, but this course and grade earn {computed}. The stored record earns {computed}.', [
                    'given' => rtrim(rtrim(number_format((float) $result['credit'], 2, '.', ''), '0'), '.') ?: '0',
                    'computed' => rtrim(rtrim(number_format($computed, 2, '.', ''), '0'), '.') ?: '0',
                ]);
            }
        }
    }

    /**
     * Group the rows that are ready into the columns they will be written to.
     *
     * @param array $results       Resolved rows.
     * @param array $scalesByClass Scale chosen for each class.
     *
     * @return array Keyed by class then column name.
     */
    private function buildPlan(array $results, array $scalesByClass): array
    {
        $plan = [];

        foreach ($results as $index => $result) {
            if ($result['status'] !== 'ready') {
                continue;
            }

            $classID = $result['gibbonCourseClassID'];
            $name = $this->columnName($result['schoolYearName'], $result['termNameShort']);

            if (!isset($plan[$classID][$name])) {
                $plan[$classID][$name] = [
                    'gibbonCourseClassID' => $classID,
                    'name' => $name,
                    'description' => 'Imported historic grades from ' . $result['schoolYearName'] . ' ' . $result['termName'],
                    'gibbonSchoolYearID' => $result['gibbonSchoolYearID'],
                    'gibbonSchoolYearTermID' => $result['gibbonSchoolYearTermID'],
                    'completeDate' => $result['termLastDay'] !== '' ? $result['termLastDay'] : null,
                    'gibbonScaleIDAttainment' => $scalesByClass[$classID]['scaleID'] ?? '',
                    'courseName' => $result['courseName'],
                    'classNameShort' => $result['classNameShort'],
                    'students' => [],
                ];
            }

            $plan[$classID][$name]['students'][$result['gibbonPersonID']] = [
                'gibbonPersonID' => $result['gibbonPersonID'],
                'value' => $result['value'] ?? $result['grade'],
                'resultIndex' => $index,
            ];
        }

        return $plan;
    }

    /**
     * The column name, which is the one Store Grades uses for a cycle.
     *
     * Both wizards write the same shape, so the Coverage page and the
     * transcript read an imported column and a stored one alike.
     *
     * @param string $schoolYearName Name of the school year.
     * @param string $termNameShort  Short name of the term.
     *
     * @return string
     */
    private function columnName(string $schoolYearName, string $termNameShort): string
    {
        return mb_substr(trim($schoolYearName . ' ' . $termNameShort . ' Final Grade'), 0, self::NAME_LIMIT);
    }

    /**
     * Count what the plan would do, and on a live run, do it.
     *
     * @param array  $plan     The grouped plan.
     * @param array  $results  Resolved rows, changed in place to record the
     *                         action each one led to.
     * @param string $type     Internal Assessment Type this module writes.
     * @param int    $actorID  Who is importing.
     * @param bool   $liveRun  True writes inside one transaction.
     *
     * @return array Counts, and any error that stopped the run.
     */
    private function applyPlan(array $plan, array &$results, string $type, int $actorID, bool $liveRun): array
    {
        $outcome = [
            'columnsToCreate' => 0,
            'columnsReused' => 0,
            'entriesInserted' => 0,
            'entriesUpdated' => 0,
            'entriesNoChange' => 0,
            'error' => '',
        ];

        if (empty($plan)) {
            return $outcome;
        }

        $viewStudents = $this->settingGateway->getSettingByScope('Academic Records', 'viewableStudents') ?: 'Y';
        $viewParents = $this->settingGateway->getSettingByScope('Academic Records', 'viewableParents') ?: 'Y';

        $existingColumns = $this->gateway->selectColumnsKeyed(array_keys($plan), $type);

        $columnIDs = [];
        foreach ($existingColumns as $byName) {
            foreach ($byName as $column) {
                $columnIDs[] = (int) $column['gibbonInternalAssessmentColumnID'];
            }
        }

        $existingEntries = $this->gateway->selectEntriesKeyed($columnIDs);
        $groupingID = $this->gateway->getNextGroupingID();

        if ($liveRun) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($plan as $classID => $columns) {
                foreach ($columns as $name => $column) {

                    $existing = $existingColumns[$classID][$name] ?? [];
                    $columnID = (int) ($existing['gibbonInternalAssessmentColumnID'] ?? 0);

                    if ($columnID <= 0) {
                        $outcome['columnsToCreate']++;

                        if ($liveRun) {
                            $columnID = $this->gateway->insertColumn([
                                'gibbonCourseClassID' => $classID,
                                'groupingID' => $groupingID,
                                'name' => $name,
                                'description' => $column['description'],
                                'type' => $type,
                                'attachment' => '',
                                'attainment' => 'Y',
                                'gibbonScaleIDAttainment' => $column['gibbonScaleIDAttainment'],
                                'effort' => 'N',
                                'gibbonScaleIDEffort' => null,
                                'comment' => 'N',
                                'uploadedResponse' => 'N',
                                'complete' => 'Y',
                                'completeDate' => $column['completeDate'],
                                'viewableStudents' => $viewStudents,
                                'viewableParents' => $viewParents,
                                'gibbonPersonIDCreator' => $actorID,
                                'gibbonPersonIDLastEdit' => $actorID,
                            ]);
                        }
                    } else {
                        $outcome['columnsReused']++;

                        // Attainment may be off, or on another scale, if the
                        // column was written by an earlier run on a different
                        // default. Effort is left exactly as it was found, so
                        // an import never undoes a stored reporting grade.
                        $needsUpdate = ($existing['attainment'] ?? 'N') !== 'Y'
                            || (string) ($existing['gibbonScaleIDAttainment'] ?? '') !== (string) $column['gibbonScaleIDAttainment'];

                        if ($liveRun && $needsUpdate) {
                            $this->gateway->updateColumn($columnID, [
                                'attainment' => 'Y',
                                'gibbonScaleIDAttainment' => $column['gibbonScaleIDAttainment'],
                                'gibbonPersonIDLastEdit' => $actorID,
                            ]);
                        }
                    }

                    if ($liveRun && $columnID > 0) {
                        $this->storedGradeGateway->saveIndex([
                            'columnID' => $columnID,
                            'schoolYearID' => $column['gibbonSchoolYearID'],
                            'termID' => $column['gibbonSchoolYearTermID'],
                            'cycleID' => null,
                            'classID' => $classID,
                            'actorID' => $actorID,
                        ]);
                    }

                    foreach ($column['students'] as $student) {
                        $entry = $existingEntries[$columnID][$student['gibbonPersonID']] ?? null;
                        $index = $student['resultIndex'];

                        if ($entry === null) {
                            $outcome['entriesInserted']++;
                            $results[$index]['action'] = 'insert';

                            if ($liveRun && $columnID > 0) {
                                $this->gateway->insertEntry([
                                    'gibbonInternalAssessmentColumnID' => $columnID,
                                    'gibbonPersonIDStudent' => $student['gibbonPersonID'],
                                    'attainmentValue' => $student['value'],
                                    'gibbonPersonIDLastEdit' => $actorID,
                                ]);
                            }

                            continue;
                        }

                        if ((string) ($entry['attainmentValue'] ?? '') === $student['value']) {
                            $outcome['entriesNoChange']++;
                            $results[$index]['action'] = 'noChange';

                            continue;
                        }

                        $outcome['entriesUpdated']++;
                        $results[$index]['action'] = 'update';
                        $results[$index]['warnings'][] = __('This replaces a stored grade of {old}.', [
                            'old' => (string) ($entry['attainmentValue'] ?? ''),
                        ]);

                        if ($liveRun) {
                            $this->gateway->updateEntry((int) $entry['gibbonInternalAssessmentEntryID'], [
                                'attainmentValue' => $student['value'],
                                'gibbonPersonIDLastEdit' => $actorID,
                            ]);
                        }
                    }
                }
            }

            if ($liveRun) {
                $this->db->commit();
            }

        } catch (\Throwable $e) {

            if ($liveRun) {
                try {
                    $this->db->rollBack();
                } catch (\Throwable $ignored) {
                    // A failed rollback leaves nothing more to be done here.
                }
            }

            $outcome['error'] = $e->getMessage();
        }

        return $outcome;
    }

    /**
     * Build the result the import page renders.
     *
     * @param array  $results        Resolved rows.
     * @param array  $plan           The grouped plan.
     * @param array  $outcome        Counts from applying the plan.
     * @param array  $scalesByClass  Scale chosen for each class.
     * @param array  $scaleValues    Grades of every active scale.
     * @param string $defaultScaleID The scale chosen on the first step.
     * @param bool   $liveRun        Whether this run wrote.
     *
     * @return array
     */
    private function summarise(array $results, array $plan, array $outcome, array $scalesByClass, array $scaleValues, string $defaultScaleID, bool $liveRun): array
    {
        $blocking = RowResolver::blockingReasons();
        $counts = ['ready' => 0, 'skipped' => 0, 'blank' => 0];
        $blockingRows = 0;
        $reasonCounts = [];

        foreach ($results as $result) {
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;

            if ($result['status'] !== 'skipped') {
                continue;
            }

            $reasonCounts[$result['reasonCode']] = ($reasonCounts[$result['reasonCode']] ?? 0) + 1;

            if (in_array($result['reasonCode'], $blocking, true)) {
                $blockingRows++;
            }
        }

        $warnings = [];

        foreach ($scalesByClass as $scale) {
            if ($scale['substituted'] !== true) {
                continue;
            }

            $name = $this->scaleName($scale['scaleID'], $scaleValues);
            $warnings[] = __('One class was stored on the {scale} scale rather than the default, because the default does not hold every grade it uses.', ['scale' => $name]);
        }

        $warnings = array_values(array_unique($warnings));

        $success = $outcome['error'] === '';
        $canLiveRun = $success && $blockingRows === 0 && $counts['ready'] > 0;

        $message = '';

        if (!$success) {
            $message = $outcome['error'];
        } elseif ($blockingRows > 0) {
            $message = __n(
                '{count} row failed for a reason that stops the import. Correct the file and upload it again.',
                '{count} rows failed for a reason that stops the import. Correct the file and upload it again.',
                $blockingRows,
                ['count' => $blockingRows]
            );
        } elseif ($counts['ready'] === 0) {
            $message = __('No row in the file resolved to a class, so there is nothing to import.');
        }

        return [
            'success' => $success,
            'canLiveRun' => $canLiveRun,
            'liveRun' => $liveRun,
            'message' => $message,
            'warnings' => $warnings,
            'rows' => $results,
            'summary' => [
                'rowsReceived' => count($results),
                'rowsReady' => $counts['ready'],
                'rowsSkipped' => $counts['skipped'],
                'blankRows' => $counts['blank'],
                'blockingRows' => $blockingRows,
                'reasonCounts' => $reasonCounts,
                'columnsToCreate' => $outcome['columnsToCreate'],
                'columnsReused' => $outcome['columnsReused'],
                'entriesInserted' => $outcome['entriesInserted'],
                'entriesUpdated' => $outcome['entriesUpdated'],
                'entriesNoChange' => $outcome['entriesNoChange'],
            ],
            'context' => [
                'defaultScale' => $this->scaleName($defaultScaleID, $scaleValues),
                'classesInPlan' => count($plan),
                'scalesByClass' => $scalesByClass,
            ],
        ];
    }

    /**
     * The name of one scale, read from the grades already in memory.
     *
     * @param string $scaleID     Scale to name.
     * @param array  $scaleValues Grades of every active scale.
     *
     * @return string
     */
    private function scaleName(string $scaleID, array $scaleValues): string
    {
        $grades = $scaleValues[$scaleID] ?? [];
        $first = reset($grades);

        return is_array($first) ? (string) $first['name'] : $scaleID;
    }

    /**
     * The shape returned when the import cannot start at all.
     *
     * @param string $message Already translated.
     *
     * @return array
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'canLiveRun' => false,
            'liveRun' => false,
            'message' => $message,
            'warnings' => [],
            'rows' => [],
            'summary' => [
                'rowsReceived' => 0,
                'rowsReady' => 0,
                'rowsSkipped' => 0,
                'blankRows' => 0,
                'blockingRows' => 0,
                'reasonCounts' => [],
                'columnsToCreate' => 0,
                'columnsReused' => 0,
                'entriesInserted' => 0,
                'entriesUpdated' => 0,
                'entriesNoChange' => 0,
            ],
            'context' => [],
        ];
    }
}
