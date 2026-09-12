<?php

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\YearGroupMapGateway;
use Gibbon\Module\AcademicRecords\Testwise\StudentExport;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_studentExport.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Export Student Data to GL Assessment'));
echo '<h2>' . __('Export Student Data to GL Assessment') . '</h2>';

$settingGateway = $container->get(SettingGateway::class);
$region = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'testwiseRegion', true)['value'] ?? ''));

$yearGroupsURL = $session->get('absoluteURL')
    . '/index.php?q=/modules/Academic Records/academicRecords_testwiseYearGroups.php';

if ($region === '') {
    echo Format::alert(
        __('No Testwise Region is set, so the Year column cannot be formatted for GL Assessment. Set the region in Academic Records Settings, then confirm your year groups.'),
        'error'
    );
    return;
}

$unconfirmedYearGroups = $container->get(StudentExport::class)->unconfirmedYearGroups(
    $container->get(YearGroupMapGateway::class)->selectMapKeyed()
);

if (!empty($unconfirmedYearGroups)) {
    echo Format::alert(
        __('These year groups have students but no confirmed Testwise year: {list}. They will export a suggested value, or their Gibbon name if no value can be worked out. Confirm them on the Testwise Year Groups page first.', ['list' => '<b>' . htmlspecialchars(implode(', ', $unconfirmedYearGroups)) . '</b>'])
        . '<br/><a href="' . htmlspecialchars($yearGroupsURL) . '">' . __('Open Testwise Year Groups') . '</a>',
        'warning'
    );
}

echo Format::alert(
    __('This export includes all students who are enrolled in the current school year. You can optionally limit the export to students who joined after a chosen date.'),
    'message'
);

$form = Form::create(
    'studentExport',
    $session->get('absoluteURL') . '/modules/Academic Records/academicRecords_studentExportProcess.php'
);
$form->addHiddenValue('address', $session->get('address'));

$form->toggleVisibilityByClass('joinDatePanel')
    ->onClick('filterByJoinDate')
    ->when('Y');

$row = $form->addRow();
$row->addLabel('filterByJoinDate', __('Filter by Join Date'))
    ->description(__('Set to No to export all students enrolled in the current school year.'));
$row->addYesNo('filterByJoinDate')->selected('N');

$row = $form->addRow()->addClass('joinDatePanel');
$row->addLabel('joinedAfterDate', __('Joined After Date'))
    ->description(__('Only students whose Start Date is later than this date will be exported.'));
$row->addDate('joinedAfterDate');

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__('Export CSV'));

echo $form->getOutput();
