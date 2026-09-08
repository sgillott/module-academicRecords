<?php

use Gibbon\Forms\Form;
use Gibbon\Module\AcademicRecords\Domain\CAT4MappingGateway;

require_once __DIR__.'/moduleFunctions.php';
require_once __DIR__.'/includes/CAT4MappingService.php';
require_once __DIR__.'/includes/CAT4MappingRenderer.php';

if (!isActionAccessible($guid,$connection2,'/modules/Academic Records/academicRecords_cat4Mapping.php')) {
    $page->addError(__('You do not have access.'));
    return;
}

$page->breadcrumbs->add(__('CAT4 Import Mapping'));
echo '<h2>'.__('CAT4 Import Mapping').'</h2>';

$gateway = $container->get(CAT4MappingGateway::class);
$mappingService = new CAT4MappingService();

$assessments = $gateway->selectAssessmentsActive();

$assessmentOptions = [];
$assessmentNames = [];

foreach ($assessments as $assessment) {

    $id = (int)$assessment['gibbonExternalAssessmentID'];
    $name = (string)$assessment['name'];

    $assessmentOptions[$id] = $name;
    $assessmentNames[$id] = $name;
}

$cat4ID = isset($_GET['cat4ID']) ? (int)$_GET['cat4ID'] : 1;
$gcseID = isset($_GET['gcseID']) ? (int)$_GET['gcseID'] : 2;
$ibID   = isset($_GET['ibID']) ? (int)$_GET['ibID'] : 3;

$formSelect = Form::create(
    'assessment',
    $session->get('absoluteURL').'/index.php',
    'get'
);

$formSelect->addHiddenValue(
    'q',
    '/modules/Academic Records/academicRecords_cat4Mapping.php'
);

$row = $formSelect->addRow();
$row->addLabel('cat4ID',__('Select CAT4 External Assessment'));
$row->addSelect('cat4ID')
    ->fromArray($assessmentOptions)
    ->selected($cat4ID)
    ->setAttribute('onchange','this.form.submit()');

$row = $formSelect->addRow();
$row->addLabel('gcseID',__('Select GCSE External Assessment'));
$row->addSelect('gcseID')
    ->fromArray($assessmentOptions)
    ->selected($gcseID)
    ->setAttribute('onchange','this.form.submit()');

$row = $formSelect->addRow();
$row->addLabel('ibID',__('Select IB Diploma External Assessment'));
$row->addSelect('ibID')
    ->fromArray($assessmentOptions)
    ->selected($ibID)
    ->setAttribute('onchange','this.form.submit()');

echo $formSelect->getOutput();

$form = Form::create(
    'mapping',
    $session->get('absoluteURL').'/index.php?q=/modules/Academic Records/academicRecords_cat4MappingProcess.php',
    'post'
);

$form->addHiddenValue('address',$session->get('address'));
$form->addHiddenValue('cat4ID',$cat4ID);
$form->addHiddenValue('gcseID',$gcseID);
$form->addHiddenValue('ibID',$ibID);

CAT4MappingRenderer::renderSection(
    $form,
    $gateway,
    $mappingService,
    $cat4ID,
    $assessmentNames[$cat4ID] ?? '',
    'CAT4 Fields',
    'cat4'
);

CAT4MappingRenderer::renderSection(
    $form,
    $gateway,
    $mappingService,
    $gcseID,
    $assessmentNames[$gcseID] ?? '',
    'GCSE Fields',
    'gcse'
);

CAT4MappingRenderer::renderSection(
    $form,
    $gateway,
    $mappingService,
    $ibID,
    $assessmentNames[$ibID] ?? '',
    'IB Diploma Fields',
    'ib'
);

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__('Save Mapping'));

echo $form->getOutput();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    window.markCat4MappingChanged = function (selectElement) {
        var row = selectElement.closest('tr');
        if (!row) {
            return;
        }

        var value = (selectElement.value || '').trim();
        row.classList.remove(
            'cat4-mapping-row-saved',
            'cat4-mapping-row-missing',
            'cat4-mapping-row-high',
            'cat4-mapping-row-medium',
            'cat4-mapping-row-changed'
        );
        row.classList.add(value === '' ? 'cat4-mapping-row-missing' : 'cat4-mapping-row-changed');
    };
});
</script>
