<?php
/**
 * Reads and writes the External Assessment rows a CAT4 import touches.
 *
 * Every lookup here selects one column, and Connection::selectOne returns
 * that value itself, or 0 when there is no row. An id of 0 is never valid,
 * so a lookup returns null for "not found" and a positive int otherwise.
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

class CAT4ImportGateway extends QueryableGateway
{
    use TableAware;
    use BindsInList;

    /**
     * The gibbonPerson fields a spreadsheet identifier may be matched on.
     */
    public const STUDENT_MATCH_FIELDS = ['studentID', 'gibbonPersonID', 'username'];

    /**
     * A positive id, or null for none.
     *
     * @param mixed $value Result of a one column selectOne.
     *
     * @return int|null
     */
    private function idOrNull($value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    public function findStudentByField(string $field, string $value): ?array
    {
        if (!in_array($field, self::STUDENT_MATCH_FIELDS, true)) {
            return null;
        }

        $sql = "SELECT gibbonPersonID
                FROM gibbonPerson
                WHERE {$field} = :value";

        $personID = $this->idOrNull($this->db()->selectOne($sql, ['value' => trim($value)]));

        return $personID !== null
            ? ['gibbonPersonID' => $personID]
            : null;
    }

    public function findExternalAssessmentStudentID(int $assessmentID, int $personID, string $date): ?int
    {
        $sql = "SELECT gibbonExternalAssessmentStudentID
                FROM gibbonExternalAssessmentStudent
                WHERE gibbonExternalAssessmentID = :assessmentID
                  AND gibbonPersonID = :personID
                  AND date = :date";

        $data = [
            'assessmentID' => $assessmentID,
            'personID'     => $personID,
            'date'         => $date,
        ];

        return $this->idOrNull($this->db()->selectOne($sql, $data));
    }

    public function insertExternalAssessmentStudent(int $assessmentID, int $personID, string $date): ?int
    {
        $sql = "INSERT INTO gibbonExternalAssessmentStudent
                (gibbonExternalAssessmentID, gibbonPersonID, date, attachment)
                VALUES (:assessmentID, :personID, :date, '')";

        $data = [
            'assessmentID' => $assessmentID,
            'personID'     => $personID,
            'date'         => $date,
        ];

        return $this->idOrNull($this->db()->insert($sql, $data));
    }

    public function findScaleGradeID(int $scaleID, string $value): ?int
    {
        $sql = "SELECT gibbonScaleGradeID
                FROM gibbonScaleGrade
                WHERE gibbonScaleID = :scaleID
                  AND value = :value";

        $data = [
            'scaleID' => $scaleID,
            'value'   => $value,
        ];

        return $this->idOrNull($this->db()->selectOne($sql, $data));
    }

    public function findEntryID(int $externalAssessmentStudentID, int $fieldID): ?int
    {
        $sql = "SELECT gibbonExternalAssessmentStudentEntryID
                FROM gibbonExternalAssessmentStudentEntry
                WHERE gibbonExternalAssessmentStudentID = :studentID
                  AND gibbonExternalAssessmentFieldID = :fieldID";

        $data = [
            'studentID' => $externalAssessmentStudentID,
            'fieldID'   => $fieldID,
        ];

        return $this->idOrNull($this->db()->selectOne($sql, $data));
    }

    public function findEntryGradeID(int $entryID): ?int
    {
        $sql = "SELECT gibbonScaleGradeID
                FROM gibbonExternalAssessmentStudentEntry
                WHERE gibbonExternalAssessmentStudentEntryID = :entryID";

        return $this->idOrNull($this->db()->selectOne($sql, ['entryID' => $entryID]));
    }

    public function insertEntry(int $externalAssessmentStudentID, int $fieldID, ?int $gradeID): ?int
    {
        $sql = "INSERT INTO gibbonExternalAssessmentStudentEntry
                (gibbonExternalAssessmentStudentID, gibbonExternalAssessmentFieldID, gibbonScaleGradeID)
                VALUES (:studentID, :fieldID, :gradeID)";

        $data = [
            'studentID' => $externalAssessmentStudentID,
            'fieldID'   => $fieldID,
            'gradeID'   => $gradeID,
        ];

        return $this->idOrNull($this->db()->insert($sql, $data));
    }

    public function updateEntry(int $entryID, ?int $gradeID): bool
    {
        $sql = "UPDATE gibbonExternalAssessmentStudentEntry
                SET gibbonScaleGradeID = :gradeID
                WHERE gibbonExternalAssessmentStudentEntryID = :entryID";

        $data = [
            'entryID' => $entryID,
            'gradeID' => $gradeID,
        ];

        return (bool) $this->db()->update($sql, $data);
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->db()->getErrorMessage();
    }

    /* ---------------------------------------------------------
       Batch reads

       The importer loads what a file needs up front, then reads from
       memory. Each returns rows keyed for that lookup.
    --------------------------------------------------------- */

    /**
     * People matched on one field, keyed by the value held in the database.
     *
     * @param string $field  One of STUDENT_MATCH_FIELDS.
     * @param array  $values Identifiers found in the file.
     *
     * @return array Field value to gibbonPersonID.
     */
    public function selectPersonIDsByFieldKeyed(string $field, array $values): array
    {
        if (!in_array($field, self::STUDENT_MATCH_FIELDS, true) || empty($values)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($values, 'v', $field === 'gibbonPersonID');

        $sql = "SELECT {$field} AS matchValue, gibbonPersonID
                FROM gibbonPerson
                WHERE {$field} IN ({$placeholders})";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $keyed[(string) $row['matchValue']] = (int) $row['gibbonPersonID'];
        }

        return $keyed;
    }

    /**
     * Every grade of the given scales, keyed by scale then value.
     *
     * @param array $scaleIDs gibbonScaleID values.
     *
     * @return array
     */
    public function selectScaleGradesKeyed(array $scaleIDs): array
    {
        $scaleIDs = array_filter(array_map('intval', $scaleIDs));

        if (empty($scaleIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($scaleIDs, 's', true);

        $sql = "SELECT gibbonScaleID, value, gibbonScaleGradeID
                FROM gibbonScaleGrade
                WHERE gibbonScaleID IN ({$placeholders})";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $keyed[(int) $row['gibbonScaleID']][(string) $row['value']] = (int) $row['gibbonScaleGradeID'];
        }

        return $keyed;
    }

    /**
     * Existing student rows of one assessment on the given dates, keyed by
     * person then date.
     *
     * @param int   $assessmentID gibbonExternalAssessmentID.
     * @param array $dates        Y-m-d dates found in the file.
     *
     * @return array
     */
    public function selectExternalAssessmentStudentsKeyed(int $assessmentID, array $dates): array
    {
        if (empty($dates)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($dates, 'd');
        $data['assessmentID'] = $assessmentID;

        $sql = "SELECT gibbonExternalAssessmentStudentID, gibbonPersonID, date
                FROM gibbonExternalAssessmentStudent
                WHERE gibbonExternalAssessmentID = :assessmentID
                  AND date IN ({$placeholders})
                ORDER BY gibbonExternalAssessmentStudentID";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $personID = (int) $row['gibbonPersonID'];
            $date = (string) $row['date'];

            // The first row wins, the same as a single selectOne would.
            if (!isset($keyed[$personID][$date])) {
                $keyed[$personID][$date] = (int) $row['gibbonExternalAssessmentStudentID'];
            }
        }

        return $keyed;
    }

    /**
     * Entries of the given student assessment rows, keyed by row then field.
     *
     * @param array $studentAssessmentIDs gibbonExternalAssessmentStudentID values.
     *
     * @return array Each leaf holds entryID and gradeID.
     */
    public function selectEntriesKeyed(array $studentAssessmentIDs): array
    {
        $studentAssessmentIDs = array_filter(array_map('intval', $studentAssessmentIDs));

        if (empty($studentAssessmentIDs)) {
            return [];
        }

        [$placeholders, $data] = $this->inList($studentAssessmentIDs, 'e', true);

        $sql = "SELECT gibbonExternalAssessmentStudentEntryID,
                    gibbonExternalAssessmentStudentID,
                    gibbonExternalAssessmentFieldID,
                    gibbonScaleGradeID
                FROM gibbonExternalAssessmentStudentEntry
                WHERE gibbonExternalAssessmentStudentID IN ({$placeholders})
                ORDER BY gibbonExternalAssessmentStudentEntryID";

        $keyed = [];

        foreach ($this->db()->select($sql, $data)->fetchAll() as $row) {
            $rowID = (int) $row['gibbonExternalAssessmentStudentID'];
            $fieldID = (int) $row['gibbonExternalAssessmentFieldID'];

            if (!isset($keyed[$rowID][$fieldID])) {
                $keyed[$rowID][$fieldID] = [
                    'entryID' => (int) $row['gibbonExternalAssessmentStudentEntryID'],
                    'gradeID' => $this->idOrNull($row['gibbonScaleGradeID']),
                ];
            }
        }

        return $keyed;
    }

}
