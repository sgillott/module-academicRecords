<?php

namespace Gibbon\Module\AcademicRecords\Domain;

use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;

class CAT4MappingGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'academicRecordsCAT4Mapping';
    private static $primaryKey = 'academicRecordsCAT4MappingID';

    public function selectAssessmentsActive(): array
    {
        $sql = "SELECT gibbonExternalAssessmentID, name, nameShort
                FROM gibbonExternalAssessment
                WHERE active = 'Y'
                ORDER BY name";
        return $this->db()->select($sql)->fetchAll();
    }

    public function getAssessment(int $assessmentID): ?array
    {
        $sql = "SELECT gibbonExternalAssessmentID, name, nameShort
                FROM gibbonExternalAssessment
                WHERE gibbonExternalAssessmentID = :id";

        $row = $this->db()->selectOne($sql, ['id' => $assessmentID]);

        return is_array($row) ? $row : null;
    }

    public function selectAssessmentFields(int $assessmentID): array
    {
        $sql = "SELECT gibbonExternalAssessmentFieldID, name, category, `order`, gibbonScaleID
                FROM gibbonExternalAssessmentField
                WHERE gibbonExternalAssessmentID = :id
                ORDER BY category, `order`, name";
        return $this->db()->select($sql, ['id' => $assessmentID])->fetchAll();
    }

    public function selectMappingsByAssessment(int $assessmentID): array
    {
        $sql = "SELECT academicRecordsCAT4MappingID, name, studentMatchField, studentIdentifierHeaderPattern, dateHeaderPattern,
                       importCategories, active, isDefault, timestampUpdated, timestampCreated
                FROM academicRecordsCAT4Mapping
                WHERE gibbonExternalAssessmentID = :id
                ORDER BY isDefault DESC, active DESC, name";
        return $this->db()->select($sql, ['id' => $assessmentID])->fetchAll();
    }

    public function selectDefaultMappings(): array
    {
        $sql = "SELECT m.*, ea.name AS assessmentName, ea.nameShort AS assessmentNameShort
                FROM academicRecordsCAT4Mapping m
                JOIN gibbonExternalAssessment ea
                  ON ea.gibbonExternalAssessmentID = m.gibbonExternalAssessmentID
                WHERE m.active = 'Y'
                  AND m.isDefault = 'Y'
                ORDER BY ea.name";

        return $this->db()->select($sql)->fetchAll();
    }

    public function getMapping(int $mappingID): ?array
    {
        $sql = "SELECT *
                FROM academicRecordsCAT4Mapping
                WHERE academicRecordsCAT4MappingID = :id";
        $row = $this->db()->selectOne($sql, ['id' => $mappingID]);
        return is_array($row) ? $row : null;
    }

    public function getDefaultMappingID(int $assessmentID): ?int
    {
        $sql = "SELECT academicRecordsCAT4MappingID
                FROM academicRecordsCAT4Mapping
                WHERE gibbonExternalAssessmentID = :id
                  AND active = 'Y'
                  AND isDefault = 'Y'
                ORDER BY timestampUpdated DESC, timestampCreated DESC
                LIMIT 1";

        // A one column select returns the value itself, or 0 for no row.
        $mappingID = (int) $this->db()->selectOne($sql, ['id' => $assessmentID]);

        return $mappingID > 0 ? $mappingID : null;
    }

    public function selectMappingFields(int $mappingID): array
    {
        $sql = "SELECT mf.gibbonExternalAssessmentFieldID, mf.headerPattern, mf.active,
                       ef.name, ef.category, ef.gibbonScaleID
                FROM academicRecordsCAT4MappingField mf
                JOIN gibbonExternalAssessmentField ef
                  ON ef.gibbonExternalAssessmentFieldID = mf.gibbonExternalAssessmentFieldID
                WHERE mf.academicRecordsCAT4MappingID = :id
                ORDER BY ef.category, ef.`order`, ef.name";
        return $this->db()->select($sql, ['id' => $mappingID])->fetchAll();
    }

    public function upsertMapping(array $data, ?int $mappingID = null): int
    {
        if ($mappingID) {
            $sql = "UPDATE academicRecordsCAT4Mapping
                    SET gibbonExternalAssessmentID = :gibbonExternalAssessmentID,
                        name = :name,
                        studentMatchField = :studentMatchField,
                        studentIdentifierHeaderPattern = :studentIdentifierHeaderPattern,
                        dateHeaderPattern = :dateHeaderPattern,
                        importCategories = :importCategories,
                        active = :active,
                        timestampUpdated = :timestampUpdated,
                        gibbonPersonIDUpdated = :gibbonPersonIDUpdated
                    WHERE academicRecordsCAT4MappingID = :id";
            $this->db()->update($sql, $data + ['id' => $mappingID]);
            return $mappingID;
        }

        $sql = "INSERT INTO academicRecordsCAT4Mapping
                (gibbonExternalAssessmentID, name, studentMatchField, studentIdentifierHeaderPattern, dateHeaderPattern,
                 importCategories, active, isDefault, timestampCreated, gibbonPersonIDCreated)
                VALUES
                (:gibbonExternalAssessmentID, :name, :studentMatchField, :studentIdentifierHeaderPattern, :dateHeaderPattern,
                 :importCategories, :active, 'N', :timestampCreated, :gibbonPersonIDCreated)";

        // Connection::insert() returns lastInsertId.
        return (int) $this->db()->insert($sql, $data);
    }

    public function replaceMappingFields(int $mappingID, array $fields): void
    {
        $sqlDel = "DELETE FROM academicRecordsCAT4MappingField
                   WHERE academicRecordsCAT4MappingID = :id";
        $this->db()->delete($sqlDel, ['id' => $mappingID]);

        $sqlIns = "INSERT INTO academicRecordsCAT4MappingField
                   (academicRecordsCAT4MappingID, gibbonExternalAssessmentFieldID, headerPattern, active)
                   VALUES (:mappingID, :fieldID, :pattern, :active)";

        foreach ($fields as $f) {
            $this->db()->insert($sqlIns, [
                'mappingID' => $mappingID,
                'fieldID'   => (int) $f['fieldID'],
                'pattern'   => (string) $f['pattern'],
                'active'    => ($f['active'] ?? 'Y') === 'N' ? 'N' : 'Y',
            ]);
        }
    }

    public function setDefaultMapping(int $assessmentID, int $mappingID): void
    {
        $sql1 = "UPDATE academicRecordsCAT4Mapping
                 SET isDefault = 'N'
                 WHERE gibbonExternalAssessmentID = :assessmentID";
        $this->db()->update($sql1, ['assessmentID' => $assessmentID]);

        $sql2 = "UPDATE academicRecordsCAT4Mapping
                 SET isDefault = 'Y'
                 WHERE academicRecordsCAT4MappingID = :mappingID
                   AND gibbonExternalAssessmentID = :assessmentID";
        $this->db()->update($sql2, [
            'mappingID' => $mappingID,
            'assessmentID' => $assessmentID,
        ]);
    }

    public function ensureDefaultMapping(int $assessmentID, int $personID): int
    {
        $mappingID = $this->getDefaultMappingID($assessmentID);

        if ($mappingID !== null && $mappingID > 0) {
            return $mappingID;
        }

        $existingMappings = $this->selectMappingsByAssessment($assessmentID);
        if (!empty($existingMappings)) {
            $existingMappingID = (int) ($existingMappings[0]['academicRecordsCAT4MappingID'] ?? 0);

            if ($existingMappingID > 0) {
                $this->setDefaultMapping($assessmentID, $existingMappingID);
                return $existingMappingID;
            }
        }

        $assessment = $this->getAssessment($assessmentID);
        $assessmentName = trim((string) ($assessment['name'] ?? 'External Assessment'));

        $mappingID = $this->upsertMapping([
            'gibbonExternalAssessmentID' => $assessmentID,
            'name' => $assessmentName . ' Default Mapping',
            'studentMatchField' => 'studentID',
            'studentIdentifierHeaderPattern' => 'Student ID',
            'dateHeaderPattern' => 'Date of test',
            'importCategories' => '[]',
            'active' => 'Y',
            'timestampCreated' => date('Y-m-d H:i:s'),
            'gibbonPersonIDCreated' => $personID,
        ]);

        if ($mappingID <= 0) {
            throw new \RuntimeException('Unable to create CAT4 default mapping record.');
        }

        $this->setDefaultMapping($assessmentID, $mappingID);

        return $mappingID;
    }
}
