<?php
/**
 * Reads and writes for the historic grade import.
 *
 * The import names its targets in words: a school year by name, a term by
 * its position in that year, a course by name and a student by studentID.
 * Every lookup here turns one of those words into an ID, and every read is
 * whole sets rather than single rows, because the importer resolves a file
 * of thousands of rows against memory rather than against the database.
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
use Gibbon\Module\AcademicRecords\Domain\Traits\BindsInList;

class HistoricGradeGateway extends QueryableGateway
{
    use BindsInList;

    /**
     * This gateway owns no table of its own. It reads across the school
     * year, course and person tables, and writes to the core internal
     * assessment tables, so there is nothing for a paged query to count.
     *
     * @return int
     */
    protected function countAll(): int
    {
        return 0;
    }

    /**
     * Nothing here is reached through a search box.
     *
     * @return array
     */
    protected function getSearchableColumns(): array
    {
        return [];
    }

    /**
     * Every school year, keyed by its name folded for comparison.
     *
     * @return array
     */
    public function selectSchoolYearsKeyed(): array
    {
        $sql = "SELECT gibbonSchoolYearID,
                    name,
                    status,
                    firstDay,
                    lastDay,
                    sequenceNumber
                FROM gibbonSchoolYear
                ORDER BY sequenceNumber";

        $keyed = [];

        foreach ($this->db()->select($sql)->fetchAll() as $row) {
            $keyed[self::foldKey((string) $row['name'])] = $row;
        }

        return $keyed;
    }

    /**
     * Terms of the given school years, keyed by year then by position.
     *
     * The position is counted here rather than read from sequenceNumber,
     * because a Semester number in the file means the first or the second
     * term taught, whatever numbering the school year happens to hold.
     *
     * @param array $schoolYearIDs Years to read.
     *
     * @return array [schoolYearID][1..n] => term row.
     */
    public function selectTermsKeyed(array $schoolYearIDs): array
    {
        if (empty($schoolYearIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($schoolYearIDs, 'y');

        $sql = "SELECT gibbonSchoolYearTermID,
                    gibbonSchoolYearID,
                    name,
                    nameShort,
                    firstDay,
                    lastDay,
                    sequenceNumber
                FROM gibbonSchoolYearTerm
                WHERE gibbonSchoolYearID IN ({$placeholders})
                ORDER BY gibbonSchoolYearID, sequenceNumber, firstDay";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $yearID = (string) $row['gibbonSchoolYearID'];
            $keyed[$yearID][count($keyed[$yearID] ?? []) + 1] = $row;
        }

        return $keyed;
    }

    /**
     * Person IDs of the named students, keyed by studentID folded.
     *
     * Only people whose primary role is a student are matched, so a staff
     * member who happens to carry a student number is never written to.
     *
     * @param array $studentIDs Values from the file.
     *
     * @return array
     */
    public function selectPersonIDsByStudentID(array $studentIDs): array
    {
        if (empty($studentIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($studentIDs, 's');

        $sql = "SELECT p.gibbonPersonID,
                    p.studentID
                FROM gibbonPerson AS p
                JOIN gibbonRole AS r ON (r.gibbonRoleID = p.gibbonRoleIDPrimary AND r.category = 'Student')
                WHERE p.studentID IN ({$placeholders})";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $keyed[self::foldKey((string) $row['studentID'])] = (string) $row['gibbonPersonID'];
        }

        return $keyed;
    }

    /**
     * The year group each student was in, keyed by person then school year.
     *
     * Read so the import can check the Grade Level column against what
     * Gibbon holds. It is a cross check only; the enrolment stays correct.
     *
     * @param array $personIDs     Students in the file.
     * @param array $schoolYearIDs Years in the file.
     *
     * @return array
     */
    public function selectYearGroupsKeyed(array $personIDs, array $schoolYearIDs): array
    {
        if (empty($personIDs) || empty($schoolYearIDs)) {
            return [];
        }

        [$personPlaceholders, $personData] = $this->inList($personIDs, 'p');
        [$yearPlaceholders, $yearData] = $this->inList($schoolYearIDs, 'y');

        $sql = "SELECT se.gibbonPersonID,
                    se.gibbonSchoolYearID,
                    yg.name,
                    yg.nameShort
                FROM gibbonStudentEnrolment AS se
                JOIN gibbonYearGroup AS yg ON (yg.gibbonYearGroupID = se.gibbonYearGroupID)
                WHERE se.gibbonPersonID IN ({$personPlaceholders})
                    AND se.gibbonSchoolYearID IN ({$yearPlaceholders})";

        $keyed = [];

        foreach ($this->db()->select($sql, $personData + $yearData)->fetchAll() as $row) {
            $keyed[(string) $row['gibbonPersonID']][(string) $row['gibbonSchoolYearID']] = $row;
        }

        return $keyed;
    }

    /**
     * Courses of the given school years, keyed by year then name folded.
     *
     * Rows are collected in a list per name, so the importer can tell one
     * match from an ambiguous pair rather than silently taking the first.
     *
     * @param array $schoolYearIDs Years to read.
     *
     * @return array [schoolYearID][name] => list of course rows.
     */
    public function selectCoursesKeyed(array $schoolYearIDs): array
    {
        if (empty($schoolYearIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($schoolYearIDs, 'y');

        $sql = "SELECT gibbonCourseID,
                    gibbonSchoolYearID,
                    name,
                    nameShort
                FROM gibbonCourse
                WHERE gibbonSchoolYearID IN ({$placeholders})
                ORDER BY name";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $keyed[(string) $row['gibbonSchoolYearID']][self::foldKey((string) $row['name'])][] = $row;
        }

        return $keyed;
    }

    /**
     * Class enrolments of the given students in the given school years.
     *
     * A student who left a class part way through the year is included,
     * because the grade they earned before they left is still a record.
     *
     * @param array $personIDs     Students in the file.
     * @param array $schoolYearIDs Years in the file.
     *
     * @return array [personID][courseID] => list of class rows.
     */
    public function selectClassEnrolmentsKeyed(array $personIDs, array $schoolYearIDs): array
    {
        if (empty($personIDs) || empty($schoolYearIDs)) {
            return [];
        }

        [$personPlaceholders, $personData] = $this->inList($personIDs, 'p');
        [$yearPlaceholders, $yearData] = $this->inList($schoolYearIDs, 'y');

        $sql = "SELECT ccp.gibbonPersonID,
                    co.gibbonCourseID,
                    co.gibbonSchoolYearID,
                    co.name AS courseName,
                    cc.gibbonCourseClassID,
                    cc.name AS className,
                    cc.nameShort AS classNameShort
                FROM gibbonCourseClassPerson AS ccp
                JOIN gibbonCourseClass AS cc ON (cc.gibbonCourseClassID = ccp.gibbonCourseClassID)
                JOIN gibbonCourse AS co ON (co.gibbonCourseID = cc.gibbonCourseID)
                WHERE ccp.gibbonPersonID IN ({$personPlaceholders})
                    AND co.gibbonSchoolYearID IN ({$yearPlaceholders})
                    AND ccp.role IN ('Student', 'Student - Left')
                ORDER BY cc.nameShort";

        $keyed = [];

        foreach ($this->db()->select($sql, $personData + $yearData)->fetchAll() as $row) {
            $keyed[(string) $row['gibbonPersonID']][(string) $row['gibbonCourseID']][] = $row;
        }

        return $keyed;
    }

    /**
     * Every grade of every active scale, keyed by scale then value.
     *
     * The import has no scale of its own to go on, so the values in the
     * file are matched against these to choose the column's scale.
     *
     * @return array [scaleID] => [value => descriptor].
     */
    public function selectScaleValuesKeyed(): array
    {
        $sql = "SELECT sg.gibbonScaleID,
                    sg.value,
                    sg.descriptor,
                    s.name,
                    s.nameShort
                FROM gibbonScaleGrade AS sg
                JOIN gibbonScale AS s ON (s.gibbonScaleID = sg.gibbonScaleID)
                WHERE s.active = 'Y'
                ORDER BY s.name, sg.sequenceNumber";

        $keyed = [];

        foreach ($this->db()->select($sql)->fetchAll() as $row) {
            $keyed[(string) $row['gibbonScaleID']][self::foldKey((string) $row['value'])] = $row;
        }

        return $keyed;
    }

    /**
     * Names of the active scales, keyed by ID, for a select list.
     *
     * @return array
     */
    public function selectScaleOptions(): array
    {
        $sql = "SELECT gibbonScaleID,
                    name
                FROM gibbonScale
                WHERE active = 'Y'
                ORDER BY name";

        $options = [];

        foreach ($this->db()->select($sql)->fetchAll() as $row) {
            $options[(string) $row['gibbonScaleID']] = (string) $row['name'];
        }

        return $options;
    }

    /**
     * The scale the module's existing stored grade columns use most.
     *
     * Used only to preselect the scale on the first step, so the common
     * case needs no thought. The user can always choose another.
     *
     * @param string $type Internal Assessment Type this module writes.
     *
     * @return string Empty when nothing has been stored yet.
     */
    public function getUsualScale(string $type): string
    {
        $sql = "SELECT gibbonScaleIDAttainment,
                    COUNT(*) AS columns_
                FROM gibbonInternalAssessmentColumn
                WHERE type = :type
                    AND gibbonScaleIDAttainment IS NOT NULL
                GROUP BY gibbonScaleIDAttainment
                ORDER BY columns_ DESC
                LIMIT 1";

        $data = ['type' => $type];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? (string) $row['gibbonScaleIDAttainment'] : '';
    }

    /**
     * Stored grade columns of the given classes, keyed by class then name.
     *
     * A column is matched on class, name and type, which is how a repeated
     * import finds the column an earlier run wrote rather than adding one.
     *
     * @param array  $classIDs Classes in the plan.
     * @param string $type     Internal Assessment Type this module writes.
     *
     * @return array
     */
    public function selectColumnsKeyed(array $classIDs, string $type): array
    {
        if (empty($classIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($classIDs, 'c');
        $data['type'] = $type;

        $sql = "SELECT gibbonInternalAssessmentColumnID,
                    gibbonCourseClassID,
                    name,
                    attainment,
                    gibbonScaleIDAttainment,
                    effort,
                    gibbonScaleIDEffort,
                    completeDate
                FROM gibbonInternalAssessmentColumn
                WHERE gibbonCourseClassID IN ({$placeholders})
                    AND type = :type";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $keyed[(string) $row['gibbonCourseClassID']][(string) $row['name']] = $row;
        }

        return $keyed;
    }

    /**
     * Entries of the given columns, keyed by column then student.
     *
     * Keyed on the column ID as an integer, because the ID columns are
     * ZEROFILL and the padded form a query returns would not match the
     * plain number the caller holds.
     *
     * @param array $columnIDs Columns already in the database.
     *
     * @return array
     */
    public function selectEntriesKeyed(array $columnIDs): array
    {
        if (empty($columnIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($columnIDs, 'k');

        $sql = "SELECT gibbonInternalAssessmentEntryID,
                    gibbonInternalAssessmentColumnID,
                    gibbonPersonIDStudent,
                    attainmentValue,
                    attainmentDescriptor,
                    effortValue
                FROM gibbonInternalAssessmentEntry
                WHERE gibbonInternalAssessmentColumnID IN ({$placeholders})";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $columnID = (int) $row['gibbonInternalAssessmentColumnID'];
            $keyed[$columnID][(string) $row['gibbonPersonIDStudent']] = $row;
        }

        return $keyed;
    }

    /**
     * The next grouping number, so one import's columns hang together.
     *
     * @return int
     */
    public function getNextGroupingID(): int
    {
        $sql = "SELECT COALESCE(MAX(groupingID), 0) + 1 AS nextGrouping
                FROM gibbonInternalAssessmentColumn";

        $next = (int) $this->db()->selectOne($sql);

        return $next > 0 ? $next : 1;
    }

    /**
     * Add one Internal Assessment column.
     *
     * @param array $values Column values, already resolved.
     *
     * @return int The new column ID.
     *
     * @throws \RuntimeException When the write fails.
     */
    public function insertColumn(array $values): int
    {
        $insert = $this->newInsert()
            ->into('gibbonInternalAssessmentColumn')
            ->cols($values);

        $columnID = (int) $this->runInsert($insert);

        if ($columnID <= 0) {
            throw new \RuntimeException(__('Failed to add an Internal Assessment column.'));
        }

        return $columnID;
    }

    /**
     * Change the scale or the enabled fields of one column.
     *
     * @param int   $columnID Column to change.
     * @param array $values   Columns to set.
     *
     * @return void
     *
     * @throws \RuntimeException When the write fails.
     */
    public function updateColumn(int $columnID, array $values): void
    {
        $update = $this->newUpdate()
            ->table('gibbonInternalAssessmentColumn')
            ->cols($values)
            ->where('gibbonInternalAssessmentColumnID = :columnID')
            ->bindValue('columnID', $columnID);

        if ($this->runUpdate($update) === false) {
            throw new \RuntimeException(__('Failed to update an Internal Assessment column.'));
        }
    }

    /**
     * Add one Internal Assessment entry.
     *
     * @param array $values Entry values.
     *
     * @return void
     *
     * @throws \RuntimeException When the write fails.
     */
    public function insertEntry(array $values): void
    {
        $insert = $this->newInsert()
            ->into('gibbonInternalAssessmentEntry')
            ->cols($values);

        if ((int) $this->runInsert($insert) <= 0) {
            throw new \RuntimeException(__('Failed to add an Internal Assessment entry.'));
        }
    }

    /**
     * Change one Internal Assessment entry.
     *
     * @param int   $entryID Entry to change.
     * @param array $values  Columns to set.
     *
     * @return void
     *
     * @throws \RuntimeException When the write fails.
     */
    public function updateEntry(int $entryID, array $values): void
    {
        $update = $this->newUpdate()
            ->table('gibbonInternalAssessmentEntry')
            ->cols($values)
            ->where('gibbonInternalAssessmentEntryID = :entryID')
            ->bindValue('entryID', $entryID);

        if ($this->runUpdate($update) === false) {
            throw new \RuntimeException(__('Failed to update an Internal Assessment entry.'));
        }
    }

    /**
     * Fold a value so a lookup ignores case and surrounding space.
     *
     * The file is a hand kept export, so a trailing space in a heading or
     * a subject name is normal and must not lose a row.
     *
     * @param string $value Value to fold.
     *
     * @return string
     */
    public static function foldKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
