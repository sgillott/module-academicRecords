<?php

use Gibbon\Forms\Form;
use Gibbon\Module\AcademicRecords\Domain\CAT4MappingGateway;

class CAT4MappingRenderer
{
    public static function renderSection(
        Form $form,
        CAT4MappingGateway $gateway,
        CAT4MappingService $mappingService,
        int $assessmentID,
        string $assessmentName,
        string $title,
        string $namespace
    ): void {

        $form->addRow()->addHeading(__($title));

        $fields = $gateway->selectAssessmentFields($assessmentID);
        $mappingID = $gateway->getDefaultMappingID($assessmentID);
        $mapping = $mappingID ? $gateway->getMapping($mappingID) : [];
        $mapping = is_array($mapping) ? $mapping : [];

        $savedRows = $mappingID ? $gateway->selectMappingFields($mappingID) : [];
        $savedMappings = $mappingService->buildSavedMappingLookup($savedRows);

        $studentMatchField = (string) ($mapping['studentMatchField'] ?? 'studentID');
        $studentIdentifierHeaderPattern = (string) ($mapping['studentIdentifierHeaderPattern'] ?? 'Student ID');
        $dateHeaderPattern = (string) ($mapping['dateHeaderPattern'] ?? 'Date of test');

        $row = $form->addRow();
        $row->addLabel("studentMatchField[$namespace]", __('Student Match Field'))
            ->description(__('Choose which Gibbon student field should be matched against the imported identifier column.'));
        $row->addSelect("studentMatchField[$namespace]")
            ->fromArray([
                'studentID' => __('Student ID'),
                'gibbonPersonID' => __('Gibbon Person ID'),
                'username' => __('Username'),
            ])
            ->selected($studentMatchField)
            ->required();

        $row = $form->addRow();
        $row->addLabel("studentIdentifierHeaderPattern[$namespace]", __('Student Identifier Column Header'))
            ->description(__('Enter the spreadsheet column header that contains the student identifier, for example "Student ID".'));
        $row->addTextField("studentIdentifierHeaderPattern[$namespace]")
            ->setValue($studentIdentifierHeaderPattern)
            ->maxLength(120)
            ->required();

        $row = $form->addRow();
        $row->addLabel("dateHeaderPattern[$namespace]", __('Date Column Header'))
            ->description(__('Enter the spreadsheet column header that contains the assessment date, for example "Date of test".'));
        $row->addTextField("dateHeaderPattern[$namespace]")
            ->setValue($dateHeaderPattern)
            ->maxLength(120)
            ->required();

        $table = $form->addRow()->addTable()->setClass('colorOddEven w-full');

        $header = $table->addHeaderRow();
        $header->addContent(__('Category'));
        $header->addContent(__('Field'));
        $header->addContent(__('Spreadsheet Column'));
        $header->addContent(__('Import'));

        foreach ($fields as $field) {

            if ($mappingService->shouldSkipField($field)) {
                continue;
            }

            $fieldID = (int)$field['gibbonExternalAssessmentFieldID'];

            $headerType = $mappingService->guessHeaderTypeForField($field, $assessmentName);

            $headerOptions = $mappingService->buildHeaderOptionsForAssessment($headerType);

            $suggestedMatch = $mappingService->getSuggestedHeaderMatch(
                $field,
                $headerOptions,
                $savedMappings
            );
            $selectedHeader = $suggestedMatch['header'] ?? '';

            $selectedActive = $mappingService->getDefaultActiveForField(
                $field,
                $savedMappings
            );

            $confidenceLevel = (string) ($suggestedMatch['confidenceLevel'] ?? 'missing');
            $hasSavedMapping = !empty($savedMappings[$fieldID]['headerPattern']);
            $rowClasses = ['cat4-mapping-row'];

            if ($hasSavedMapping) {
                $rowClasses[] = 'cat4-mapping-row-saved';
            } elseif ($selectedHeader === '') {
                $rowClasses[] = 'cat4-mapping-row-missing';
            } elseif (in_array($confidenceLevel, ['high', 'medium'], true)) {
                $rowClasses[] = 'cat4-mapping-row-' . $confidenceLevel;
            }

            $row = $table->addRow();
            $row->addClass(implode(' ', $rowClasses));

            $row->addContent((string)$field['category']);
            $row->addContent((string)$field['name']);

            $row->addSelect("header[$namespace][$fieldID]")
                ->fromArray($headerOptions)
                ->selected($selectedHeader)
                ->setClass('cat4-mapping-select')
                ->setAttribute('onchange', 'window.markCat4MappingChanged && window.markCat4MappingChanged(this)')
                ->setAttribute('data-placeholder-option', __('Select a spreadsheet column'))
                ->setAttribute('data-confidence-level', $confidenceLevel)
                ->setAttribute('data-initial-value', $selectedHeader)
                ->setAttribute('data-initial-label', $selectedHeader === '' ? __('Select a spreadsheet column') : $selectedHeader);

            $row->addYesNo("active[$namespace][$fieldID]")
                ->selected($selectedActive);
        }
    }
}
