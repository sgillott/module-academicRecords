<?php

use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Services\Format;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\AcademicRecordsGateway;
use Gibbon\Module\AcademicRecords\Domain\StoredGradeGateway;
use Gibbon\Module\AcademicRecords\Domain\StoreFilterGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, "/modules/Academic Records/academicRecords_store.php")) {
    $page->addError(__('You do not have access.'));
    return;
}

$settingGateway = $container->get(SettingGateway::class);
$internalAssessmentTypeSetting = $settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType', true);
$internalAssessmentType = trim((string) ($internalAssessmentTypeSetting['value'] ?? ''));

if ($internalAssessmentType === '') {
    $settingsURL = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_settings.php';

    $page->addError(__('Store Grades cannot continue until Academic Records Settings have been configured.'));

    echo '<h2>' . __('Module Setup Required') . '</h2>';
    echo academicRecordsSetupWarning(
        __('Before storing grades, you must choose the Internal Assessment Type in Academic Records Settings.<br />')
        . __('Open Academic Records Settings, choose an Internal Assessment Type, save the settings, and then return to this page.'),
        __('Go to Academic Records Settings'),
        $settingsURL
    );
    return;
}

/* -----------------------------------------------------
   Step Setup
----------------------------------------------------- */

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$step = max(1, min(4, $step));

$steps = [
    1 => __('Select Grades to store'),
    2 => __('Preview'),
    3 => __('Dry Run'),
    4 => __('Store Grades'),
];

$page->breadcrumbs->add(__('Store Grades'));
$page->breadcrumbs->add(__('Step {number}', ['number' => $step]) . ' - ' . $steps[$step]);

$page->stylesheets->add('module-academicRecords', 'modules/Academic Records/css/module.css');

/* -----------------------------------------------------
   Multi step header UI
----------------------------------------------------- */

echo "<ul class='multiPartForm'>";
printf("<li class='step %s'>%s</li>", ($step >= 1) ? "active" : "", $steps[1]);
printf("<li class='step %s'>%s</li>", ($step >= 2) ? "active" : "", $steps[2]);
printf("<li class='step %s'>%s</li>", ($step >= 3) ? "active" : "", $steps[3]);
printf("<li class='step %s'>%s</li>", ($step >= 4) ? "active" : "", $steps[4]);
echo "</ul>";

echo '<h2>';
echo __('Step {number}', ['number' => $step]) . ' - ' . $steps[$step];
echo '</h2>';

/* -----------------------------------------------------
   Helpers
----------------------------------------------------- */

