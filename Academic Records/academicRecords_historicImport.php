<?php
/**
 * Imports grades from the years before Gibbon was in use.
 *
 * Four steps, the same shape as CAT4 Import: choose the file, confirm what
 * was read, dry run, then run live. The dry run and the live run call the
 * same importer, so the report on the third step is exactly what the
 * fourth step writes.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Data\Importer;
use Gibbon\Domain\DataSet;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\Module\AcademicRecords\Domain\HistoricGradeGateway;
use Gibbon\Module\AcademicRecords\Historic\Importer as HistoricImporter;
use Gibbon\Module\AcademicRecords\Historic\RowResolver;

require_once __DIR__ . '/moduleFunctions.php';

/**
 * The spreadsheet headings this import understands.
 *
 * Grade Level and Credit Awarded are cross checks rather than inputs, so a
 * file without them still imports. Any other column, including a name
 * column, is read past and never stored.
 *
 * @return array Folded heading => canonical field name.
 */
function historicImportHeaderMap(): array
{
    return [
        'studentid' => 'studentID',
        'student id' => 'studentID',
        'year' => 'year',
        'school year' => 'year',
        'grade level' => 'gradeLevel',
        'semester' => 'semester',
        'term' => 'semester',
        'subject name' => 'subject',
        'subject' => 'subject',
        'semester grade' => 'grade',
        'grade' => 'grade',
        'credit awarded' => 'credit',
    ];
}

/**
 * Match the file's headings to the fields the import needs.
 *
 * @param array $headings The heading line, as read.
 *
 * @return array Keys columns, found and missing.
 */
function historicImportMatchHeadings(array $headings): array
{
    $map = historicImportHeaderMap();
    $columns = [];
    $found = [];

    foreach ($headings as $index => $heading) {
        $field = $map[HistoricGradeGateway::foldKey((string) $heading)] ?? '';

        if ($field === '' || isset($found[$field])) {
            continue;
        }

        $columns[$index] = $field;
        $found[$field] = (string) $heading;
    }

    $missing = array_values(array_diff(RowResolver::REQUIRED_FIELDS, array_keys($found)));

    return ['columns' => $columns, 'found' => $found, 'missing' => $missing];
}

/**
 * Turn the file's lines into rows keyed by canonical field name.
 *
 * @param array $lines   Lines after the heading.
 * @param array $columns Column index => canonical field name.
 *
 * @return array
 */
function historicImportBuildRows(array $lines, array $columns): array
{
    $rows = [];

    foreach ($lines as $line) {
        $row = [];

        foreach ($columns as $index => $field) {
            $row[$field] = $line[$index] ?? '';
        }

        $rows[] = $row;
    }

    return $rows;
}

/**
 * The rows worth listing after a run.
 *
 * A file of several thousand clean rows says nothing by being printed, so
 * only the rows that did not import, and the rows that carry a note, are
 * listed. The counts above the table cover the rest.
 *
 * @param string $id      DataTable id, unique on the page.
 * @param array  $results Rows from the importer.
 * @param bool   $liveRun Whether the run wrote.
 *
 * @return string Empty when every row was clean.
 */
function renderHistoricRowResults(string $id, array $results, bool $liveRun): string
{
    $listed = [];

    foreach ($results as $result) {
        if ($result['status'] === 'blank') {
            continue;
        }

        if ($result['status'] === 'ready' && empty($result['warnings'])) {
            continue;
        }

        $listed[] = [
            'rowNumber' => $result['rowNumber'],
            'studentID' => $result['studentID'],
            'year' => $result['year'],
            'semester' => $result['semester'],
            'subject' => $result['subject'],
            'grade' => $result['grade'],
            'status' => $result['status'] === 'ready'
                ? ($liveRun ? __('Imported') : __('Will import'))
                : __('Not imported'),
            'detail' => $result['status'] === 'ready'
                ? implode(' ', $result['warnings'])
                : $result['reason'],
        ];
    }

    if (empty($listed)) {
        return '';
    }

    $table = DataTable::create($id);
    $table->setTitle(__('Rows Needing Attention'));
    $table->setDescription(__('Every row that did not import, and every row that imported with a note. Rows that imported cleanly are counted above but not listed.'));

    $table->addColumn('rowNumber', __('Row'));
    $table->addColumn('studentID', __('Student ID'));
    $table->addColumn('year', __('Year'));
    $table->addColumn('semester', __('Semester'));
    $table->addColumn('subject', __('Subject'));
    $table->addColumn('grade', __('Grade'));
    $table->addColumn('status', __('Status'));
    $table->addColumn('detail', __('Reason'));

    return $table->render(new DataSet($listed));
}

