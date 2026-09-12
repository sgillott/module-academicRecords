<?php
/**
 * Reads the choices offered on Store Grades step 1.
 *
 * Each list narrows as the one above it changes: the cycle sets the year
 * groups, the year groups and students set the subjects, and all of them
 * set the criteria types. The same methods serve the page and its AJAX
 * refresh, so both always agree.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Domain;

use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;
use Gibbon\Module\AcademicRecords\Domain\Traits\BindsInList;

class StoreFilterGateway extends QueryableGateway
{
    use TableAware;
    use BindsInList;

    private static $tableName = 'gibbonReportingCycle';
    private static $primaryKey = 'gibbonReportingCycleID';

    /**
     * Every reporting cycle, newest school year first.
     *
     * @return array gibbonReportingCycleID to "(School Year) Cycle".
     */
    public function selectCycleOptions(): array
    {
        $sql = "SELECT rc.gibbonReportingCycleID,
                    rc.name AS cycleName,
                    sy.name AS schoolYearName
                FROM gibbonReportingCycle rc
                JOIN gibbonSchoolYear sy ON (sy.gibbonSchoolYearID = rc.gibbonSchoolYearID)
                ORDER BY sy.sequenceNumber DESC, rc.dateStart DESC, rc.gibbonReportingCycleID DESC";

        $results = [];

        foreach ($this->db()->select($sql)->fetchAll() as $row) {
            $results[$row['gibbonReportingCycleID']] = '(' . $row['schoolYearName'] . ') ' . $row['cycleName'];
        }

        return $results;
    }

    /**
     * The year groups a reporting cycle covers, in teaching order.
     *
     * @param int $cycleID gibbonReportingCycleID.
     *
     * @return array gibbonYearGroupID to name.
     */
    public function selectYearGroupsByCycle(int $cycleID): array
    {
        $sql = "SELECT gibbonYearGroupIDList
                FROM gibbonReportingCycle
                WHERE gibbonReportingCycleID = :cycleID";

        $list = (string) $this->db()->selectOne($sql, ['cycleID' => $cycleID]);

        if ($list === '') {
            return [];
        }

        [$placeholders, $data] = $this->inList(array_map('trim', explode(',', $list)), 'yg', true);

        $sql = "SELECT gibbonYearGroupID, name
                FROM gibbonYearGroup
                WHERE gibbonYearGroupID IN ({$placeholders})
                ORDER BY sequenceNumber";

        $results = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $results[$row['gibbonYearGroupID']] = $row['name'];
        }

        return $results;
    }

    /**
     * Courses reported on in a cycle for the given year groups.
     *
     * @param array $yearGroupIDs    Year groups to cover.
     * @param int   $cycleID         gibbonReportingCycleID.
     * @param array $studentIDs      Students to restrict to.
     * @param bool  $studentFilterOn Whether the student list applies.
     *
     * @return array gibbonCourseID to nameShort.
     */
    public function selectSubjectsByYearGroups(array $yearGroupIDs, int $cycleID, array $studentIDs = [], bool $studentFilterOn = false): array
    {
        if (empty($yearGroupIDs) || empty($cycleID)) {
            return [];
        }

        $conditions = [];
        $data = ['cycleID' => $cycleID];
        $studentCondition = '';

        foreach (array_values($yearGroupIDs) as $i => $id) {
            $key = "yg$i";
            $conditions[] = "FIND_IN_SET(:$key, c.gibbonYearGroupIDList)";
            $data[$key] = normalizeYearGroupID($id);
        }

        if ($studentFilterOn && !empty($studentIDs)) {
            [$placeholders, $studentData] = $this->inList($studentIDs, 'student', true);
            $data += $studentData;

            $studentCondition = " AND EXISTS (
                SELECT 1
                FROM gibbonReportingValue rv
                WHERE rv.gibbonReportingCycleID = rc.gibbonReportingCycleID
                  AND rv.gibbonCourseClassID IS NOT NULL
                  AND rv.gibbonPersonIDStudent IN ({$placeholders})
                  AND rv.gibbonReportingCriteriaID = rc.gibbonReportingCriteriaID
            )";
        }

        $sql = "SELECT DISTINCT c.gibbonCourseID, c.nameShort
                FROM gibbonReportingCriteria rc
                JOIN gibbonCourse c ON (c.gibbonCourseID = rc.gibbonCourseID)
                WHERE rc.gibbonReportingCycleID = :cycleID
                  AND rc.target = 'Per Student'
                  AND (" . implode(' OR ', $conditions) . ")
                  {$studentCondition}
                ORDER BY c.nameShort";

        $results = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $results[$row['gibbonCourseID']] = $row['nameShort'];
        }

        return $results;
    }

    /**
     * Grade scale criteria types reported on in a cycle, after the filters.
     *
     * @param int   $cycleID           gibbonReportingCycleID.
     * @param array $yearGroupIDs      Year groups, used when the filter is on.
     * @param bool  $yearGroupFilterOn Whether the year group list applies.
     * @param array $subjectIDs        Courses, used when the filter is on.
     * @param bool  $subjectFilterOn   Whether the course list applies.
     * @param array $studentIDs        Students, used when the filter is on.
     * @param bool  $studentFilterOn   Whether the student list applies.
     *
     * @return array gibbonReportingCriteriaTypeID to name and scaleID.
     */
    public function selectCriteriaTypesByCycle(
        int $cycleID,
        array $yearGroupIDs = [],
        bool $yearGroupFilterOn = false,
        array $subjectIDs = [],
        bool $subjectFilterOn = false,
        array $studentIDs = [],
        bool $studentFilterOn = false
    ): array {
        $data = ['cycleID' => $cycleID];
        $yearGroupCondition = '';
        $subjectCondition = '';
        $studentCondition = '';

        if ($yearGroupFilterOn && !empty($yearGroupIDs)) {
            $conditions = [];

            foreach (array_values($yearGroupIDs) as $i => $id) {
                $key = "yg$i";
                $conditions[] = "FIND_IN_SET(:$key, c.gibbonYearGroupIDList)";
                $data[$key] = normalizeYearGroupID($id);
            }

            $yearGroupCondition = " AND (" . implode(' OR ', $conditions) . ")";
        }

        if ($subjectFilterOn && !empty($subjectIDs)) {
            [$placeholders, $subjectData] = $this->inList($subjectIDs, 'subject', true);
            $data += $subjectData;
            $subjectCondition = " AND c.gibbonCourseID IN ({$placeholders})";
        }

        if ($studentFilterOn && !empty($studentIDs)) {
            [$placeholders, $studentData] = $this->inList($studentIDs, 'student', true);
            $data += $studentData;

            $studentCondition = " AND EXISTS (
                SELECT 1
                FROM gibbonReportingValue rv
                WHERE rv.gibbonReportingCycleID = rc.gibbonReportingCycleID
                  AND rv.gibbonReportingCriteriaID = rc.gibbonReportingCriteriaID
                  AND rv.gibbonPersonIDStudent IN ({$placeholders})
            )";
        }

        $sql = "SELECT DISTINCT rct.gibbonReportingCriteriaTypeID, rct.name, rct.gibbonScaleID
                FROM gibbonReportingCriteria rc
                JOIN gibbonReportingCriteriaType rct ON (rct.gibbonReportingCriteriaTypeID = rc.gibbonReportingCriteriaTypeID)
                JOIN gibbonCourse c ON (c.gibbonCourseID = rc.gibbonCourseID)
                WHERE rc.gibbonReportingCycleID = :cycleID
                  AND rc.target = 'Per Student'
                  AND rct.valueType = 'Grade Scale'
                  AND rct.active = 'Y'
                  {$yearGroupCondition}
                  {$subjectCondition}
                  {$studentCondition}
                ORDER BY rct.name";

        $results = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $results[$row['gibbonReportingCriteriaTypeID']] = [
                'name' => $row['name'],
                'scaleID' => $row['gibbonScaleID'],
            ];
        }

        return $results;
    }

    /**
     * The values of a scale, in order, with a blank marker where the scale
     * holds an empty value.
     *
     * @param int $scaleID gibbonScaleID.
     *
     * @return array value to label; '__BLANK__' to '(Blank)' for an empty value.
     */
    public function selectScaleValues(int $scaleID): array
    {
        $sql = "SELECT value
                FROM gibbonScaleGrade
                WHERE gibbonScaleID = :scaleID
                ORDER BY sequenceNumber";

        $values = [];

        foreach ($this->db()->select($sql, ['scaleID' => $scaleID])->fetchAll() as $row) {
            $v = $row['value'];

            if ($v === '' || $v === null) {
                $values['__BLANK__'] = '(Blank)';
            } else {
                $values[$v] = $v;
            }
        }

        return $values;
    }
}
