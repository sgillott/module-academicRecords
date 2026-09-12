<?php
/**
 * Reads the academic history a transcript is built from.
 *
 * A transcript is a grid of school years down the page and terms across it.
 * The rows come from course class enrolment, so a course the student is
 * taking now appears with empty cells until its grades are stored. Grades
 * are attached through the stored grade index, which is the only reliable
 * link between a stored grade and the term it belongs to.
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

class TranscriptGateway extends QueryableGateway
{
    use TableAware;
    use BindsInList;

    private static $tableName = 'academicRecordsTranscriptStudent';
    private static $primaryKey = 'academicRecordsTranscriptStudentID';

    /**
     * A student who has left a class still has to appear on the transcript,
     * together with whatever was stored for them while they were in it.
     */
    private const STUDENT_ROLES = "('Student', 'Student - Left')";

    /**
     * The school year a transcript is anchored on.
     *
     * @param string $gibbonReportID The report being generated.
     *
     * @return array Empty when the report does not exist.
     */
    public function getReportSchoolYear(string $gibbonReportID): array
    {
        $sql = "SELECT sy.gibbonSchoolYearID,
                    sy.name,
                    sy.sequenceNumber,
                    sy.firstDay,
                    sy.lastDay
                FROM gibbonReport AS r
                JOIN gibbonSchoolYear AS sy ON (sy.gibbonSchoolYearID = r.gibbonSchoolYearID)
                WHERE r.gibbonReportID = :gibbonReportID";

        $data = ['gibbonReportID' => $gibbonReportID];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? $row : [];
    }

    /**
     * The person behind a student enrolment record.
     *
     * @param string $gibbonStudentEnrolmentID Enrolment the report runs on.
     *
     * @return string gibbonPersonID, or an empty string.
     */
    public function getPersonByEnrolment(string $gibbonStudentEnrolmentID): string
    {
        $sql = "SELECT gibbonPersonID
                FROM gibbonStudentEnrolment
                WHERE gibbonStudentEnrolmentID = :gibbonStudentEnrolmentID";

        $data = ['gibbonStudentEnrolmentID' => $gibbonStudentEnrolmentID];

        $personID = $this->db()->selectOne($sql, $data);

        return !empty($personID) ? (string) $personID : '';
    }

    /**
     * School years the transcript covers, newest last.
     *
     * A year is listed only when the student was in at least one course class
     * that year. An enrolment with no classes leaves nothing to print, so the
     * year is dropped rather than shown empty.
     *
     * @param string $personID     The student.
     * @param int    $anchorSeq    sequenceNumber of the school year the
     *                             transcript is anchored on.
     * @param int    $yearCount    How many years to cover, including the
     *                             anchor year.
     *
     * @return array
     */
    public function selectSchoolYears(string $personID, int $anchorSeq, string $yearGroupList): array
    {
        $yearGroupFilter = $yearGroupList !== ''
            ? 'AND FIND_IN_SET(se.gibbonYearGroupID, :yearGroupList)'
            : '';

        $sql = "SELECT DISTINCT sy.gibbonSchoolYearID,
                    sy.name,
                    sy.sequenceNumber,
                    sy.firstDay,
                    sy.lastDay,
                    se.gibbonYearGroupID,
                    yg.name AS yearGroupName,
                    yg.nameShort AS yearGroupNameShort
                FROM gibbonStudentEnrolment AS se
                JOIN gibbonSchoolYear AS sy ON (sy.gibbonSchoolYearID = se.gibbonSchoolYearID)
                JOIN gibbonYearGroup AS yg ON (yg.gibbonYearGroupID = se.gibbonYearGroupID)
                JOIN gibbonCourse AS co ON (co.gibbonSchoolYearID = se.gibbonSchoolYearID)
                JOIN gibbonCourseClass AS cc ON (cc.gibbonCourseID = co.gibbonCourseID)
                JOIN gibbonCourseClassPerson AS ccp
                    ON (ccp.gibbonCourseClassID = cc.gibbonCourseClassID AND ccp.gibbonPersonID = se.gibbonPersonID)
                WHERE se.gibbonPersonID = :personID
                    AND ccp.role IN " . self::STUDENT_ROLES . "
                    AND sy.sequenceNumber <= :anchorSeq
                    {$yearGroupFilter}
                ORDER BY sy.sequenceNumber";

        $data = [
            'personID' => $personID,
            'anchorSeq' => $anchorSeq,
        ];

        if ($yearGroupList !== '') {
            $data['yearGroupList'] = $yearGroupList;
        }

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Terms of the given school years, in teaching order.
     *
     * The transcript reads its columns from these rows, so a school running
     * three terms gets three columns with no change here.
     *
     * @param array $schoolYearIDs School years to cover.
     *
     * @return array
     */
    public function selectTerms(array $schoolYearIDs): array
    {
        if (empty($schoolYearIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($schoolYearIDs, 'yr');

        $sql = "SELECT gibbonSchoolYearTermID,
                    gibbonSchoolYearID,
                    name,
                    nameShort,
                    sequenceNumber,
                    firstDay,
                    lastDay
                FROM gibbonSchoolYearTerm
                WHERE gibbonSchoolYearID IN ({$placeholders})
                ORDER BY gibbonSchoolYearID, sequenceNumber, firstDay";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Course classes the student was in, for the given school years.
     *
     * The row key is the class, not the course. A student who moves class
     * part way through a year therefore gets a row for each class, and each
     * row carries only the grades stored while they were in it.
     *
     * @param string $personID      The student.
     * @param array  $schoolYearIDs School years to cover.
     *
     * @return array
     */
    public function selectCourseClasses(string $personID, array $schoolYearIDs): array
    {
        if (empty($schoolYearIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($schoolYearIDs, 'yr');
        $data['personID'] = $personID;

        $sql = "SELECT co.gibbonSchoolYearID,
                    co.gibbonCourseID,
                    cc.gibbonCourseClassID,
                    co.name AS courseName,
                    co.nameShort AS courseNameShort,
                    cc.name AS className,
                    cc.nameShort AS classNameShort,
                    ccp.role
                FROM gibbonCourseClassPerson AS ccp
                JOIN gibbonCourseClass AS cc ON (cc.gibbonCourseClassID = ccp.gibbonCourseClassID)
                JOIN gibbonCourse AS co ON (co.gibbonCourseID = cc.gibbonCourseID)
                WHERE ccp.gibbonPersonID = :personID
                    AND ccp.role IN " . self::STUDENT_ROLES . "
                    AND co.gibbonSchoolYearID IN ({$placeholders})
                ORDER BY co.gibbonSchoolYearID, co.name, cc.nameShort";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Stored grades for the student, keyed by class and term.
     *
     * Ordered by completion date, so where a class and term somehow hold more
     * than one stored column, the most recent one is the one that survives
     * when the caller keys the rows.
     *
     * @param string $personID      The student.
     * @param array  $schoolYearIDs School years to cover.
     *
     * @return array
     */
    public function selectStoredGrades(string $personID, array $schoolYearIDs): array
    {
        if (empty($schoolYearIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($schoolYearIDs, 'yr');
        $data['personID'] = $personID;

        $sql = "SELECT sg.gibbonSchoolYearID,
                    sg.gibbonSchoolYearTermID,
                    sg.gibbonCourseClassID,
                    ic.name AS columnName,
                    ic.completeDate,
                    ic.gibbonScaleIDAttainment,
                    ie.attainmentValue,
                    ie.attainmentDescriptor,
                    ie.effortValue,
                    ie.effortDescriptor
                FROM academicRecordsStoredGrade AS sg
                JOIN gibbonInternalAssessmentColumn AS ic
                    ON (ic.gibbonInternalAssessmentColumnID = sg.gibbonInternalAssessmentColumnID)
                JOIN gibbonInternalAssessmentEntry AS ie
                    ON (ie.gibbonInternalAssessmentColumnID = ic.gibbonInternalAssessmentColumnID)
                WHERE ie.gibbonPersonIDStudent = :personID
                    AND sg.gibbonSchoolYearID IN ({$placeholders})
                ORDER BY ic.completeDate";

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * The year group the student was in for a given school year.
     *
     * Falls back to the most recent earlier enrolment, so a transcript can
     * still be produced for a student who has left.
     *
     * @param string $personID     The student.
     * @param int    $anchorSeq    sequenceNumber of the anchor school year.
     *
     * @return array Empty when the student has no enrolment at all.
     */
    public function getYearGroupAt(string $personID, int $anchorSeq): array
    {
        $sql = "SELECT yg.gibbonYearGroupID,
                    yg.name,
                    yg.nameShort,
                    yg.sequenceNumber,
                    sy.sequenceNumber AS schoolYearSequence
                FROM gibbonStudentEnrolment AS se
                JOIN gibbonSchoolYear AS sy ON (sy.gibbonSchoolYearID = se.gibbonSchoolYearID)
                JOIN gibbonYearGroup AS yg ON (yg.gibbonYearGroupID = se.gibbonYearGroupID)
                WHERE se.gibbonPersonID = :personID
                    AND sy.sequenceNumber <= :anchorSeq
                ORDER BY sy.sequenceNumber DESC
                LIMIT 1";

        $data = [
            'personID' => $personID,
            'anchorSeq' => $anchorSeq,
        ];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? $row : [];
    }

    /**
     * How many year groups sit above the given one.
     *
     * Counting rows rather than subtracting sequence numbers keeps this right
     * where the year group sequence has gaps, which it does below Grade 1.
     *
     * @param int $yearGroupSequence sequenceNumber of the student's year group.
     *
     * @return int
     */
    public function countYearGroupsAbove(int $yearGroupSequence): int
    {
        $sql = "SELECT COUNT(*)
                FROM gibbonYearGroup
                WHERE sequenceNumber > :yearGroupSequence";

        $data = ['yearGroupSequence' => $yearGroupSequence];

        return (int) $this->db()->selectOne($sql, $data);
    }

    /**
     * The school year at a given position in the sequence.
     *
     * @param int $sequenceNumber Position to read.
     *
     * @return array Empty when no school year is defined that far ahead.
     */
    public function getSchoolYearBySequence(int $sequenceNumber): array
    {
        $sql = "SELECT gibbonSchoolYearID,
                    name,
                    sequenceNumber,
                    firstDay,
                    lastDay
                FROM gibbonSchoolYear
                WHERE sequenceNumber = :sequenceNumber";

        $data = ['sequenceNumber' => $sequenceNumber];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? $row : [];
    }

    /**
     * Transcript values held against one student.
     *
     * @param string $personID The student.
     *
     * @return array Empty when nothing has been overridden.
     */
    public function getStudentValues(string $personID): array
    {
        $sql = "SELECT gibbonPersonID,
                    graduationYear,
                    graduationDate,
                    showGPA
                FROM academicRecordsTranscriptStudent
                WHERE gibbonPersonID = :personID";

        $data = ['personID' => $personID];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? $row : [];
    }

    /**
     * Transcript values for a set of students, keyed by gibbonPersonID.
     *
     * @param array $personIDs Students to read.
     *
     * @return array
     */
    public function selectStudentValuesKeyed(array $personIDs): array
    {
        if (empty($personIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($personIDs, 'p');

        $sql = "SELECT gibbonPersonID,
                    graduationYear,
                    graduationDate,
                    showGPA
                FROM academicRecordsTranscriptStudent
                WHERE gibbonPersonID IN ({$placeholders})";

        $rows = $this->db()->select($sql, $data)->fetchAll();
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['gibbonPersonID']] = $row;
        }

        return $keyed;
    }

    /**
     * Store the transcript overrides for one student.
     *
     * @param string      $personID       The student.
     * @param string|null $graduationYear Four digit year, or null to derive it.
     * @param string|null $graduationDate Y-m-d date, or null to derive it.
     * @param string      $showGPA        Y to print GPA for this student.
     * @param int         $actorID        Who made the change.
     *
     * @return bool
     */
    public function saveStudentValues(string $personID, ?string $graduationYear, ?string $graduationDate, string $showGPA, int $actorID): bool
    {
        $sql = "INSERT INTO academicRecordsTranscriptStudent
                    (gibbonPersonID, graduationYear, graduationDate, showGPA, timestampUpdated, gibbonPersonIDUpdated)
                VALUES (:personID, :graduationYear, :graduationDate, :showGPA, NOW(), :actorID)
                ON DUPLICATE KEY UPDATE
                    graduationYear = :graduationYear,
                    graduationDate = :graduationDate,
                    showGPA = :showGPA,
                    timestampUpdated = NOW(),
                    gibbonPersonIDUpdated = :actorID";

        $data = [
            'personID' => $personID,
            'graduationYear' => $graduationYear,
            'graduationDate' => $graduationDate,
            'showGPA' => $showGPA === 'Y' ? 'Y' : 'N',
            'actorID' => $actorID,
        ];

        return $this->db()->statement($sql, $data) !== false;
    }

    /**
     * Remove the overrides for a student, so both values are derived again.
     *
     * @param string $personID The student.
     *
     * @return bool
     */
    public function deleteStudentValues(string $personID): bool
    {
        $sql = "DELETE FROM academicRecordsTranscriptStudent
                WHERE gibbonPersonID = :personID";

        $data = ['personID' => $personID];

        return $this->db()->statement($sql, $data) !== false;
    }

    /**
     * Reports that a transcript can be generated from.
     *
     * Only templates built on the Student Enrolment context are listed,
     * because that is the context this module supplies identifiers for.
     *
     * @param string $schoolYearID School year to list reports for.
     *
     * @return array
     */
    public function selectTranscriptReports(string $schoolYearID): array
    {
        $sql = "SELECT r.gibbonReportID,
                    r.name,
                    rt.name AS templateName
                FROM gibbonReport AS r
                JOIN gibbonReportTemplate AS rt ON (rt.gibbonReportTemplateID = r.gibbonReportTemplateID)
                WHERE r.gibbonSchoolYearID = :schoolYearID
                    AND r.active = 'Y'
                    AND rt.context = 'Student Enrolment'
                ORDER BY r.name";

        $data = ['schoolYearID' => $schoolYearID];

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Everything the generator needs about one report.
     *
     * @param string $gibbonReportID The report to generate.
     *
     * @return array Empty when the report, its template or its archive is missing.
     */
    public function getReportForGeneration(string $gibbonReportID): array
    {
        $sql = "SELECT r.gibbonReportID,
                    r.name,
                    r.gibbonReportTemplateID,
                    r.gibbonReportArchiveID,
                    r.gibbonSchoolYearID,
                    r.gibbonReportingCycleID,
                    rt.context,
                    ra.path AS archivePath
                FROM gibbonReport AS r
                JOIN gibbonReportTemplate AS rt ON (rt.gibbonReportTemplateID = r.gibbonReportTemplateID)
                JOIN gibbonReportArchive AS ra ON (ra.gibbonReportArchiveID = r.gibbonReportArchiveID)
                WHERE r.gibbonReportID = :gibbonReportID";

        $data = ['gibbonReportID' => $gibbonReportID];

        $row = $this->db()->selectOne($sql, $data);

        return is_array($row) ? $row : [];
    }

    /**
     * Student enrolment records for a set of people in one school year.
     *
     * The Reports engine works from an enrolment, not a person, so a student
     * with no enrolment in the report's year cannot be generated.
     *
     * @param array  $personIDs    Students to resolve.
     * @param string $schoolYearID School year of the report.
     *
     * @return array Keyed by gibbonPersonID.
     */
    public function selectEnrolmentsKeyed(array $personIDs, string $schoolYearID): array
    {
        if (empty($personIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($personIDs, 'p');
        $data['schoolYearID'] = $schoolYearID;

        $sql = "SELECT se.gibbonPersonID,
                    se.gibbonStudentEnrolmentID,
                    se.gibbonYearGroupID,
                    se.gibbonFormGroupID
                FROM gibbonStudentEnrolment AS se
                WHERE se.gibbonSchoolYearID = :schoolYearID
                    AND se.gibbonPersonID IN ({$placeholders})";

        $rows = $this->db()->select($sql, $data)->fetchAll();
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['gibbonPersonID']] = $row;
        }

        return $keyed;
    }

    /**
     * The most recent archived transcript for each student on one report.
     *
     * @param string $gibbonReportID The report.
     * @param array  $personIDs      Students to look up.
     *
     * @return array Keyed by gibbonPersonID.
     */
    public function selectArchiveEntriesKeyed(string $gibbonReportID, array $personIDs): array
    {
        if (empty($personIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($personIDs, 'p');
        $data['gibbonReportID'] = $gibbonReportID;

        $sql = "SELECT gibbonReportArchiveEntryID,
                    gibbonPersonID,
                    status,
                    filePath,
                    timestampModified
                FROM gibbonReportArchiveEntry
                WHERE gibbonReportID = :gibbonReportID
                    AND type = 'Single'
                    AND gibbonPersonID IN ({$placeholders})
                ORDER BY timestampModified";

        $rows = $this->db()->select($sql, $data)->fetchAll();
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['gibbonPersonID']] = $row;
        }

        return $keyed;
    }

    /**
     * One of this module's settings.
     *
     * @param string $name Setting name within the Academic Records scope.
     *
     * @return string Empty when the setting is not set.
     */
    public function getSetting(string $name): string
    {
        $sql = "SELECT value
                FROM gibbonSetting
                WHERE scope = 'Academic Records'
                    AND name = :name";

        $data = ['name' => $name];

        $value = $this->db()->selectOne($sql, $data);

        return is_string($value) ? $value : '';
    }
}
