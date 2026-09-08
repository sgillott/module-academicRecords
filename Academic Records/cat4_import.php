<?php

use Gibbon\Data\Importer;
use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\Domain\DataSet;
use Gibbon\Module\AcademicRecords\Domain\CAT4MappingGateway;

require __DIR__ . '/moduleFunctions.php';

function renderCAT4ExecutionResults($page, array $results): void
{
    echo $page->fetchFromTemplate('importer.twig.html', $results);

    echo '<table class="smallIntBorder" cellspacing="0" style="margin: 0 auto; width: 60%;">';
    echo '<tr><td class="right" width="50%">' . __('External Assessment Rows Inserted') . ':</td><td>'
        . (int) ($results['assessmentRowsInserted'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('External Assessment Rows Reused') . ':</td><td>'
        . (int) ($results['assessmentRowsReused'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('Database Rows with No Change') . ':</td><td>'
        . (int) ($results['noChange'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('Field Values Skipped') . ':</td><td>'
        . (int) ($results['fieldValuesSkipped'] ?? 0) . '</td></tr>';
    echo '</table><br/>';
}

function buildCAT4PayloadPreview(array $result, array $headings, array $assocRows): string
{
    $payload = [
        'transferFormat' => 'Uploaded spreadsheet parsed into associative rows',
        'mappingContext' => $result['mappingContext'] ?? [],
        'sourceHeadings' => $headings,
        'summary' => $result['summary'] ?? [],
        'warnings' => $result['warnings'] ?? [],
        'rowResults' => $result['rows'] ?? [],
        'sourcePreview' => array_slice($assocRows, 0, 25),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT);

    return $json === false ? '' : $json;
}

if (!isActionAccessible($guid, $connection2, "/modules/Academic Records/cat4_import.php")) {
    $page->addError(__('You do not have access.'));
    return;
}

$mappingGateway = $container->get(CAT4MappingGateway::class);
$defaultMappings = $mappingGateway->selectDefaultMappings();
$hasUsableDefaultMappings = false;

foreach ($defaultMappings as $mapping) {
    $mappingID = (int) ($mapping['academicRecordsCAT4MappingID'] ?? 0);
    if ($mappingID <= 0) {
        continue;
    }

    $fields = $mappingGateway->selectMappingFields($mappingID);
    if (!empty($fields)) {
        $hasUsableDefaultMappings = true;
        break;
    }
}

if (!$hasUsableDefaultMappings) {
    $mappingURL = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_cat4Mapping.php';

    $page->addError(__('CAT4 Import cannot continue until CAT4 Import Mapping has been configured.'));

    echo '<h2>' . __('Module Setup Required') . '</h2>';
    echo '<div class="warning flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">';
    echo '<div class="flex-1">';
    echo __('Before importing CAT4 data, you must complete the CAT4 Import Mapping setup and save the spreadsheet column mappings.');
    echo '</div>';
    echo '<div class="text-left sm:text-right sm:ml-auto">';
    echo '<a class="rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 border border-amber-600 bg-white hover:bg-amber-50 text-amber-900 no-underline" href="'
        . htmlspecialchars($mappingURL) . '">'
        . __('Go to CAT4 Import Mapping')
        . '</a>';
    echo '</div>';
    echo '</div>';
    return;
}

$step = isset($_GET['step']) ? min(max(1, (int)$_GET['step']), 4) : 1;

$page->breadcrumbs->add(__('CAT4 Import'));

echo "<ul class='multiPartForm'>";
printf("<li class='step %s'>%s</li>", ($step >= 1)? "active" : "", __('Select File'));
printf("<li class='step %s'>%s</li>", ($step >= 2)? "active" : "", __('Confirm'));
printf("<li class='step %s'>%s</li>", ($step >= 3)? "active" : "", __('Dry Run'));
printf("<li class='step %s'>%s</li>", ($step >= 4)? "active" : "", __('Live Run'));
echo "</ul>";

echo '<h2>'.__('Step {number}', ['number'=>$step]).'</h2>';

$importer = new Importer($pdo);

//
// STEP 1
//
if ($step == 1) {

    echo Format::alert(__('Always backup your database before importing.'), 'message');

    $form = Form::create('step1',
        $session->get('absoluteURL')
        .'/index.php?q=/modules/Academic Records/cat4_import.php&step=2'
    );

    $form->addHiddenValue('address', $session->get('address'));

    $row = $form->addRow();
    $row->addLabel('file', __('File'));
    $row->addFileUpload('file')
        ->required()
        ->accepts('.csv,.xls,.xlsx');

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Continue'));

    echo $form->getOutput();
}

//
// STEP 2 – Preview
//
elseif ($step == 2) {

    if (empty($_FILES['file']['tmp_name'])) {
        echo Format::alert(__('No file uploaded.'));
        return;
    }

    if (!$importer->isValidMimeType($_FILES['file']['type'])) {
        echo Format::alert(__('Invalid file type.'));
        return;
    }

    $csvData = $importer->readFileIntoCSV();

    if (empty($csvData)) {
        echo Format::alert(__('Error reading file.'));
        return;
    }

    // Parse CSV
    $rows = [];
    $handle = fopen('php://memory','r+');
    fwrite($handle, $csvData);
    rewind($handle);

    while (($data = fgetcsv($handle)) !== false) {
        $rows[] = $data;
    }
    fclose($handle);

    if (count($rows) < 2) {
        echo Format::alert(__('File appears empty.'));
        return;
    }

    $headings = $rows[0];
    $firstRow = $rows[1];

    // Build preview table
    $previewData = [];
    foreach ($headings as $index => $heading) {
        $previewData[] = [
            'column' => $heading,
            'value'  => $firstRow[$index] ?? ''
        ];
    }

    $table = DataTable::create('preview');
    $table->setTitle(__('Preview (First Record)'));

    $table->addColumn('column', __('Column'));
    $table->addColumn('value', __('Value'));

    echo $table->render(new DataSet($previewData));

    // Continue to Dry Run
    $form = Form::createBlank('step2',
        $session->get('absoluteURL')
        .'/index.php?q=/modules/Academic Records/cat4_import.php&step=3'
    );

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('csvData', $csvData);

    $row = $form->addRow();
    $row->addSubmit(__('Dry Run'));

    echo $form->getOutput();
}

//
// STEP 3 & 4 – Dry Run / Live Run
//
elseif ($step == 3 || $step == 4) {
    $isLive = ($step == 4);
    $memoryStart = memory_get_usage();
    $timeStart = microtime(true);

    $csvData = $_POST['csvData'] ?? null;
    if (empty($csvData)) {
        echo Format::alert(__('Invalid request.'));
        return;
    }

    $rows = [];
    $handle = fopen('php://memory','r+');
    fwrite($handle, $csvData);
    rewind($handle);

    while (($data = fgetcsv($handle)) !== false) {
        $rows[] = $data;
    }
    fclose($handle);

    if (count($rows) < 2) {
        echo Format::alert(__('No data found.'));
        return;
    }

    $headings = array_shift($rows);

	// Convert numeric rows into associative rows
	$assocRows = [];

	foreach ($rows as $row) {
        $normalisedRow = array_slice(array_pad($row, count($headings), ''), 0, count($headings));
		$assocRows[] = array_combine($headings, $normalisedRow);
	}

    require __DIR__.'/cat4_import_process.php';

    $result = runCAT4Import($pdo, $container, $assocRows, $isLive);

    $overallSuccess = (bool) ($result['success'] ?? false);

    if ($overallSuccess) {
        if ($isLive) {
            echo Format::alert(
                __('The CAT4 import completed successfully and the relevant External Assessment rows and entries have been created and/or updated.'),
                'success'
            );
        } else {
            echo Format::alert(
                __('The data was successfully validated. This is a <b>DRY RUN!</b> No changes have been made to the database.<br />If everything looks good here, you can click "Run Live Import" to complete this import.'),
                'message'
            );
        }
    } else {
        echo Format::alert($result['message'] ?? __('The CAT4 import failed.'), 'warning');
    }

    $summary = $result['summary'] ?? [];
    $warnings = $result['warnings'] ?? [];
    $rowResults = $result['rows'] ?? [];

    $results = [
        'step' => $step,
        'importSuccess' => true,
        'buildSuccess' => $overallSuccess,
        'databaseSuccess' => $overallSuccess,
        'rows' => (int) ($summary['rowsReceived'] ?? count($assocRows)),
        'rowerrors' => (int) ($summary['rowsSkipped'] ?? 0),
        'errors' => $overallSuccess ? 0 : 1,
        'warnings' => count($warnings),
        'inserts' => (int) ($summary['entriesInserted'] ?? 0),
        'inserts_skipped' => 0,
        'updates' => (int) ($summary['entriesUpdated'] ?? 0),
        'updates_skipped' => (int) ($summary['entriesNoChange'] ?? 0),
        'executionTime' => mb_substr((string) (microtime(true) - $timeStart), 0, 6) . ' sec',
        'memoryUsage' => Format::filesize(max(0, memory_get_usage() - $memoryStart)),
        'assessmentRowsInserted' => (int) ($summary['assessmentRowsInserted'] ?? 0),
        'assessmentRowsReused' => (int) ($summary['assessmentRowsReused'] ?? 0),
        'fieldValuesSkipped' => (int) ($summary['fieldValuesSkipped'] ?? 0),
        'noChange' => (int) ($summary['entriesNoChange'] ?? 0),
        'lastError' => $overallSuccess ? '' : (string) ($result['message'] ?? __('The CAT4 import failed.')),
    ];

    renderCAT4ExecutionResults($page, $results);

    if (!$isLive) {
        $action = $session->get('absoluteURL')
            . '/index.php?q=/modules/Academic Records/cat4_import.php&step=4';

        $backURL = $session->get('absoluteURL')
            . '/index.php?q=/modules/Academic Records/cat4_import.php&step=1';

        $form = Form::createBlank('cat4DryRunConfirm', $action);
        $form->addHiddenValue('address', $session->get('address'));
        $form->addHiddenValue('csvData', $csvData);

        $payloadPreview = buildCAT4PayloadPreview($result, $headings, $assocRows);

        if ($payloadPreview !== '') {
            $row = $form->addRow();
            $row->addContent(
                '<details class="w-full rounded border border-gray-400 bg-white">'
                . '<summary class="cursor-pointer select-none px-4 py-3 font-semibold text-gray-800">'
                . __('Data')
                . '</summary>'
                . '<div class="px-4 pb-4 pt-2">'
                . '<textarea readonly rows="16" cols="74" class="w-full" style="font-family: monospace;">'
                . htmlspecialchars($payloadPreview)
                . '</textarea>'
                . '</div>'
                . '</details>'
            );
        }

        $rowResultsHtml = '';
        if (!empty($rowResults)) {
            $table = DataTable::create('cat4ImportResults');
            $table->setTitle(__('Import Results'));
            $table->addColumn('assessment', __('Assessment'));
            $table->addColumn('rowNumber', __('Row'));
            $table->addColumn('student', __('Student'));
            $table->addColumn('date', __('Date'));
            $table->addColumn('status', __('Status'));
            $table->addColumn('details', __('Details'));

            $rowResultsHtml = $table->render(new DataSet($rowResults));
        }

        if ($rowResultsHtml !== '') {
            $row = $form->addRow();
            $row->addContent($rowResultsHtml);
        }

        $row = $form->addRow();
        $row->addContent(
            '<div style="width:100%; display:flex; justify-content:space-between; align-items:center; gap:16px; padding-top:8px;">'
            . '<a class="no-underline" style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:6px; border:1px solid #475569; background:#475569; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18);" onmouseover="this.style.backgroundColor=\'#334155\';this.style.borderColor=\'#334155\';" onmouseout="this.style.backgroundColor=\'#475569\';this.style.borderColor=\'#475569\';" href="' . htmlspecialchars($backURL) . '">'
            . '&#8592; ' . __('Back')
            . '</a>'
            . (!empty($result['canLiveRun'])
                ? '<button type="submit" id="submitStep3" style="display:inline-flex; align-items:center; padding:8px 16px; border-radius:6px; border:1px solid #1f2937; background:#374151; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18); cursor:pointer;" onmouseover="this.style.backgroundColor=\'#1f2937\';" onmouseout="this.style.backgroundColor=\'#374151\';">'
                . __('Run Live Import')
                . '</button>'
                : '<button type="button" id="submitStep3" disabled style="display:inline-flex; align-items:center; padding:8px 16px; border-radius:6px; border:1px solid #cbd5e1; background:#e2e8f0; color:#64748b; font-size:14px; font-weight:600; line-height:1.25; box-shadow:none; cursor:not-allowed;">'
                . __('Failed')
                . '</button>')
            . '</div>'
        );

        echo $form->getOutput();
    } else {
        if (!empty($rowResults)) {
            $table = DataTable::create('cat4ImportResultsLive');
            $table->setTitle(__('Import Results'));
            $table->addColumn('assessment', __('Assessment'));
            $table->addColumn('rowNumber', __('Row'));
            $table->addColumn('student', __('Student'));
            $table->addColumn('date', __('Date'));
            $table->addColumn('status', __('Status'));
            $table->addColumn('details', __('Details'));

            echo $table->render(new DataSet($rowResults));
        }
    }
}
