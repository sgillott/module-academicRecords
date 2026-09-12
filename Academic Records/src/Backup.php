<?php
/**
 * Exports and restores the module's settings as one JSON file.
 *
 * The file holds the module's gibbonSetting rows, the CAT4 mappings with
 * their fields, the Testwise year group mappings, the grade setup of each
 * scale and the credit of each course. Year group, scale and course names
 * travel with their rows, because the IDs are specific to one install and
 * will not line up on another. Not included: the stored grade index and the
 * per student transcript overrides, which only mean something on the
 * install that wrote them.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Module\AcademicRecords\Testwise\Year;

class Backup
{
    /**
     * @var Connection
     */
    private $db;

    /**
     * @param Connection $db Shared database connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Everything the module holds, ready to write as JSON.
     *
     * @return array
     */
    public function build(): array
    {
        $settings = $this->db->select(
            "SELECT scope, name, nameDisplay, description, value
             FROM gibbonSetting
             WHERE scope = 'Academic Records'
             ORDER BY name"
        )->fetchAll();

        $mappings = $this->db->select(
            "SELECT academicRecordsCAT4MappingID, gibbonExternalAssessmentID, name, studentMatchField,
                    studentIdentifierHeaderPattern, dateHeaderPattern, importCategories, active, isDefault
             FROM academicRecordsCAT4Mapping
             ORDER BY gibbonExternalAssessmentID, academicRecordsCAT4MappingID"
        )->fetchAll();

        $fields = $this->db->select(
            "SELECT academicRecordsCAT4MappingID, gibbonExternalAssessmentFieldID, headerPattern, active
             FROM academicRecordsCAT4MappingField
             ORDER BY academicRecordsCAT4MappingID, gibbonExternalAssessmentFieldID"
        )->fetchAll();

        $yearGroupMappings = $this->db->select(
            "SELECT m.gibbonYearGroupID, yg.name AS yearGroupName, yg.nameShort AS yearGroupNameShort, m.testwiseYear
             FROM academicRecordsYearGroupMap m
             LEFT JOIN gibbonYearGroup yg ON (yg.gibbonYearGroupID = m.gibbonYearGroupID)
             ORDER BY yg.sequenceNumber, m.gibbonYearGroupID"
        )->fetchAll();

        // The scale and course names travel with these rows for the same
        // reason as the year group names: their IDs differ between installs.
        $gradeSettings = $this->db->select(
            "SELECT gs.gibbonScaleID, s.name AS scaleName, s.nameShort AS scaleNameShort,
                    gs.value, gs.creditFactor, gs.percentMin, gs.percentMax, gs.gpaPoints, gs.gpaLetter
             FROM academicRecordsGradeSetting gs
             LEFT JOIN gibbonScale s ON (s.gibbonScaleID = gs.gibbonScaleID)
             ORDER BY s.name, gs.value"
        )->fetchAll();

        $courseCredits = $this->db->select(
            "SELECT cc.gibbonCourseID, sy.name AS schoolYearName, co.nameShort AS courseNameShort, co.name AS courseName,
                    cc.creditPerTerm, cc.showOnTranscript
             FROM academicRecordsCourseCredit cc
             LEFT JOIN gibbonCourse co ON (co.gibbonCourseID = cc.gibbonCourseID)
             LEFT JOIN gibbonSchoolYear sy ON (sy.gibbonSchoolYearID = co.gibbonSchoolYearID)
             ORDER BY sy.sequenceNumber, co.nameShort"
        )->fetchAll();

        return [
            'module' => 'Academic Records',
            'format' => 3,
            'exportedAt' => date('c'),
            'settings' => array_map(static function (array $row): array {
                return [
                    'scope' => (string) ($row['scope'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'nameDisplay' => (string) ($row['nameDisplay'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'value' => (string) ($row['value'] ?? ''),
                ];
            }, $settings),
            'cat4Mappings' => array_map(static function (array $row): array {
                return [
                    'academicRecordsCAT4MappingID' => (int) ($row['academicRecordsCAT4MappingID'] ?? 0),
                    'gibbonExternalAssessmentID' => (int) ($row['gibbonExternalAssessmentID'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'studentMatchField' => (string) ($row['studentMatchField'] ?? 'studentID'),
                    'studentIdentifierHeaderPattern' => (string) ($row['studentIdentifierHeaderPattern'] ?? 'Student ID'),
                    'dateHeaderPattern' => (string) ($row['dateHeaderPattern'] ?? 'Date of test'),
                    'importCategories' => (string) ($row['importCategories'] ?? '[]'),
                    'active' => (string) ($row['active'] ?? 'Y'),
                    'isDefault' => (string) ($row['isDefault'] ?? 'N'),
                ];
            }, $mappings),
            'cat4MappingFields' => array_map(static function (array $row): array {
                return [
                    'academicRecordsCAT4MappingID' => (int) ($row['academicRecordsCAT4MappingID'] ?? 0),
                    'gibbonExternalAssessmentFieldID' => (int) ($row['gibbonExternalAssessmentFieldID'] ?? 0),
                    'headerPattern' => (string) ($row['headerPattern'] ?? ''),
                    'active' => (string) ($row['active'] ?? 'Y'),
                ];
            }, $fields),
            'yearGroupMappings' => array_map(static function (array $row): array {
                return [
                    'gibbonYearGroupID' => (string) ($row['gibbonYearGroupID'] ?? ''),
                    'yearGroupName' => (string) ($row['yearGroupName'] ?? ''),
                    'yearGroupNameShort' => (string) ($row['yearGroupNameShort'] ?? ''),
                    'testwiseYear' => (string) ($row['testwiseYear'] ?? ''),
                ];
            }, $yearGroupMappings),
            'gradeSettings' => array_map(static function (array $row): array {
                return [
                    'gibbonScaleID' => (string) ($row['gibbonScaleID'] ?? ''),
                    'scaleName' => (string) ($row['scaleName'] ?? ''),
                    'scaleNameShort' => (string) ($row['scaleNameShort'] ?? ''),
                    'value' => (string) ($row['value'] ?? ''),
                    'creditFactor' => $row['creditFactor'],
                    'percentMin' => $row['percentMin'],
                    'percentMax' => $row['percentMax'],
                    'gpaPoints' => $row['gpaPoints'],
                    'gpaLetter' => $row['gpaLetter'],
                ];
            }, $gradeSettings),
            'courseCredits' => array_map(static function (array $row): array {
                return [
                    'gibbonCourseID' => (string) ($row['gibbonCourseID'] ?? ''),
                    'schoolYearName' => (string) ($row['schoolYearName'] ?? ''),
                    'courseNameShort' => (string) ($row['courseNameShort'] ?? ''),
                    'courseName' => (string) ($row['courseName'] ?? ''),
                    'creditPerTerm' => $row['creditPerTerm'],
                    'showOnTranscript' => (string) ($row['showOnTranscript'] ?? 'Y'),
                ];
            }, $courseCredits),
        ];
    }

    /**
     * Check an uploaded file and keep only the usable rows.
     *
     * Restore whatever the file happens to hold. A block that is absent or
     * empty is left alone on restore, and an unusable row inside a block is
     * dropped, so a school that never set up CAT4 or Testwise year groups
     * can still move its settings between installs.
     *
     * @param mixed $payload Decoded JSON.
     *
     * @return array|null Null when the file is not a backup of this module.
     */
    public static function validate($payload): ?array
    {
        if (!is_array($payload) || ($payload['module'] ?? '') !== 'Academic Records') {
            return null;
        }

        $blocks = [
            'settings' => static function ($setting): bool {
                return is_array($setting)
                    && ($setting['scope'] ?? '') === 'Academic Records'
                    && !empty($setting['name']);
            },
            'cat4Mappings' => static function ($mapping): bool {
                return is_array($mapping)
                    && !empty($mapping['gibbonExternalAssessmentID'])
                    && !empty($mapping['name']);
            },
            'cat4MappingFields' => static function ($field): bool {
                return is_array($field)
                    && !empty($field['academicRecordsCAT4MappingID'])
                    && !empty($field['gibbonExternalAssessmentFieldID']);
            },
            'yearGroupMappings' => static function ($yearGroup): bool {
                if (!is_array($yearGroup)) {
                    return false;
                }

                $hasIdentity = !empty($yearGroup['yearGroupName'])
                    || !empty($yearGroup['yearGroupNameShort'])
                    || !empty($yearGroup['gibbonYearGroupID']);

                return $hasIdentity && Year::isValidYear((string) ($yearGroup['testwiseYear'] ?? ''));
            },
            'gradeSettings' => static function ($setting): bool {
                return is_array($setting)
                    && (!empty($setting['scaleName']) || !empty($setting['scaleNameShort']) || !empty($setting['gibbonScaleID']))
                    && isset($setting['value']) && (string) $setting['value'] !== '';
            },
            'courseCredits' => static function ($credit): bool {
                return is_array($credit)
                    && ((!empty($credit['schoolYearName']) && !empty($credit['courseNameShort'])) || !empty($credit['gibbonCourseID']));
            },
        ];

        $backup = [];

        foreach ($blocks as $key => $isValid) {
            $rows = is_array($payload[$key] ?? null) ? $payload[$key] : [];
            $backup[$key] = array_values(array_filter($rows, $isValid));
        }

        return $backup;
    }

    /**
     * Write a validated backup, all or nothing.
     *
     * Only a table the backup actually carried rows for is replaced. A file
     * with no CAT4 or no year group data leaves those tables alone rather
     * than emptying them.
     *
     * @param array $backup  Result of validate().
     * @param int   $actorID Who is restoring.
     *
     * @return bool
     */
    public function restore(array $backup, int $actorID): bool
    {
        $this->db->beginTransaction();

        try {
            foreach ($backup['settings'] as $setting) {
                if (!$this->upsertSetting($setting)) {
                    throw new \RuntimeException('Failed to upsert module setting.');
                }
            }

            if (!empty($backup['cat4Mappings'])) {
                $this->restoreCat4Mappings($backup, $actorID);
            }

            if (!empty($backup['yearGroupMappings'])) {
                $this->restoreYearGroupMappings($backup);
            }

            if (!empty($backup['gradeSettings'])) {
                $this->restoreGradeSettings($backup);
            }

            if (!empty($backup['courseCredits'])) {
                $this->restoreCourseCredits($backup, $actorID);
            }

            $this->db->commit();

            return true;
        } catch (\Throwable $failure) {
            $this->db->rollBack();

            return false;
        }
    }

    /**
     * Run a write and stop the restore if it failed.
     *
     * Connection swallows a PDO exception, so a failed write would otherwise
     * pass unnoticed and the transaction would commit part of the file.
     * insert() returns false on failure and lastInsertId otherwise, for any
     * statement, which is the one signal the contract offers.
     *
     * @param string $sql  Statement to run.
     * @param array  $data Bindings.
     *
     * @return int|string lastInsertId for an INSERT with an auto id, else 0.
     */
    private function write(string $sql, array $data = [])
    {
        $result = $this->db->insert($sql, $data);

        if ($result === false) {
            throw new \RuntimeException('A restore statement failed.');
        }

        return $result;
    }

    private function upsertSetting(array $setting): bool
    {
        $sql = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value)
                VALUES (:scope, :name, :nameDisplay, :description, :value)
                ON DUPLICATE KEY UPDATE
                    nameDisplay = VALUES(nameDisplay),
                    description = VALUES(description),
                    value = VALUES(value)";

        $data = [
            'scope' => (string) ($setting['scope'] ?? 'Academic Records'),
            'name' => (string) ($setting['name'] ?? ''),
            'nameDisplay' => (string) ($setting['nameDisplay'] ?? ''),
            'description' => (string) ($setting['description'] ?? ''),
            'value' => (string) ($setting['value'] ?? ''),
        ];

        return $this->db->statement($sql, $data) !== false;
    }

    private function restoreCat4Mappings(array $backup, int $actorID): void
    {
        $this->write('DELETE FROM academicRecordsCAT4MappingField');
        $this->write('DELETE FROM academicRecordsCAT4Mapping');

        $sqlMapping = "INSERT INTO academicRecordsCAT4Mapping
                (gibbonExternalAssessmentID, name, studentMatchField, studentIdentifierHeaderPattern, dateHeaderPattern,
                 importCategories, active, isDefault, timestampCreated, timestampUpdated, gibbonPersonIDCreated, gibbonPersonIDUpdated)
            VALUES
                (:gibbonExternalAssessmentID, :name, :studentMatchField, :studentIdentifierHeaderPattern, :dateHeaderPattern,
                 :importCategories, :active, :isDefault, :timestampCreated, :timestampUpdated, :gibbonPersonIDCreated, :gibbonPersonIDUpdated)";

        $mappingIdMap = [];

        foreach ($backup['cat4Mappings'] as $mapping) {
            $legacyID = (int) ($mapping['academicRecordsCAT4MappingID'] ?? 0);
            $timestamp = date('Y-m-d H:i:s');

            $data = [
                'gibbonExternalAssessmentID' => (int) ($mapping['gibbonExternalAssessmentID'] ?? 0),
                'name' => (string) ($mapping['name'] ?? ''),
                'studentMatchField' => (string) ($mapping['studentMatchField'] ?? 'studentID'),
                'studentIdentifierHeaderPattern' => (string) ($mapping['studentIdentifierHeaderPattern'] ?? 'Student ID'),
                'dateHeaderPattern' => (string) ($mapping['dateHeaderPattern'] ?? 'Date of test'),
                'importCategories' => (string) ($mapping['importCategories'] ?? '[]'),
                'active' => (($mapping['active'] ?? 'Y') === 'N') ? 'N' : 'Y',
                'isDefault' => (($mapping['isDefault'] ?? 'N') === 'Y') ? 'Y' : 'N',
                'timestampCreated' => $timestamp,
                'timestampUpdated' => $timestamp,
                'gibbonPersonIDCreated' => $actorID > 0 ? $actorID : null,
                'gibbonPersonIDUpdated' => $actorID > 0 ? $actorID : null,
            ];

            $mappingIdMap[$legacyID] = (int) $this->write($sqlMapping, $data);
        }

        $sqlField = "INSERT INTO academicRecordsCAT4MappingField
                (academicRecordsCAT4MappingID, gibbonExternalAssessmentFieldID, headerPattern, active)
            VALUES
                (:academicRecordsCAT4MappingID, :gibbonExternalAssessmentFieldID, :headerPattern, :active)";

        foreach ($backup['cat4MappingFields'] as $field) {
            $legacyMappingID = (int) ($field['academicRecordsCAT4MappingID'] ?? 0);
            $newMappingID = $mappingIdMap[$legacyMappingID] ?? 0;

            if ($newMappingID <= 0) {
                continue;
            }

            $data = [
                'academicRecordsCAT4MappingID' => $newMappingID,
                'gibbonExternalAssessmentFieldID' => (int) ($field['gibbonExternalAssessmentFieldID'] ?? 0),
                'headerPattern' => (string) ($field['headerPattern'] ?? ''),
                'active' => (($field['active'] ?? 'Y') === 'N') ? 'N' : 'Y',
            ];

            $this->write($sqlField, $data);
        }
    }

    /**
     * A decimal from the file, or null where nothing was set.
     *
     * @param mixed $value Value from the file.
     *
     * @return string|null
     */
    private static function decimalOrNull($value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (string) (float) $value;
    }

    private function restoreGradeSettings(array $backup): void
    {
        $this->write('DELETE FROM academicRecordsGradeSetting');

        // Scales are matched on name, then short name, then ID, as year
        // groups are. A scale that does not exist here is skipped.
        $byName = [];
        $byNameShort = [];
        $byID = [];

        foreach ($this->db->select('SELECT gibbonScaleID, name, nameShort FROM gibbonScale')->fetchAll() as $row) {
            $rowID = (string) $row['gibbonScaleID'];
            $byID[$rowID] = $rowID;
            $byName[strtolower(trim((string) $row['name']))] = $rowID;
            $byNameShort[strtolower(trim((string) $row['nameShort']))] = $rowID;
        }

        $sql = "INSERT INTO academicRecordsGradeSetting
                    (gibbonScaleID, value, creditFactor, percentMin, percentMax, gpaPoints, gpaLetter)
                VALUES (:scaleID, :value, :creditFactor, :percentMin, :percentMax, :gpaPoints, :gpaLetter)
                ON DUPLICATE KEY UPDATE
                    creditFactor = VALUES(creditFactor),
                    percentMin = VALUES(percentMin),
                    percentMax = VALUES(percentMax),
                    gpaPoints = VALUES(gpaPoints),
                    gpaLetter = VALUES(gpaLetter)";

        foreach ($backup['gradeSettings'] as $setting) {
            $targetID = $byName[strtolower(trim((string) ($setting['scaleName'] ?? '')))]
                ?? $byNameShort[strtolower(trim((string) ($setting['scaleNameShort'] ?? '')))]
                ?? $byID[(string) ($setting['gibbonScaleID'] ?? '')]
                ?? null;

            if ($targetID === null) {
                continue;
            }

            $letter = trim((string) ($setting['gpaLetter'] ?? ''));

            $this->write($sql, [
                'scaleID' => $targetID,
                'value' => (string) $setting['value'],
                'creditFactor' => self::decimalOrNull($setting['creditFactor'] ?? null),
                'percentMin' => self::decimalOrNull($setting['percentMin'] ?? null),
                'percentMax' => self::decimalOrNull($setting['percentMax'] ?? null),
                'gpaPoints' => self::decimalOrNull($setting['gpaPoints'] ?? null),
                'gpaLetter' => $letter !== '' ? mb_substr($letter, 0, 4) : null,
            ]);
        }
    }

    private function restoreCourseCredits(array $backup, int $actorID): void
    {
        $this->write('DELETE FROM academicRecordsCourseCredit');

        // A course is matched on its school year name and short name, which
        // together are unique in Gibbon, then on ID. A course that does not
        // exist here is skipped.
        $byKey = [];
        $byID = [];

        $courses = $this->db->select(
            "SELECT co.gibbonCourseID, co.nameShort, sy.name AS schoolYearName
             FROM gibbonCourse co
             JOIN gibbonSchoolYear sy ON (sy.gibbonSchoolYearID = co.gibbonSchoolYearID)"
        )->fetchAll();

        foreach ($courses as $row) {
            $rowID = (string) $row['gibbonCourseID'];
            $byID[$rowID] = $rowID;
            $byKey[strtolower(trim((string) $row['schoolYearName'])) . '|' . strtolower(trim((string) $row['nameShort']))] = $rowID;
        }

        $sql = "INSERT INTO academicRecordsCourseCredit
                    (gibbonCourseID, creditPerTerm, showOnTranscript, timestampUpdated, gibbonPersonIDUpdated)
                VALUES (:courseID, :creditPerTerm, :showOnTranscript, NOW(), :actorID)
                ON DUPLICATE KEY UPDATE
                    creditPerTerm = VALUES(creditPerTerm),
                    showOnTranscript = VALUES(showOnTranscript),
                    timestampUpdated = NOW(),
                    gibbonPersonIDUpdated = VALUES(gibbonPersonIDUpdated)";

        foreach ($backup['courseCredits'] as $credit) {
            $key = strtolower(trim((string) ($credit['schoolYearName'] ?? ''))) . '|' . strtolower(trim((string) ($credit['courseNameShort'] ?? '')));

            $targetID = $byKey[$key]
                ?? $byID[(string) ($credit['gibbonCourseID'] ?? '')]
                ?? null;

            if ($targetID === null) {
                continue;
            }

            $this->write($sql, [
                'courseID' => $targetID,
                'creditPerTerm' => self::decimalOrNull($credit['creditPerTerm'] ?? null),
                'showOnTranscript' => (($credit['showOnTranscript'] ?? 'Y') === 'N') ? 'N' : 'Y',
                'actorID' => $actorID > 0 ? $actorID : null,
            ]);
        }
    }

    private function restoreYearGroupMappings(array $backup): void
    {
        $this->write('DELETE FROM academicRecordsYearGroupMap');

        // Match on the year group's own names first, so a backup restores
        // onto another install where the IDs differ. A year group that no
        // longer exists is skipped rather than failing the whole restore.
        $byName = [];
        $byNameShort = [];
        $byID = [];

        foreach ($this->db->select('SELECT gibbonYearGroupID, name, nameShort FROM gibbonYearGroup')->fetchAll() as $row) {
            $rowID = (string) $row['gibbonYearGroupID'];
            $byID[$rowID] = $rowID;
            $byName[strtolower(trim((string) $row['name']))] = $rowID;
            $byNameShort[strtolower(trim((string) $row['nameShort']))] = $rowID;
        }

        $sql = "INSERT INTO academicRecordsYearGroupMap (gibbonYearGroupID, testwiseYear)
                VALUES (:gibbonYearGroupID, :testwiseYear)
                ON DUPLICATE KEY UPDATE testwiseYear = VALUES(testwiseYear)";

        foreach ($backup['yearGroupMappings'] as $yearGroup) {
            $name = strtolower(trim((string) ($yearGroup['yearGroupName'] ?? '')));
            $nameShort = strtolower(trim((string) ($yearGroup['yearGroupNameShort'] ?? '')));
            $legacyID = (string) ($yearGroup['gibbonYearGroupID'] ?? '');

            $targetID = $byName[$name]
                ?? $byNameShort[$nameShort]
                ?? $byID[$legacyID]
                ?? null;

            if ($targetID === null) {
                continue;
            }

            $this->write($sql, [
                'gibbonYearGroupID' => $targetID,
                'testwiseYear' => (string) ($yearGroup['testwiseYear'] ?? ''),
            ]);
        }
    }
}
