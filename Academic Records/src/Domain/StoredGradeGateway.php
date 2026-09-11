<?php
/**
 * Index of the internal assessment columns that this module writes.
 *
 * Each stored grade column is recorded against the school year term it
 * belongs to. A reporting cycle cannot supply this, because a cycle often
 * runs after the term it reports on has closed. Transcripts read the term
 * from this index.
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

class StoredGradeGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'academicRecordsStoredGrade';
    private static $primaryKey = 'academicRecordsStoredGradeID';

    /**
     * School year and dates for one reporting cycle.
     *
     * @param int $cycleID gibbonReportingCycleID to read.
     *
     * @return array Empty when the cycle does not exist.
     */
    public function getCycleContext(int $cycleID): array
    {
        $sql = "SELECT rc.gibbonReportingCycleID,
                    rc.gibbonSchoolYearID,
                    rc.name,
                    rc.nameShort,
                    rc.dateStart,
                    rc.dateEnd,
                    sy.name AS schoolYearName
                FROM gibbonReportingCycle AS rc
                JOIN gibbonSchoolYear AS sy ON (sy.gibbonSchoolYearID = rc.gibbonSchoolYearID)
                WHERE rc.gibbonReportingCycleID = :cycleID";

        $data = ['cycleID' => $cycleID];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? $row : [];
    }

    /**
     * Terms of one school year, in teaching order.
     *
     * The number of terms is not fixed. A school using trimesters returns
     * three rows here, and every caller works from the rows it is given.
     *
     * @param string $schoolYearID gibbonSchoolYearID to read.
     *
     * @return array
     */
    public function selectTermsBySchoolYear(string $schoolYearID): array
    {
        $sql = "SELECT gibbonSchoolYearTermID,
                    gibbonSchoolYearID,
                    name,
                    nameShort,
                    firstDay,
                    lastDay,
                    sequenceNumber
                FROM gibbonSchoolYearTerm
                WHERE gibbonSchoolYearID = :schoolYearID
                ORDER BY sequenceNumber, firstDay";

        $data = ['schoolYearID' => $schoolYearID];

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Terms of the school year that a reporting cycle belongs to.
     *
     * @param int $cycleID gibbonReportingCycleID to read.
     *
     * @return array
     */
    public function selectTermsByCycle(int $cycleID): array
    {
        $cycle = $this->getCycleContext($cycleID);

        if (empty($cycle['gibbonSchoolYearID'])) {
            return [];
        }

        return $this->selectTermsBySchoolYear((string) $cycle['gibbonSchoolYearID']);
    }

    /**
     * Best guess at the term that a reporting cycle reports on.
     *
     * The guess uses the cycle start date, not the end date. A cycle that
     * reports on term one commonly ends after term two has begun, so the
     * end date points at the wrong term. The user confirms the guess on
     * the Store Grades page.
     *
     * @param int $cycleID gibbonReportingCycleID to read.
     *
     * @return string gibbonSchoolYearTermID, or an empty string when the
     *                school year has no terms.
     */
    public function suggestTermForCycle(int $cycleID): string
    {
        $cycle = $this->getCycleContext($cycleID);

        if (empty($cycle['gibbonSchoolYearID'])) {
            return '';
        }

        return $this->suggestTermForDate(
            (string) $cycle['gibbonSchoolYearID'],
            (string) ($cycle['dateStart'] ?? '')
        );
    }

    /**
     * The term of a school year that a date falls in.
     *
     * A date in the gap between two terms returns the term that has already
     * started, because work dated in a break belongs to the term before it.
     *
     * @param string $schoolYearID gibbonSchoolYearID to search.
     * @param string $date         Date in Y-m-d form. An empty date returns
     *                             the first term.
     *
     * @return string gibbonSchoolYearTermID, or an empty string when the
     *                school year has no terms.
     */
    public function suggestTermForDate(string $schoolYearID, string $date): string
    {
        $terms = $this->selectTermsBySchoolYear($schoolYearID);

        if (empty($terms)) {
            return '';
        }

        $firstTermID = (string) $terms[0]['gibbonSchoolYearTermID'];
        $date = substr(trim($date), 0, 10);

        if ($date === '') {
            return $firstTermID;
        }

        $started = '';

        foreach ($terms as $term) {
            $firstDay = (string) ($term['firstDay'] ?? '');
            $lastDay = (string) ($term['lastDay'] ?? '');

            if ($firstDay !== '' && $lastDay !== '' && $date >= $firstDay && $date <= $lastDay) {
                return (string) $term['gibbonSchoolYearTermID'];
            }

            if ($firstDay !== '' && $date >= $firstDay) {
                $started = (string) $term['gibbonSchoolYearTermID'];
            }
        }

        return $started !== '' ? $started : $firstTermID;
    }

    /**
     * Record, or re-record, the term that one stored grade column belongs to.
     *
     * The internal assessment column is the natural key, so a repeated store
     * of the same cycle updates the existing row rather than adding another.
     *
     * @param array $values Keys columnID, schoolYearID, termID, cycleID,
     *                      classID and actorID.
     *
     * @return bool
     */
    public function saveIndex(array $values): bool
    {
        $sql = "INSERT INTO academicRecordsStoredGrade
                    (gibbonInternalAssessmentColumnID, gibbonSchoolYearID, gibbonSchoolYearTermID,
                     gibbonReportingCycleID, gibbonCourseClassID, timestampCreated, gibbonPersonIDCreated)
                VALUES (:columnID, :schoolYearID, :termID, :cycleID, :classID, NOW(), :actorID)
                ON DUPLICATE KEY UPDATE
                    gibbonSchoolYearID = :schoolYearID,
                    gibbonSchoolYearTermID = :termID,
                    gibbonReportingCycleID = :cycleID,
                    gibbonCourseClassID = :classID,
                    timestampUpdated = NOW(),
                    gibbonPersonIDUpdated = :actorID";

        $data = [
            'columnID' => (int) ($values['columnID'] ?? 0),
            'schoolYearID' => (string) ($values['schoolYearID'] ?? ''),
            'termID' => (string) ($values['termID'] ?? ''),
            // A reindexed column has no cycle to point at, so the column stays null.
            'cycleID' => !empty($values['cycleID']) ? (int) $values['cycleID'] : null,
            'classID' => (int) ($values['classID'] ?? 0),
            'actorID' => (int) ($values['actorID'] ?? 0),
        ];

        return $this->db()->statement($sql, $data) !== false;
    }

    /**
     * The index row for one internal assessment column.
     *
     * @param int $columnID gibbonInternalAssessmentColumnID to read.
     *
     * @return array Empty when the column has not been indexed.
     */
    public function getIndexByColumn(int $columnID): array
    {
        $sql = "SELECT *
                FROM academicRecordsStoredGrade
                WHERE gibbonInternalAssessmentColumnID = :columnID";

        $data = ['columnID' => $columnID];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? $row : [];
    }

    /**
     * Stored grade columns that carry no term.
     *
     * These are grades stored before this index existed. The Transcript Setup
     * page lists them so an admin can set the term for each one.
     *
     * @param string $type The Internal Assessment Type this module writes.
     *
     * @return array
     */
    public function selectUnindexedColumns(string $type): array
    {
        $sql = "SELECT ic.gibbonInternalAssessmentColumnID,
                    ic.name,
                    ic.completeDate,
                    ic.gibbonCourseClassID,
                    co.gibbonSchoolYearID,
                    sy.name AS schoolYearName,
                    co.nameShort AS courseName,
                    cc.nameShort AS className
                FROM gibbonInternalAssessmentColumn AS ic
                JOIN gibbonCourseClass AS cc ON (cc.gibbonCourseClassID = ic.gibbonCourseClassID)
                JOIN gibbonCourse AS co ON (co.gibbonCourseID = cc.gibbonCourseID)
                JOIN gibbonSchoolYear AS sy ON (sy.gibbonSchoolYearID = co.gibbonSchoolYearID)
                LEFT JOIN academicRecordsStoredGrade AS sg
                    ON (sg.gibbonInternalAssessmentColumnID = ic.gibbonInternalAssessmentColumnID)
                WHERE ic.type = :type
                    AND ic.complete = 'Y'
                    AND sg.academicRecordsStoredGradeID IS NULL
                ORDER BY sy.name DESC, ic.name, co.nameShort, cc.nameShort";

        $data = ['type' => $type];

        return $this->db()->select($sql, $data)->fetchAll();
    }
}
