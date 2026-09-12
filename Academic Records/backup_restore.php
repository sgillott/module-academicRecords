<?php

use Gibbon\Forms\Form;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/backup_restore.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Backup and Restore Settings'));

$exportForm = Form::create(
    'settings_export',
    $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/backup_restoreProcess.php?action=export'
);
$exportForm->addHiddenValue('address', $session->get('address'));

$row = $exportForm->addRow();
$row->addHeading(__('Export Settings'));

$row = $exportForm->addRow();
$row->addContent(__('Download a JSON backup of Academic Records settings, CAT4 mapping data, Testwise year group mappings, transcript grade setup and course credits.'));

$row = $exportForm->addRow();
$row->addContent(__('This backup includes module `gibbonSetting` rows, which cover the Testwise Region and the transcript options, plus the CAT4 mapping tables, the Testwise year group mappings, the Grade Setup of each scale and the credit and transcript switch of each course. It does not include permissions, hooks, student exports, transcript overrides per student, or stored academic record data.'));

$row = $exportForm->addRow();
$row->addFooter();
$row->addSubmit(__('Download Backup'));

echo $exportForm->getOutput();

$importForm = Form::create(
    'settings_import',
    $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/backup_restoreProcess.php?action=import'
);
$importForm->addHiddenValue('address', $session->get('address'));

$row = $importForm->addRow();
$row->addHeading(__('Restore Settings'));

$row = $importForm->addRow();
$row->addContent(__('Upload a previously exported JSON backup to restore Academic Records settings, CAT4 mappings, Testwise year group mappings, grade setup and course credits after reinstalling the module or moving configuration between environments.'));

$row = $importForm->addRow();
$row->addContent(__('Restore updates existing module settings and replaces the CAT4 mapping rows, Testwise year group mappings, grade setup and course credits in this module with the contents of the backup file.'));

$row = $importForm->addRow();
$row->addContent(__('Only the parts the file holds are restored. A backup from a school that never set up CAT4 or Testwise year groups restores its settings and leaves those tables alone, rather than being rejected.'));

$row = $importForm->addRow();
$row->addContent(__('Year group mappings, grade setup and course credits are matched on names, not IDs, so a backup can be restored onto another Gibbon install. A year group, scale or course in the backup that does not exist here is skipped.'));

$row = $importForm->addRow();
$row->addLabel('settingsBackupFile', __('Backup File'))
    ->description(__('Accepted format: JSON exported by this module.'));
$row->addFileUpload('settingsBackupFile')->required()->accepts(['.json']);

$row = $importForm->addRow();
$row->addFooter();
$row->addSubmit(__('Restore Backup'));

echo $importForm->getOutput();