/**
 * A count of each reason a row did not import.
 *
 * @param array $summary Summary from the importer.
 *
 * @return string Empty when no row was skipped.
 */
function renderHistoricReasonSummary(array $summary): string
{
    $counts = $summary['reasonCounts'] ?? [];

    if (empty($counts)) {
        return '';
    }

    $labels = [
        RowResolver::REASON_UNKNOWN_STUDENT => __('Student ID not found in Gibbon'),
        RowResolver::REASON_UNKNOWN_YEAR => __('School year not found'),
        RowResolver::REASON_DUPLICATE => __('The same student, class and term appears more than once'),
        RowResolver::REASON_MISSING_FIELD => __('A required value is missing'),
        RowResolver::REASON_UNKNOWN_TERM => __('That school year has no such term'),
        RowResolver::REASON_UNKNOWN_COURSE => __('No course of that name in that school year'),
        RowResolver::REASON_AMBIGUOUS_COURSE => __('More than one course of that name'),
        RowResolver::REASON_NOT_ENROLLED => __('Student not enrolled in that course'),
        RowResolver::REASON_AMBIGUOUS_CLASS => __('Student enrolled in more than one class of that course'),
        RowResolver::REASON_NO_SCALE => __('No single grade scale fits the class'),
    ];

    $blocking = RowResolver::blockingReasons();
    $rows = [];

    foreach ($counts as $code => $count) {
        $rows[] = [
            'reason' => $labels[$code] ?? $code,
            'stops' => in_array($code, $blocking, true) ? __('Yes') : __('No'),
            'count' => $count,
        ];
    }

    $table = DataTable::create('historicReasons');
    $table->setTitle(__('Why Rows Did Not Import'));
    $table->addColumn('reason', __('Reason'));
    $table->addColumn('stops', __('Stops the Import'));
    $table->addColumn('count', __('Rows'));

    return $table->render(new DataSet($rows));
}

/**
 * The standard importer summary, with the counts this import adds.
 *
 * @param object $page    The page, for the core template.
 * @param array  $results Values in the shape importer.twig.html expects.
 *
 * @return void
 */
