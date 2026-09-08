<?php

namespace Gibbon\Module\AcademicRecords\Domain;

use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;

class CAT4ImportGateway extends QueryableGateway
{
    use TableAware;

    private function normalizeNullableId($result, string $key): ?int
    {
        if (is_array($result) && isset($result[$key])) {
            $value = (int) $result[$key];
            return $value > 0 ? $value : null;
        }

        if (is_string($result) || is_int($result)) {
            $value = (int) $result;
            return $value > 0 ? $value : null;
        }

        return null;
    }

    public function findStudentByField(string $field, string $value): ?array
    {
        $allowed = ['studentID', 'gibbonPersonID', 'username'];
        if (!in_array($field, $allowed, true)) {
            return null;
        }

        $sql = "SELECT gibbonPersonID
                FROM gibbonPerson
                WHERE {$field} = :value";

        $result = $this->db()->selectOne($sql, [
            'value' => trim($value),
        ]);

        $personID = $this->normalizeNullableId($result, 'gibbonPersonID');

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

        $result = $this->db()->selectOne($sql, [
            'assessmentID' => $assessmentID,
            'personID'     => $personID,
            'date'         => $date,
        ]);

        return $this->normalizeNullableId($result, 'gibbonExternalAssessmentStudentID');
    }

    public function insertExternalAssessmentStudent(int $assessmentID, int $personID, string $date): ?int
    {
        $sql = "INSERT INTO gibbonExternalAssessmentStudent
                (gibbonExternalAssessmentID, gibbonPersonID, date, attachment)
                VALUES (:assessmentID, :personID, :date, '')";

        $result = $this->db()->insert($sql, [
            'assessmentID' => $assessmentID,
            'personID'     => $personID,
            'date'         => $date,
        ]);

        return is_numeric($result) && (int) $result > 0
            ? (int) $result
            : null;
    }

    public function getFieldsByAssessment(int $assessmentID): array
    {
        $sql = "SELECT gibbonExternalAssessmentFieldID, name, gibbonScaleID
                FROM gibbonExternalAssessmentField
                WHERE gibbonExternalAssessmentID = :assessmentID";

        return $this->db()->select($sql, ['assessmentID' => $assessmentID])->fetchAll();
    }

    public function findScaleGradeID(int $scaleID, string $value): ?int
    {
        $sql = "SELECT gibbonScaleGradeID
                FROM gibbonScaleGrade
                WHERE gibbonScaleID = :scaleID
                  AND value = :value";

        $result = $this->db()->selectOne($sql, [
            'scaleID' => $scaleID,
            'value'   => $value,
        ]);

        return $this->normalizeNullableId($result, 'gibbonScaleGradeID');
    }

    public function findEntryID(int $externalAssessmentStudentID, int $fieldID): ?int
    {
        $sql = "SELECT gibbonExternalAssessmentStudentEntryID
                FROM gibbonExternalAssessmentStudentEntry
                WHERE gibbonExternalAssessmentStudentID = :studentID
                  AND gibbonExternalAssessmentFieldID = :fieldID";

        $result = $this->db()->selectOne($sql, [
            'studentID' => $externalAssessmentStudentID,
            'fieldID'   => $fieldID,
        ]);

        return $this->normalizeNullableId($result, 'gibbonExternalAssessmentStudentEntryID');
    }

    public function findEntryGradeID(int $entryID): ?int
    {
        $sql = "SELECT gibbonScaleGradeID
                FROM gibbonExternalAssessmentStudentEntry
                WHERE gibbonExternalAssessmentStudentEntryID = :entryID";

        $result = $this->db()->selectOne($sql, [
            'entryID' => $entryID,
        ]);

        if (is_array($result) && array_key_exists('gibbonScaleGradeID', $result)) {
            return $result['gibbonScaleGradeID'] !== null && (int) $result['gibbonScaleGradeID'] > 0
                ? (int) $result['gibbonScaleGradeID']
                : null;
        }

        return null;
    }

    public function insertEntry(int $externalAssessmentStudentID, int $fieldID, ?int $gradeID): ?int
    {
        $sql = "INSERT INTO gibbonExternalAssessmentStudentEntry
                (gibbonExternalAssessmentStudentID, gibbonExternalAssessmentFieldID, gibbonScaleGradeID)
                VALUES (:studentID, :fieldID, :gradeID)";

        $result = $this->db()->insert($sql, [
            'studentID' => $externalAssessmentStudentID,
            'fieldID'   => $fieldID,
            'gradeID'   => $gradeID,
        ]);

        return is_numeric($result) && (int) $result > 0
            ? (int) $result
            : null;
    }

    public function updateEntry(int $entryID, ?int $gradeID): bool
    {
        $sql = "UPDATE gibbonExternalAssessmentStudentEntry
                SET gibbonScaleGradeID = :gradeID
                WHERE gibbonExternalAssessmentStudentEntryID = :entryID";

        return (bool) $this->db()->update($sql, [
            'entryID' => $entryID,
            'gradeID' => $gradeID,
        ]);
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->db()->getErrorMessage();
    }
}
