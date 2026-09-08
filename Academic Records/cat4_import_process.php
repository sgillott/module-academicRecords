<?php

use Gibbon\Module\AcademicRecords\Domain\CAT4ImportGateway;
use Gibbon\Module\AcademicRecords\Domain\CAT4MappingGateway;

function runCAT4Import($pdo, $container, array $rows, bool $liveRun): array
{
    /** @var CAT4MappingGateway $mappingGateway */
    $mappingGateway = $container->get(CAT4MappingGateway::class);
    /** @var CAT4ImportGateway $importGateway */
    $importGateway = $container->get(CAT4ImportGateway::class);

    $defaultMappings = $mappingGateway->selectDefaultMappings();

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

    if ($liveRun) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($defaultMappings as $mapping) {
            $mappingID = (int) ($mapping['academicRecordsCAT4MappingID'] ?? 0);
            $assessmentID = (int) ($mapping['gibbonExternalAssessmentID'] ?? 0);
            $assessmentName = trim((string) ($mapping['assessmentName'] ?? $mapping['name'] ?? __('External Assessment')));

            if ($mappingID <= 0 || $assessmentID <= 0) {
                continue;
            }

            $mappingFields = $mappingGateway->selectMappingFields($mappingID);
            $assessmentFields = $mappingGateway->selectAssessmentFields($assessmentID);
            $availableHeaders = array_keys($rows[0] ?? []);

            $mappingFields = supplementCAT4ScoreMappingFields(
                $mappingFields,
                $assessmentFields,
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

            $studentHeader = resolveMappedHeader($studentHeaderPattern, $availableHeaders);
            $dateHeader = resolveMappedHeader($dateHeaderPattern, $availableHeaders);

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

                $resolvedHeader = resolveMappedHeader($headerPattern, $availableHeaders);
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

                $date = normalizeImportDate($dateValue);
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

                $person = $importGateway->findStudentByField($studentMatchField, $studentValue);
                if (empty($person['gibbonPersonID'])) {
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
                $personID = (int) $person['gibbonPersonID'];

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

                    $candidateValues = normalizeImportedScaleValues($value);
                    $gradeID = null;
                    $matchedValue = null;

                    foreach ($candidateValues as $candidateValue) {
                        $gradeID = $importGateway->findScaleGradeID($field['scaleID'], $candidateValue);

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

                $studentAssessmentID = $importGateway->findExternalAssessmentStudentID($assessmentID, $personID, $date);
                $isNewStudentAssessment = false;

                if ($studentAssessmentID === null) {
                    $isNewStudentAssessment = true;

                    if ($liveRun) {
                        $studentAssessmentID = $importGateway->insertExternalAssessmentStudent($assessmentID, $personID, $date);

                        if ($studentAssessmentID === null) {
                            throw new \RuntimeException(
                                __('Failed to create External Assessment row for assessment "{assessment}" and student "{student}". {error}', [
                                    'assessment' => $assessmentName,
                                    'student' => $studentValue,
                                    'error' => (string) ($importGateway->getLastErrorMessage() ?? ''),
                                ])
                            );
                        }
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

                    $existingEntryID = $importGateway->findEntryID($studentAssessmentID, $plannedEntry['fieldID']);

                    if ($existingEntryID === null) {
                        if ($liveRun) {
                            $insertedEntryID = $importGateway->insertEntry($studentAssessmentID, $plannedEntry['fieldID'], $plannedEntry['gradeID']);

                            if ($insertedEntryID === null) {
                                throw new \RuntimeException(
                                    __('Failed to create External Assessment entry for field "{field}". {error}', [
                                        'field' => $plannedEntry['fieldName'],
                                        'error' => (string) ($importGateway->getLastErrorMessage() ?? ''),
                                    ])
                                );
                            }
                        }

                        $summary['entriesInserted']++;

                        continue;
                    }

                    $existingGradeID = $importGateway->findEntryGradeID($existingEntryID);
                    if ($existingGradeID === $plannedEntry['gradeID']) {
                        $summary['entriesNoChange']++;
                        continue;
                    }

                    $summary['entriesUpdated']++;

                    if ($liveRun && !$importGateway->updateEntry($existingEntryID, $plannedEntry['gradeID'])) {
                        throw new \RuntimeException(
                            __('Failed to update External Assessment entry for field "{field}". {error}', [
                                'field' => $plannedEntry['fieldName'],
                                'error' => (string) ($importGateway->getLastErrorMessage() ?? ''),
                            ])
                        );
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
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($liveRun) {
            try {
                $pdo->rollBack();
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

function resolveMappedHeader(string $pattern, array $headers): ?string
{
    $needle = normalizeImportHeader($pattern);

    foreach ($headers as $header) {
        if (normalizeImportHeader((string) $header) === $needle) {
            return (string) $header;
        }
    }

    foreach ($headers as $header) {
        if ($needle !== '' && str_contains(normalizeImportHeader((string) $header), $needle)) {
            return (string) $header;
        }
    }

    return null;
}

function supplementCAT4ScoreMappingFields(array $mappingFields, array $assessmentFields, array $availableHeaders, string $assessmentName): array
{
    if (!isCAT4AssessmentName($assessmentName)) {
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

        $fieldName = normalizeCAT4ScoreFieldName((string) ($assessmentField['name'] ?? ''));
        if (!isset($scoreHeaderMap[$fieldName])) {
            continue;
        }

        $headerPattern = $scoreHeaderMap[$fieldName];
        if (resolveMappedHeader($headerPattern, $availableHeaders) === null) {
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

function isCAT4AssessmentName(string $assessmentName): bool
{
    $assessmentName = strtolower(trim($assessmentName));

    return str_contains($assessmentName, 'cat4')
        || str_contains($assessmentName, 'cognitive abilities');
}

function normalizeCAT4ScoreFieldName(string $fieldName): string
{
    $fieldName = strtolower(trim($fieldName));
    $fieldName = str_replace('_', ' ', $fieldName);
    $fieldName = preg_replace('/\s+/', ' ', $fieldName);

    return trim((string) $fieldName);
}

function normalizeImportHeader(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value);

    return (string) $value;
}

function normalizeImportedScaleValues(string $value): array
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

function normalizeImportDate(string $value): ?string
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
