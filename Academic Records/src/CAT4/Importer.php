<?php
/**
 * Writes a GL Assessment spreadsheet into External Assessment records.
 *
 * Every active default mapping is tried against the file. A mapping whose
 * identifier and date columns are both present is run over every row: the
 * student is matched, each mapped column is turned into a scale grade, and
 * the entries are inserted or updated under one student assessment row per
 * student and date.
 *
 * The dry run and the live run share this one method, so what the dry run
 * reports is exactly what the live run writes.
 *
 * Reads are done up front and served from memory: the students named in the
 * file, the grades of every mapped scale, the assessment rows on the dates
 * in the file, and their entries. A value the prefetch did not see, such as
 * an identifier that differs only in case, falls back to a single row read,
 * so the outcome is the same as reading row by row.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\CAT4;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Module\AcademicRecords\Domain\CAT4ImportGateway;
use Gibbon\Module\AcademicRecords\Domain\CAT4MappingGateway;

class Importer
{
    /**
     * @var Connection
     */
    private $db;

    /**
     * @var CAT4MappingGateway
     */
    private $mappingGateway;

    /**
     * @var CAT4ImportGateway
     */
    private $importGateway;

    /**
     * Person IDs keyed by the identifier the current mapping matches on.
     *
     * @var array
     */
    private $personIDs = [];

    /**
     * Scale grade IDs keyed by scale then value.
     *
     * @var array
     */
    private $scaleGrades = [];

    /**
     * Student assessment row IDs keyed by person then date.
     *
     * @var array
     */
    private $studentAssessments = [];

    /**
     * Entries keyed by student assessment row then field.
     *
     * @var array
     */
    private $entries = [];

    /**
     * @param Connection         $db             Shared database connection.
     * @param CAT4MappingGateway $mappingGateway Saved mappings.
     * @param CAT4ImportGateway  $importGateway  External Assessment rows.
     */
    public function __construct(Connection $db, CAT4MappingGateway $mappingGateway, CAT4ImportGateway $importGateway)
    {
        $this->db = $db;
        $this->mappingGateway = $mappingGateway;
        $this->importGateway = $importGateway;
    }

    /**
     * Run the import.
     *
     * @param array $rows    Spreadsheet rows, each keyed by column heading.
     * @param bool  $liveRun True writes inside one transaction.
     *
     * @return array success, canLiveRun, message, warnings, summary, rows
     *               and, once a mapping was usable, mappingContext.
     */
    public function run(array $rows, bool $liveRun): array
    {
        $defaultMappings = $this->mappingGateway->selectDefaultMappings();

        if (empty($defaultMappings)) {
            return [
                'success' => false,
                'canLiveRun' => false,
                'message' => __('No default CAT4 import mappings are configured.'),
                'warnings' => [],
                'summary' => [],
                'rows' => [],
            ];
        }

        $summary = [
            'rowsReceived' => count($rows),
            'rowsProcessed' => 0,
            'rowsSkipped' => 0,
            'studentsMatched' => 0,
            'assessmentRowsInserted' => 0,
            'assessmentRowsReused' => 0,
            'entriesInserted' => 0,
            'entriesUpdated' => 0,
            'entriesNoChange' => 0,
            'fieldValuesSkipped' => 0,
        ];

        $warnings = [];
        $rowResults = [];
        $mappingContexts = [];
        $usableMappings = 0;
        $availableHeaders = array_keys($rows[0] ?? []);

        if ($liveRun) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($defaultMappings as $mapping) {
                $mappingID = (int) ($mapping['academicRecordsCAT4MappingID'] ?? 0);
                $assessmentID = (int) ($mapping['gibbonExternalAssessmentID'] ?? 0);
                $assessmentName = trim((string) ($mapping['assessmentName'] ?? $mapping['name'] ?? __('External Assessment')));

                if ($mappingID <= 0 || $assessmentID <= 0) {
                    continue;
                }

                $mappingFields = $this->supplementScoreMappingFields(
                    $this->mappingGateway->selectMappingFields($mappingID),
                    $this->mappingGateway->selectAssessmentFields($assessmentID),
                    $availableHeaders,
                    $assessmentName
                );

                if (empty($mappingFields)) {
                    $warnings[] = __('Mapping "{mapping}" has no saved field mappings.', ['mapping' => $assessmentName]);
                    continue;
                }

                $studentMatchField = (string) ($mapping['studentMatchField'] ?? 'studentID');
                $studentHeaderPattern = trim((string) ($mapping['studentIdentifierHeaderPattern'] ?? 'Student ID'));
                $dateHeaderPattern = trim((string) ($mapping['dateHeaderPattern'] ?? 'Date of test'));

                $studentHeader = $this->resolveMappedHeader($studentHeaderPattern, $availableHeaders);
                $dateHeader = $this->resolveMappedHeader($dateHeaderPattern, $availableHeaders);

                if ($studentHeader === null || $dateHeader === null) {
                    $missingParts = [];
                    if ($studentHeader === null) {
                        $missingParts[] = __('student identifier header');
                    }
                    if ($dateHeader === null) {
                        $missingParts[] = __('date header');
                    }

                    $warnings[] = __('Mapping "{mapping}" was skipped because the uploaded file does not contain the configured {items}.', [
                        'mapping' => $assessmentName,
                        'items' => implode(', ', $missingParts),
                    ]);
                    continue;
                }

                $activeFields = [];
                $mappedFieldCount = 0;

                foreach ($mappingFields as $field) {
                    if (($field['active'] ?? 'Y') !== 'Y') {
                        continue;
                    }

                    $headerPattern = trim((string) ($field['headerPattern'] ?? ''));
                    if ($headerPattern === '') {
                        continue;
                    }

                    $resolvedHeader = $this->resolveMappedHeader($headerPattern, $availableHeaders);
                    $activeFields[] = [
                        'fieldID' => (int) ($field['gibbonExternalAssessmentFieldID'] ?? 0),
                        'fieldName' => (string) ($field['name'] ?? ''),
                        'headerPattern' => $headerPattern,
                        'resolvedHeader' => $resolvedHeader,
                        'scaleID' => (int) ($field['gibbonScaleID'] ?? 0),
                    ];

                    if ($resolvedHeader !== null) {
                        $mappedFieldCount++;
                    } else {
                        $warnings[] = __('Mapped field "{field}" for "{mapping}" could not find spreadsheet column "{header}" in the uploaded file.', [
                            'field' => (string) ($field['name'] ?? ''),
                            'mapping' => $assessmentName,
                            'header' => $headerPattern,
                        ]);
                    }
                }

                if ($mappedFieldCount === 0) {
                    $warnings[] = __('Mapping "{mapping}" was skipped because none of its active mapped columns exist in the uploaded file.', [
                        'mapping' => $assessmentName,
                    ]);
                    continue;
                }

                $usableMappings++;
                $mappingContexts[] = [
                    'mappingID' => $mappingID,
                    'assessmentID' => $assessmentID,
                    'assessment' => $assessmentName,
                    'studentMatchField' => $studentMatchField,
                    'studentHeader' => $studentHeader,
                    'dateHeader' => $dateHeader,
                    'mappedFieldCount' => $mappedFieldCount,
                ];

                $this->prefetch($rows, $studentMatchField, $studentHeader, $dateHeader, $assessmentID, $activeFields);

                foreach ($rows as $index => $row) {
                    $rowNumber = $index + 2;
                    $studentValue = trim((string) ($row[$studentHeader] ?? ''));
                    $dateValue = trim((string) ($row[$dateHeader] ?? ''));
                    $rowWarnings = [];
                    $rowStatus = __('Validated');
                    $plannedEntries = [];

                    if ($studentValue === '' || $dateValue === '') {
                        $summary['rowsSkipped']++;
                        $rowResults[] = [
                            'assessment' => $assessmentName,
                            'rowNumber' => $rowNumber,
                            'student' => $studentValue,
                            'date' => $dateValue,
                            'status' => __('Skipped'),
                            'details' => __('Missing student identifier or date.'),
                        ];
                        continue;
                    }

                    $date = $this->normalizeImportDate($dateValue);
                    if ($date === null) {
                        $summary['rowsSkipped']++;
                        $rowResults[] = [
                            'assessment' => $assessmentName,
                            'rowNumber' => $rowNumber,
                            'student' => $studentValue,
                            'date' => $dateValue,
                            'status' => __('Skipped'),
                            'details' => __('Date could not be parsed.'),
                        ];
                        continue;
                    }

                    $personID = $this->findPersonID($studentMatchField, $studentValue);
                    if ($personID === null) {
                        $summary['rowsSkipped']++;
                        $rowResults[] = [
                            'assessment' => $assessmentName,
                            'rowNumber' => $rowNumber,
                            'student' => $studentValue,
                            'date' => $date,
                            'status' => __('Student Not Found'),
                            'details' => __('No student matched the configured identifier field.'),
                        ];
                        continue;
                    }

                    $summary['rowsProcessed']++;
                    $summary['studentsMatched']++;

                    foreach ($activeFields as $field) {
                        if ($field['resolvedHeader'] === null) {
                            $summary['fieldValuesSkipped']++;
                            continue;
                        }

                        $value = trim((string) ($row[$field['resolvedHeader']] ?? ''));
                        if ($value === '') {
                            $summary['fieldValuesSkipped']++;
                            continue;
                        }

                        if ($field['scaleID'] <= 0) {
                            $summary['fieldValuesSkipped']++;
                            $rowWarnings[] = __('Field "{field}" has no scale configured.', [
                                'field' => $field['fieldName'],
                            ]);
                            continue;
                        }

                        $gradeID = null;
                        $matchedValue = null;

                        foreach ($this->normalizeImportedScaleValues($value) as $candidateValue) {
                            $gradeID = $this->findScaleGradeID($field['scaleID'], $candidateValue);

                            if ($gradeID !== null) {
                                $matchedValue = $candidateValue;
                                break;
                            }
                        }

                        if ($gradeID === null) {
                            $summary['fieldValuesSkipped']++;
                            $rowWarnings[] = __('Field "{field}" value "{value}" was not found in its Gibbon scale.', [
                                'field' => $field['fieldName'],
                                'value' => $value,
                            ]);
                            continue;
                        }

                        if ($matchedValue !== null && $matchedValue !== $value) {
                            $rowWarnings[] = __('Field "{field}" value "{value}" was treated as a split grade and imported as "{normalized}".', [
                                'field' => $field['fieldName'],
                                'value' => $value,
                                'normalized' => $matchedValue,
                            ]);
                        }

                        $plannedEntries[] = [
                            'fieldID' => (int) $field['fieldID'],
                            'fieldName' => (string) $field['fieldName'],
                            'gradeID' => (int) $gradeID,
                        ];
                    }

                    if (empty($plannedEntries)) {
                        $summary['rowsSkipped']++;
                        $rowResults[] = [
                            'assessment' => $assessmentName,
                            'rowNumber' => $rowNumber,
                            'student' => $studentValue,
                            'date' => $date,
                            'status' => __('Skipped'),
                            'details' => empty($rowWarnings)
                                ? __('No valid mapped values were available to write for this row.')
                                : implode(' ', array_unique($rowWarnings)),
                        ];
                        continue;
                    }

                    $studentAssessmentID = $this->studentAssessments[$personID][$date] ?? null;
                    $isNewStudentAssessment = false;

                    if ($studentAssessmentID === null) {
                        $isNewStudentAssessment = true;

                        if ($liveRun) {
                            $studentAssessmentID = $this->importGateway->insertExternalAssessmentStudent($assessmentID, $personID, $date);

                            if ($studentAssessmentID === null) {
                                throw new \RuntimeException(
                                    __('Failed to create External Assessment row for assessment "{assessment}" and student "{student}". {error}', [
                                        'assessment' => $assessmentName,
                                        'student' => $studentValue,
                                        'error' => (string) ($this->importGateway->getLastErrorMessage() ?? ''),
                                    ])
                                );
                            }

                            // A later row for the same student and date now
                            // finds this one, as a fresh read would.
                            $this->studentAssessments[$personID][$date] = $studentAssessmentID;
                        }

                        $summary['assessmentRowsInserted']++;
                        $rowStatus = $liveRun ? __('Upserted') : __('Validated');
                    } else {
                        $summary['assessmentRowsReused']++;
                        $rowStatus = $liveRun ? __('Updated') : __('Validated');
                    }

                    foreach ($plannedEntries as $plannedEntry) {
                        if ($studentAssessmentID === null) {
                            if (!$liveRun && $isNewStudentAssessment) {
                                $summary['entriesInserted']++;
                                continue;
                            }

                            throw new \RuntimeException(
                                __('No External Assessment student row was available for assessment "{assessment}" and student "{student}".', [
                                    'assessment' => $assessmentName,
                                    'student' => $studentValue,
                                ])
                            );
                        }

                        $fieldID = $plannedEntry['fieldID'];
                        $existing = $this->entries[$studentAssessmentID][$fieldID] ?? null;

                        if ($existing === null) {
                            if ($liveRun) {
                                $insertedEntryID = $this->importGateway->insertEntry($studentAssessmentID, $fieldID, $plannedEntry['gradeID']);

                                if ($insertedEntryID === null) {
                                    throw new \RuntimeException(
                                        __('Failed to create External Assessment entry for field "{field}". {error}', [
                                            'field' => $plannedEntry['fieldName'],
                                            'error' => (string) ($this->importGateway->getLastErrorMessage() ?? ''),
                                        ])
                                    );
                                }

                                $this->entries[$studentAssessmentID][$fieldID] = [
                                    'entryID' => $insertedEntryID,
                                    'gradeID' => $plannedEntry['gradeID'],
                                ];
                            }

                            $summary['entriesInserted']++;

                            continue;
                        }

                        if ($existing['gradeID'] === $plannedEntry['gradeID']) {
                            $summary['entriesNoChange']++;
                            continue;
                        }

                        $summary['entriesUpdated']++;

                        if ($liveRun) {
                            if (!$this->importGateway->updateEntry($existing['entryID'], $plannedEntry['gradeID'])) {
                                throw new \RuntimeException(
                                    __('Failed to update External Assessment entry for field "{field}". {error}', [
                                        'field' => $plannedEntry['fieldName'],
                                        'error' => (string) ($this->importGateway->getLastErrorMessage() ?? ''),
                                    ])
                                );
                            }

                            $this->entries[$studentAssessmentID][$fieldID]['gradeID'] = $plannedEntry['gradeID'];
                        }
                    }

                    $rowResults[] = [
                        'assessment' => $assessmentName,
                        'rowNumber' => $rowNumber,
                        'student' => $studentValue,
                        'date' => $date,
                        'status' => $rowStatus,
                        'details' => empty($rowWarnings)
                            ? __('Student matched and valid mapped fields were processed.')
                            : implode(' ', array_unique($rowWarnings)),
                    ];
                }
            }

            if ($liveRun) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($liveRun) {
                try {
                    $this->db->rollBack();
                } catch (\Throwable $rollbackError) {
                    // Ignore rollback failures.
                }
            }

            return [
                'success' => false,
                'canLiveRun' => false,
                'message' => __('CAT4 import failed: {message}', ['message' => $e->getMessage()]),
                'warnings' => $warnings,
                'summary' => $summary,
                'rows' => $rowResults,
            ];
        }

        if ($usableMappings === 0) {
            return [
                'success' => false,
                'canLiveRun' => false,
                'message' => __('The uploaded file did not match any saved default CAT4 import mappings.'),
                'warnings' => array_values(array_unique($warnings)),
                'summary' => $summary,
                'rows' => $rowResults,
                'mappingContext' => $mappingContexts,
            ];
        }

        $success = ($summary['rowsProcessed'] > 0);
        $message = $liveRun
            ? __('CAT4 live import completed.')
            : __('CAT4 dry run completed. No data was written.');

        if (!$success) {
            $message = __('No valid mapped rows could be processed from this file.');
        }

        return [
            'success' => $success,
            'canLiveRun' => !$liveRun && $success,
            'message' => $message,
            'warnings' => array_values(array_unique($warnings)),
            'summary' => $summary,
            'rows' => $rowResults,
            'mappingContext' => $mappingContexts,
        ];
    }

    /* ---------------------------------------------------------
       Lookups
    --------------------------------------------------------- */

    /**
     * Load everything one mapping's pass over the file will look up.
     *
     * @param array  $rows              The file.
     * @param string $studentMatchField Field the identifier matches on.
     * @param string $studentHeader     Column holding the identifier.
     * @param string $dateHeader        Column holding the test date.
     * @param int    $assessmentID      gibbonExternalAssessmentID.
     * @param array  $activeFields      Fields this mapping writes.
     *
     * @return void
     */
    private function prefetch(array $rows, string $studentMatchField, string $studentHeader, string $dateHeader, int $assessmentID, array $activeFields): void
    {
        $identifiers = [];
        $dates = [];

        foreach ($rows as $row) {
            $identifier = trim((string) ($row[$studentHeader] ?? ''));
            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }

            $date = $this->normalizeImportDate(trim((string) ($row[$dateHeader] ?? '')));
            if ($date !== null) {
                $dates[$date] = true;
            }
        }

        $this->personIDs = [];
        $numeric = $studentMatchField === 'gibbonPersonID';
        foreach ($this->importGateway->selectPersonIDsByFieldKeyed($studentMatchField, array_keys($identifiers)) as $value => $personID) {
            $this->personIDs[$this->lookupKey($value, $numeric)] = $personID;
        }

        $this->scaleGrades = [];
        foreach ($this->importGateway->selectScaleGradesKeyed(array_column($activeFields, 'scaleID')) as $scaleID => $grades) {
            foreach ($grades as $value => $gradeID) {
                $this->scaleGrades[$scaleID][$this->lookupKey($value)] = $gradeID;
            }
        }

        $this->studentAssessments = $this->importGateway->selectExternalAssessmentStudentsKeyed($assessmentID, array_keys($dates));

        $rowIDs = [];
        foreach ($this->studentAssessments as $byDate) {
            foreach ($byDate as $rowID) {
                $rowIDs[] = $rowID;
            }
        }

        $this->entries = $this->importGateway->selectEntriesKeyed($rowIDs);
    }

    /**
     * The person an identifier names, from the prefetch or a single read.
     *
     * @param string $field Field the identifier matches on.
     * @param string $value Identifier from the file, already trimmed.
     *
     * @return int|null
     */
    private function findPersonID(string $field, string $value): ?int
    {
        $key = $this->lookupKey($value, $field === 'gibbonPersonID');

        if (isset($this->personIDs[$key])) {
            return $this->personIDs[$key];
        }

        $person = $this->importGateway->findStudentByField($field, $value);
        $personID = !empty($person['gibbonPersonID']) ? (int) $person['gibbonPersonID'] : null;

        if ($personID !== null) {
            $this->personIDs[$key] = $personID;
        }

        return $personID;
    }

    /**
     * The grade ID of a value on a scale, from the prefetch or a single read.
     *
     * @param int    $scaleID The scale.
     * @param string $value   Candidate value.
     *
     * @return int|null
     */
    private function findScaleGradeID(int $scaleID, string $value): ?int
    {
        $key = $this->lookupKey($value);

        if (isset($this->scaleGrades[$scaleID][$key])) {
            return $this->scaleGrades[$scaleID][$key];
        }

        $gradeID = $this->importGateway->findScaleGradeID($scaleID, $value);

        if ($gradeID !== null) {
            $this->scaleGrades[$scaleID][$key] = $gradeID;
        }

        return $gradeID;
    }

    /**
     * Key a value the way the database compares it.
     *
     * Text columns compare case-insensitively under Gibbon's collation. The
     * one integer column, gibbonPersonID, compares numerically, so leading
     * zeros are dropped for it and only for it.
     *
     * @param mixed $value   Identifier or grade value.
     * @param bool  $numeric True for an integer column.
     *
     * @return string
     */
    private function lookupKey($value, bool $numeric = false): string
    {
        $value = trim((string) $value);

        if ($numeric && ctype_digit($value)) {
            return (string) (int) $value;
        }

        return mb_strtolower($value);
    }

    /* ---------------------------------------------------------
       Header matching and value cleaning
    --------------------------------------------------------- */

    private function resolveMappedHeader(string $pattern, array $headers): ?string
    {
        $needle = $this->normalizeImportHeader($pattern);

        foreach ($headers as $header) {
            if ($this->normalizeImportHeader((string) $header) === $needle) {
                return (string) $header;
            }
        }

        foreach ($headers as $header) {
            if ($needle !== '' && str_contains($this->normalizeImportHeader((string) $header), $needle)) {
                return (string) $header;
            }
        }

        return null;
    }

    /**
     * Add the CAT4 score columns to a mapping that has not mapped them.
     *
     * The SAS columns have fixed names in every Testwise export, so a CAT4
     * mapping gets them without the school having to map each one.
     */
    private function supplementScoreMappingFields(array $mappingFields, array $assessmentFields, array $availableHeaders, string $assessmentName): array
    {
        if (!$this->isCAT4AssessmentName($assessmentName)) {
            return $mappingFields;
        }

        $scoreHeaderMap = [
            'verbal' => 'Verbal SAS',
            'quantitative' => 'Quantitative SAS',
            'non-verbal' => 'Non-verbal SAS',
            'spatial' => 'Spatial SAS',
            'mean sas' => 'Mean SAS',
        ];

        $mappedFieldIDs = [];
        foreach ($mappingFields as $mappingField) {
            $fieldID = (int) ($mappingField['gibbonExternalAssessmentFieldID'] ?? 0);
            if ($fieldID > 0) {
                $mappedFieldIDs[$fieldID] = true;
            }
        }

        foreach ($assessmentFields as $assessmentField) {
            $fieldID = (int) ($assessmentField['gibbonExternalAssessmentFieldID'] ?? 0);
            if ($fieldID <= 0 || isset($mappedFieldIDs[$fieldID])) {
                continue;
            }

            $fieldName = $this->normalizeScoreFieldName((string) ($assessmentField['name'] ?? ''));
            if (!isset($scoreHeaderMap[$fieldName])) {
                continue;
            }

            $headerPattern = $scoreHeaderMap[$fieldName];
            if ($this->resolveMappedHeader($headerPattern, $availableHeaders) === null) {
                continue;
            }

            $mappingFields[] = [
                'gibbonExternalAssessmentFieldID' => $fieldID,
                'headerPattern' => $headerPattern,
                'active' => 'Y',
                'name' => (string) ($assessmentField['name'] ?? ''),
                'category' => (string) ($assessmentField['category'] ?? ''),
                'gibbonScaleID' => (int) ($assessmentField['gibbonScaleID'] ?? 0),
            ];
            $mappedFieldIDs[$fieldID] = true;
        }

        return $mappingFields;
    }

    private function isCAT4AssessmentName(string $assessmentName): bool
    {
        $assessmentName = strtolower(trim($assessmentName));

        return str_contains($assessmentName, 'cat4')
            || str_contains($assessmentName, 'cognitive abilities');
    }

    private function normalizeScoreFieldName(string $fieldName): string
    {
        $fieldName = strtolower(trim($fieldName));
        $fieldName = str_replace('_', ' ', $fieldName);
        $fieldName = preg_replace('/\s+/', ' ', $fieldName);

        return trim((string) $fieldName);
    }

    private function normalizeImportHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);

        return (string) $value;
    }

    /**
     * The values to try for a cell, so a split grade such as "A/B" is
     * imported as its first grade when the pair is not on the scale.
     */
    private function normalizeImportedScaleValues(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $candidateValues = [$value];

        if (preg_match('/[\\\\\/]/', $value) === 1) {
            $splitValues = preg_split('/[\\\\\/]/', $value) ?: [];
            $firstGrade = trim((string) ($splitValues[0] ?? ''));

            if ($firstGrade !== '' && !in_array($firstGrade, $candidateValues, true)) {
                $candidateValues[] = $firstGrade;
            }
        }

        return $candidateValues;
    }

    /**
     * A test date in Y-m-d, from the forms a spreadsheet export uses.
     */
    private function normalizeImportDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{5,}$/', $value) === 1) {
            $excelSerial = (int) $value;
            if ($excelSerial > 0) {
                $date = new \DateTimeImmutable('1899-12-30');
                return $date->modify('+' . $excelSerial . ' days')->format('Y-m-d');
            }
        }

        $formats = [
            '!Y-m-d',
            '!Y/m/d',
            '!Y.m.d',
            '!d/m/Y',
            '!d-m-Y',
            '!d.m.Y',
            '!d/m/y',
            '!d-m-y',
            '!m/d/Y',
            '!m-d-Y',
        ];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if ($date !== false
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }
}