function renderStoreExecutionResults($page, array $results): void
{
    echo $page->fetchFromTemplate('importer.twig.html', $results);

    echo '<table class="smallIntBorder" cellspacing="0" style="margin: 0 auto; width: 60%;">';
    echo '<tr><td class="right" width="50%">' . __('Internal Assessment Columns to Create') . ':</td><td>'
        . (int) ($results['columnsToCreate'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('Database Rows with No Change') . ':</td><td>'
        . (int) ($results['noChange'] ?? 0) . '</td></tr>';
    echo '</table><br/>';
}

function buildStorePayloadPreview(array $result): string
{
    $payload = [
        'transferFormat' => $result['payload']['transferFormat'] ?? 'Unknown',
        'warnings' => $result['payload']['warnings'] ?? [],
        'filtersReceived' => $result['payload']['filtersReceived'] ?? [],
        'eligibleReportingRows' => array_map(function ($row) {
            return [
                'student' => $row['studentName'] ?? '',
                'course' => $row['courseName'] ?? '',
                'class' => $row['className'] ?? '',
                'criteriaType' => $row['criteriaTypeName'] ?? '',
                'criteria' => $row['criteriaName'] ?? '',
                'value' => $row['value'] ?? '',
                'sourceDate' => isset($row['timestampModified']) && !empty($row['timestampModified'])
                    ? substr((string) $row['timestampModified'], 0, 10)
                    : (isset($row['timestampCreated']) && !empty($row['timestampCreated'])
                        ? substr((string) $row['timestampCreated'], 0, 10)
                        : null),
            ];
        }, $result['payload']['eligibleReportingRows'] ?? []),
        'storagePlan' => $result['payload']['storagePlan'] ?? [],
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT);
    return $json === false ? '' : $json;
}

/* -----------------------------------------------------
   STEP 1: Select Grades to store
----------------------------------------------------- */

if ($step === 1) {

    /* -----------------------------------------------------
       Reporting Cycles
    ----------------------------------------------------- */

    $filterGateway = $container->get(StoreFilterGateway::class);

    $cycles  = $filterGateway->selectCycleOptions();
    $cycleID = $_GET['gibbonReportingCycleID'] ?? array_key_first($cycles);

    /* -----------------------------------------------------
       Term

       Transcripts read the term from the stored grade index, not from the
       reporting cycle dates. A cycle commonly runs after its term closes.
    ----------------------------------------------------- */

    $storedGradeGateway = $container->get(StoredGradeGateway::class);

    $terms = $storedGradeGateway->selectTermsByCycle((int) $cycleID);

    $termOptions = [];
    foreach ($terms as $term) {
        $termOptions[(string) $term['gibbonSchoolYearTermID']] = (string) $term['name'];
    }

    if (empty($termOptions)) {
        $termsURL = $session->get('absoluteURL')
            . '/index.php?q=/modules/School Admin/schoolYearTerm_manage.php';

        $page->addError(__('Store Grades cannot continue until the school year of this reporting cycle has terms.'));

        echo academicRecordsSetupWarning(
            __('Stored grades are recorded against a school year term, so transcripts can place them in the right column.<br />')
            . __('Add the terms for this school year, and then return to this page.'),
            __('Go to Manage School Year Terms'),
            $termsURL
        );
        return;
    }

    $termID = normalizeSchoolYearTermID($_GET['gibbonSchoolYearTermID'] ?? '');

    if ($termID === '' || !isset($termOptions[$termID])) {
        $termID = $storedGradeGateway->suggestTermForCycle((int) $cycleID);
    }

    /* -----------------------------------------------------
       Year Groups
    ----------------------------------------------------- */

    $filterYearGroups = $_GET['filterYearGroups'] ?? 'N';

    $yearGroupIDs = normalizeRequestList($_GET['gibbonYearGroupIDList'] ?? []);

    $cycleYearGroups = $filterGateway->selectYearGroupsByCycle((int) $cycleID);

    $yearGroupsTouched = ($_GET['yearGroupsTouched'] ?? '0') === '1';
    if (!$yearGroupsTouched && empty($yearGroupIDs)) {
        $yearGroupIDs = array_keys($cycleYearGroups);
    }

    /* -----------------------------------------------------
       Students
    ----------------------------------------------------- */

    $studentIDs = normalizeRequestList($_GET['studentIDs'] ?? []);
    $filterStudents = $_GET['filterStudents'] ?? 'N';

    /* -----------------------------------------------------
       Subjects
    ----------------------------------------------------- */

    $filterSubjects = $_GET['filterSubjects'] ?? 'N';
    $subjectIDs = normalizeRequestList($_GET['subjectIDs'] ?? []);

    $effectiveYearGroupIDs = ($filterYearGroups === 'Y')
        ? $yearGroupIDs
        : array_keys($cycleYearGroups);

    $subjects = $filterGateway->selectSubjectsByYearGroups(
        $effectiveYearGroupIDs,
        (int) $cycleID,
        $studentIDs,
        $filterStudents === 'Y'
    );

    /* -----------------------------------------------------
       Grades to store
    ----------------------------------------------------- */

    $criteriaTypeIDs = normalizeRequestList($_GET['criteriaTypeIDs'] ?? []);

    /* -----------------------------------------------------
       Form Setup
    ----------------------------------------------------- */

    $action = $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_store.php';

    $form = Form::create('filters', $action, 'get');
    $form->setFactory(DatabaseFormFactory::create($container->get('db')));
    $form->setAttribute(
        'data-store-ajax-url',
        $session->get('absoluteURL') . '/modules/' . rawurlencode((string) $session->get('module')) . '/academicRecords_store_ajax.php'
    );

    $form->addHiddenValue('q', '/modules/Academic Records/academicRecords_store.php');
    $form->addHiddenValue('step', '1');

    /* -----------------------------------------------------
       Reporting Cycle
    ----------------------------------------------------- */

    $row = $form->addRow();
    $row->addLabel('gibbonReportingCycleID', __('Reporting Cycle'));
    $row->addSelect('gibbonReportingCycleID')
        ->fromArray($cycles)
        ->selected($cycleID)
        ->required();

    /* -----------------------------------------------------
       Term
    ----------------------------------------------------- */

    $row = $form->addRow();
    $row->addLabel('gibbonSchoolYearTermID', __('Store as Term'))
        ->description(__('The term these grades belong to. Transcripts read this value, because a reporting cycle often runs after its term has closed. Check the suggestion before you continue.'));
    $row->addSelect('gibbonSchoolYearTermID')
        ->fromArray($termOptions)
        ->selected($termID)
        ->required();

    /* -----------------------------------------------------
       Year Groups
    ----------------------------------------------------- */

    $form->addRow()->addHeading(__('Filters'));

    $row = $form->addRow();
    $row->addContent(
        '<div id="storeAjaxStatus" class="store-ajax-status" aria-live="polite">'
        . __('Waiting for filter changes.')
        . '</div>'
    );

    $form->toggleVisibilityByClass('ygPanel')
        ->onClick('filterYearGroups')
        ->when('Y');

    $row = $form->addRow();
    $row->addLabel('filterYearGroups', __('Filter by Year Groups'));
    $row->addYesNo('filterYearGroups')
        ->checked($filterYearGroups);

    $row = $form->addRow()->addClass('ygPanel');
    $row->addCheckbox('gibbonYearGroupIDList[]')
        ->fromArray($cycleYearGroups)
        ->checked($yearGroupIDs)
        ->addCheckAllNone();

    /* -----------------------------------------------------
       Students
    ----------------------------------------------------- */

    $form->toggleVisibilityByClass('studentPanel')
        ->onClick('filterStudents')
        ->when('Y');

    $row = $form->addRow();
    $row->addLabel('filterStudents', __('Filter by Students'));
    $row->addYesNo('filterStudents')
        ->checked($filterStudents);

    $studentGateway = $container->get(StudentGateway::class);
    $criteriaObj = $studentGateway->newQueryCriteria()->sortBy(['surname', 'preferredName']);

    $students = $studentGateway
        ->queryStudentsBySchoolYear($criteriaObj, $session->get('gibbonSchoolYearID'))
        ->toArray();

    $studentSelection = buildStudentSelectionData($students, $effectiveYearGroupIDs, $studentIDs);

    $col = $form->addRow()->addClass('studentPanel')->addColumn();
    $col->addLabel('studentIDs', __('Students'));

    $multi = $col->addMultiSelect('studentIDs');
    $multi->addSortableAttribute(__('Form Group'), $studentSelection['formGroups']);
    $multi->source()->fromArray($studentSelection['source']);
    $multi->destination()->fromArray($studentSelection['destination']);

    /* -----------------------------------------------------
       Subjects
    ----------------------------------------------------- */

    $form->toggleVisibilityByClass('subjectPanel')
        ->onClick('filterSubjects')
        ->when('Y');

    $row = $form->addRow();
    $row->addLabel('filterSubjects', __('Filter by Subjects'));
    $row->addYesNo('filterSubjects')
        ->checked($filterSubjects);

    $row = $form->addRow()->addClass('subjectPanel');

    $row->addSelect('subjectIDs[]')
        ->setID('subjectIDsSelect')
        ->fromArray($subjects)
        ->selected($subjectIDs)
        ->selectMultiple()
        ->setAttribute('size', min(12, max(4, count($subjects))))
        ->required(false);

    /* -----------------------------------------------------
       Grades to store
    ----------------------------------------------------- */

    $form->addRow()->addHeading(__('Grades to store'));

    $criteriaTypes = $filterGateway->selectCriteriaTypesByCycle(
        (int) $cycleID,
        $effectiveYearGroupIDs,
        $filterYearGroups === 'Y',
        $subjectIDs,
        $filterSubjects === 'Y',
        $studentIDs,
        $filterStudents === 'Y'
    );

    $valueSelections = [];
    $valueSelectionsSaved = [];

    foreach ($_GET as $key => $val) {
        if (!is_array($val)) {
            continue;
        }

        if (strpos($key, 'values_') === 0) {
            $valueSelections[$key] = $val;
        }
        if (strpos($key, 'valuesSaved_') === 0) {
            $valueSelectionsSaved[$key] = $val;
        }
    }

    $row = $form->addRow();
    $row->addContent(
        renderCriteriaTableHtml(
            $filterGateway,
            $criteriaTypes,
            $criteriaTypeIDs,
            $valueSelections,
            $valueSelectionsSaved
        )
    );

    /* -----------------------------------------------------
       Footer
    ----------------------------------------------------- */

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Preview'))
        ->setAttribute('onclick', "this.form.step.value='2';");

    echo $form->getOutput();

    // The file's modification time is the cache key, so an edit reaches the
    // browser at once. The installed module version, which core uses for
    // js/module.js, only changes when the module update is run.
    $page->scripts->add(
        'academicRecords-store',
        'modules/Academic Records/js/store.js',
        ['context' => 'foot', 'version' => (string) filemtime(__DIR__ . '/js/store.js')]
    );
    return;
}

/* -----------------------------------------------------
   STEP 2: Preview
----------------------------------------------------- */

if ($step === 2) {

    $data = $_GET;

    $cycleID = (int)($data['gibbonReportingCycleID'] ?? 0);
    if (empty($cycleID)) {
        echo Format::alert(__('Your request failed because your inputs were invalid.'));
        return;
    }

    echo Format::alert(
        __('Preview the records that may be written. If any are incorrect, go back to step 1, otherwise continue to dry run.'),
        'message'
    );

    $gateway = $container->get(AcademicRecordsGateway::class);

    $criteria = $gateway->newQueryCriteria(true)
        ->sortBy(['p.surname', 'p.preferredName', 'c.nameShort', 'rc.name'])
        ->pageSize(50)
        ->fromPOST();

    $actionStep2 = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_store.php&step=2';

    $actionStep3 = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_store.php&step=3';

    $form = Form::create('previewStep', $actionStep2);
    $form->setFactory(DatabaseFormFactory::create($container->get('db')));

    $form->addHiddenValue('q', '/modules/Academic Records/academicRecords_store.php');
    $form->addHiddenValue('step', '2');
    academicRecordsCarryForward($form, $data);

    $dataSet = $gateway->queryEligibleReportingGrades($criteria, $cycleID, $data);

    $table = $form->addRow()
        ->addDataTable('academicRecordsPreview', $criteria)
        ->withData($dataSet);

    $table->addColumn('studentName', __('Student'));
    $table->addColumn('courseName', __('Course'));
    $table->addColumn('className', __('Class'));
    $table->addColumn('criteriaName', __('Criteria'));
    $table->addColumn('value', __('Value'));

    $backQuery = $data;
    $backQuery['q'] = '/modules/Academic Records/academicRecords_store.php';
    $backQuery['step'] = 1;

    $backURL = $session->get('absoluteURL') . '/index.php?' . http_build_query($backQuery);

    $row = $form->addRow();
    $row->addContent(academicRecordsWizardNav($backURL, __('Continue to Dry Run'), true, [
        'onclick' => 'this.form.action=\'' . addslashes($actionStep3) . '\'; this.form.step.value=\'3\';',
    ]));

    echo $form->getOutput();
    return;
}

/* -----------------------------------------------------
   STEP 3 & 4: Dry Run and Live Run (same structure as import_run.php)
----------------------------------------------------- */

if ($step === 3 || $step === 4) {

    $isLive = ($step === 4);
    $memoryStart = memory_get_usage();
    $timeStart = microtime(true);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Format::alert(__('Your request failed because your inputs were invalid.'));
        return;
    }

    $cycleID = (int)($_POST['gibbonReportingCycleID'] ?? 0);

    if (empty($cycleID)) {
        echo Format::alert(__('Invalid Reporting Cycle.'));
        return;
    }

    $filters = $_POST;

    $gateway = $container->get(AcademicRecordsGateway::class);

    try {
        $result = $gateway->dryRunStoreReportingGrades(
            $cycleID,
            $filters,
            (int) $session->get('gibbonPersonID'),
            $isLive
        );

        $overallSuccess = ($result['importSuccess'] ?? false)
            && ($result['buildSuccess'] ?? false)
            && ($result['databaseSuccess'] ?? false);

        if ($overallSuccess) {
            if ($isLive) {
                echo Format::alert(
                    __('The store completed successfully and all relevant Internal Assessment columns and entries have been created and/or updated.'),
                    'success'
                );
            } else {
                echo Format::alert(
                    __('The data was successfully validated. This is a <b>DRY RUN!</b> No changes have been made to the database.<br />If everything looks good here, you can click "Run Live Store" to complete this import.'),
                    'message'
                );
            }
        } else {
            echo Format::alert($result['lastError'] ?? __('An unknown error occurred, so the import will be aborted.'));
        }
    } catch (\Throwable $e) {
        $result = AcademicRecordsGateway::failureResult($e->getMessage(), true);
        $overallSuccess = false;
        echo Format::alert($e->getMessage());
    }

    $result['step'] = $step;
    $result['executionTime'] = mb_substr((string) (microtime(true) - $timeStart), 0, 6) . ' sec';
    $result['memoryUsage'] = Format::filesize(max(0, memory_get_usage() - $memoryStart));

    renderStoreExecutionResults($page, $result);

    if (!$isLive) {
        $action = $session->get('absoluteURL')
            . '/index.php?q=/modules/Academic Records/academicRecords_store.php&step=4';

        $backQuery = $_POST;
        $backQuery['q'] = '/modules/Academic Records/academicRecords_store.php';
        $backQuery['step'] = 2;
        $backURL = $session->get('absoluteURL') . '/index.php?' . http_build_query($backQuery);

        $form = Form::createBlank('dryRunConfirm', $action);
        $form->setFactory(DatabaseFormFactory::create($container->get('db')));
        $form->addHiddenValue('q', '/modules/Academic Records/academicRecords_store.php');
        $form->addHiddenValue('step', '4');
        academicRecordsCarryForward($form, $_POST);

        $payloadPanel = academicRecordsPayloadPanel(buildStorePayloadPreview($result));

        if ($payloadPanel !== '') {
            $form->addRow()->addContent($payloadPanel);
        }

        $row = $form->addRow();
        $row->addContent(academicRecordsWizardNav($backURL, __('Run Live Store'), $overallSuccess, ['id' => 'submitStep3']));

        echo $form->getOutput();
    }

    return;
}