function renderHistoricExecutionResults($page, array $results): void
{
    echo $page->fetchFromTemplate('importer.twig.html', $results);

    echo '<table class="smallIntBorder" cellspacing="0" style="margin: 0 auto; width: 60%;">';
    echo '<tr><td class="right" width="50%">' . __('Internal Assessment Columns to Create') . ':</td><td>'
        . (int) ($results['columnsToCreate'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('Internal Assessment Columns Reused') . ':</td><td>'
        . (int) ($results['columnsReused'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('Database Rows with No Change') . ':</td><td>'
        . (int) ($results['noChange'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('Blank Lines Ignored') . ':</td><td>'
        . (int) ($results['blankRows'] ?? 0) . '</td></tr>';
    echo '</table><br/>';
}

/**
 * The JSON shown in the collapsed Data panel on the dry run step.
 *
 * @param array $result   Result from the importer.
 * @param array $headings Headings matched in the file.
 *
 * @return string
 */
function buildHistoricPayloadPreview(array $result, array $headings): string
{
    $payload = [
        'transferFormat' => 'Uploaded spreadsheet parsed into canonical fields',
        'headingsMatched' => $headings,
        'context' => $result['context'] ?? [],
        'summary' => $result['summary'] ?? [],
        'warnings' => $result['warnings'] ?? [],
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT);

    return $json === false ? '' : $json;
}

if (!isActionAccessible($guid, $connection2, "/modules/Academic Records/academicRecords_historicImport.php")) {
    $page->addError(__('You do not have access.'));
    return;
}

$settingGateway = $container->get(SettingGateway::class);
$internalAssessmentType = trim((string) $settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType'));

if ($internalAssessmentType === '') {
    $settingsURL = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_settings.php';

    $page->addError(__('Import Historic Grades cannot continue until Academic Records Settings have been configured.'));

    echo '<h2>' . __('Module Setup Required') . '</h2>';
    echo academicRecordsSetupWarning(
        __('Before importing grades, you must choose the Internal Assessment Type in Academic Records Settings.<br />')
        . __('Open Academic Records Settings, choose an Internal Assessment Type, save the settings, and then return to this page.'),
        __('Go to Academic Records Settings'),
        $settingsURL
    );
    return;
}

$step = isset($_GET['step']) ? min(max(1, (int) $_GET['step']), 4) : 1;

$steps = [
    1 => __('Select File'),
    2 => __('Confirm'),
    3 => __('Dry Run'),
    4 => __('Live Run'),
];

$page->breadcrumbs->add(__('Import Historic Grades'));
$page->breadcrumbs->add(__('Step {number}', ['number' => $step]) . ' - ' . $steps[$step]);

echo "<ul class='multiPartForm'>";
foreach ($steps as $number => $label) {
    printf("<li class='step %s'>%s</li>", ($step >= $number) ? 'active' : '', $label);
}
echo '</ul>';

echo '<h2>' . __('Step {number}', ['number' => $step]) . ' - ' . $steps[$step] . '</h2>';

$gateway = $container->get(HistoricGradeGateway::class);
$importer = new Importer($pdo);

/* -----------------------------------------------------
   STEP 1: Select File
----------------------------------------------------- */

if ($step === 1) {

    echo Format::alert(__('Always backup your database before importing.'), 'message');

    echo Format::alert(
        __('The file needs a Student ID, Year, Semester, Subject Name and Semester Grade column. Grade Level and Credit Awarded are checked against Gibbon where they are present. Any other column is ignored.'),
        'message'
    );

    $scales = $gateway->selectScaleOptions();

    if (empty($scales)) {
        echo Format::alert(__('There are no active grade scales, so no grade in the file could be matched.'));
        return;
    }

    $form = Form::create(
        'historicStep1',
        $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_historicImport.php&step=2'
    );

    $form->addHiddenValue('address', $session->get('address'));

    $row = $form->addRow();
    $row->addLabel('file', __('File'));
    $row->addFileUpload('file')
        ->required()
        ->accepts('.csv,.xls,.xlsx');

    $row = $form->addRow();
    $row->addLabel('gibbonScaleID', __('Default Grade Scale'))
        ->description(__('The scale the grades in the file are on. A class whose grades do not all fit this scale is matched to the one active scale that does hold them, such as a pass or fail course among graded ones.'));
    $row->addSelect('gibbonScaleID')
        ->fromArray($scales)
        ->selected($gateway->getUsualScale($internalAssessmentType))
        ->required();

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Continue'));

    echo $form->getOutput();
    return;
}

/* -----------------------------------------------------
   STEP 2: Confirm
----------------------------------------------------- */

if ($step === 2) {

    if (empty($_FILES['file']['tmp_name'])) {
        echo Format::alert(__('No file uploaded.'));
        return;
    }

    if (!$importer->isValidMimeType($_FILES['file']['type'])) {
        echo Format::alert(__('Invalid file type.'));
        return;
    }

    $scaleID = trim((string) ($_POST['gibbonScaleID'] ?? ''));

    if ($scaleID === '') {
        echo Format::alert(__('No grade scale was chosen.'));
        return;
    }

    $csvData = $importer->readFileIntoCSV();

    if (empty($csvData)) {
        echo Format::alert(__('Error reading file.'));
        return;
    }

    $lines = academicRecordsParseCsv($csvData);

    if (count($lines) < 2) {
        echo Format::alert(__('File appears empty.'));
        return;
    }

    $headings = array_shift($lines);
    $matched = historicImportMatchHeadings($headings);

    $headingRows = [];

    foreach (RowResolver::REQUIRED_FIELDS as $field) {
        $headingRows[] = [
            'field' => RowResolver::fieldLabel($field),
            'need' => __('Required'),
            'column' => $matched['found'][$field] ?? __('Not found'),
        ];
    }

    foreach (['gradeLevel', 'credit'] as $field) {
        $headingRows[] = [
            'field' => RowResolver::fieldLabel($field),
            'need' => __('Checked where present'),
            'column' => $matched['found'][$field] ?? __('Not found'),
        ];
    }

    $table = DataTable::create('historicHeadings');
    $table->setTitle(__('Columns Read From the File'));
    $table->addColumn('field', __('Needed For'));
    $table->addColumn('need', __('Required'));
    $table->addColumn('column', __('Column In File'));

    echo $table->render(new DataSet($headingRows));

    if (!empty($matched['missing'])) {
        echo Format::alert(__('The file has no {fields} column, so it cannot be imported. Correct the heading row and upload it again.', [
            'fields' => implode(', ', array_map([RowResolver::class, 'fieldLabel'], $matched['missing'])),
        ]));
        return;
    }

    $rows = historicImportBuildRows($lines, $matched['columns']);

    $populated = 0;
    $years = [];

    foreach ($rows as $row) {
        if (trim(implode('', $row)) === '') {
            continue;
        }

        $populated++;
        $key = trim((string) $row['year']) . ' ' . __('Semester') . ' ' . trim((string) $row['semester']);
        $years[$key] = ($years[$key] ?? 0) + 1;
    }

    ksort($years);

    $yearRows = [];

    foreach ($years as $label => $count) {
        $yearRows[] = ['period' => $label, 'count' => $count];
    }

    $periods = DataTable::create('historicPeriods');
    $periods->setTitle(__('What the File Covers'));
    $periods->setDescription(__('{populated} rows hold data. {blank} blank lines were ignored.', [
        'populated' => $populated,
        'blank' => count($rows) - $populated,
    ]));
    $periods->addColumn('period', __('Year and Semester'));
    $periods->addColumn('count', __('Rows'));

    echo $periods->render(new DataSet($yearRows));

    $form = Form::createBlank(
        'historicStep2',
        $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_historicImport.php&step=3'
    );

    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('csvData', $csvData);
    $form->addHiddenValue('gibbonScaleID', $scaleID);

    $backURL = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_historicImport.php&step=1';

    $row = $form->addRow();
    $row->addContent(academicRecordsWizardNav($backURL, __('Dry Run')));

    echo $form->getOutput();
    return;
}

/* -----------------------------------------------------
   STEP 3 & 4: Dry Run and Live Run
----------------------------------------------------- */

if ($step === 3 || $step === 4) {

    $isLive = ($step === 4);
    $memoryStart = memory_get_usage();
    $timeStart = microtime(true);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Format::alert(__('Your request failed because your inputs were invalid.'));
        return;
    }

    $csvData = $_POST['csvData'] ?? '';
    $scaleID = trim((string) ($_POST['gibbonScaleID'] ?? ''));

    if ($csvData === '' || $scaleID === '') {
        echo Format::alert(__('Your request failed because your inputs were invalid.'));
        return;
    }

    $lines = academicRecordsParseCsv($csvData);

    if (count($lines) < 2) {
        echo Format::alert(__('No data found.'));
        return;
    }

    $headings = array_shift($lines);
    $matched = historicImportMatchHeadings($headings);

    if (!empty($matched['missing'])) {
        echo Format::alert(__('The file has no {fields} column, so it cannot be imported.', [
            'fields' => implode(', ', array_map([RowResolver::class, 'fieldLabel'], $matched['missing'])),
        ]));
        return;
    }

    $rows = historicImportBuildRows($lines, $matched['columns']);

    $result = $container->get(HistoricImporter::class)->run(
        $rows,
        $scaleID,
        (int) $session->get('gibbonPersonID'),
        $isLive
    );

    $summary = $result['summary'] ?? [];
    $overallSuccess = (bool) ($result['success'] ?? false);

    if ($overallSuccess && empty($result['message'])) {
        if ($isLive) {
            echo Format::alert(
                __('The import completed successfully and the relevant Internal Assessment columns and entries have been created and/or updated.'),
                'success'
            );
        } else {
            echo Format::alert(
                __('The data was successfully validated. This is a <b>DRY RUN!</b> No changes have been made to the database.<br />If everything looks good here, you can click "Run Live Import" to complete this import.'),
                'message'
            );
        }
    } else {
        echo Format::alert($result['message'] ?: __('The import failed.'), 'warning');
    }

    foreach ($result['warnings'] ?? [] as $warning) {
        echo Format::alert($warning, 'warning');
    }

    renderHistoricExecutionResults($page, [
        'step' => $step,
        'importSuccess' => true,
        'buildSuccess' => $overallSuccess,
        'databaseSuccess' => $overallSuccess,
        'rows' => (int) ($summary['rowsReceived'] ?? 0),
        'rowerrors' => (int) ($summary['rowsSkipped'] ?? 0),
        'errors' => $overallSuccess ? 0 : 1,
        'warnings' => count($result['warnings'] ?? []),
        'inserts' => (int) ($summary['entriesInserted'] ?? 0),
        'inserts_skipped' => 0,
        'updates' => (int) ($summary['entriesUpdated'] ?? 0),
        'updates_skipped' => (int) ($summary['entriesNoChange'] ?? 0),
        'executionTime' => mb_substr((string) (microtime(true) - $timeStart), 0, 6) . ' sec',
        'memoryUsage' => Format::filesize(max(0, memory_get_usage() - $memoryStart)),
        'columnsToCreate' => (int) ($summary['columnsToCreate'] ?? 0),
        'columnsReused' => (int) ($summary['columnsReused'] ?? 0),
        'noChange' => (int) ($summary['entriesNoChange'] ?? 0),
        'blankRows' => (int) ($summary['blankRows'] ?? 0),
        'lastError' => $overallSuccess ? '' : (string) ($result['message'] ?? ''),
    ]);

    echo renderHistoricReasonSummary($summary);

    if (!$isLive) {
        $action = $session->get('absoluteURL')
            . '/index.php?q=/modules/Academic Records/academicRecords_historicImport.php&step=4';

        $backURL = $session->get('absoluteURL')
            . '/index.php?q=/modules/Academic Records/academicRecords_historicImport.php&step=1';

        $form = Form::createBlank('historicDryRunConfirm', $action);
        $form->addHiddenValue('address', $session->get('address'));
        $form->addHiddenValue('csvData', $csvData);
        $form->addHiddenValue('gibbonScaleID', $scaleID);

        $payloadPanel = academicRecordsPayloadPanel(buildHistoricPayloadPreview($result, $matched['found']));

        if ($payloadPanel !== '') {
            $form->addRow()->addContent($payloadPanel);
        }

        $rowResults = renderHistoricRowResults('historicResults', $result['rows'] ?? [], false);

        if ($rowResults !== '') {
            $form->addRow()->addContent($rowResults);
        }

        $row = $form->addRow();
        $row->addContent(academicRecordsWizardNav(
            $backURL,
            __('Run Live Import'),
            (bool) ($result['canLiveRun'] ?? false),
            ['id' => 'submitStep3']
        ));

        echo $form->getOutput();
        return;
    }

    echo renderHistoricRowResults('historicResultsLive', $result['rows'] ?? [], true);
    return;
}
