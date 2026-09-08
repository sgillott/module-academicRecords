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
$row->addContent(__('Download a JSON backup of Academic Records settings, CAT4 mapping data and Testwise year group mappings.'));

$row = $exportForm->addRow();
$row->addContent(__('This backup includes module `gibbonSetting` rows, which cover the Testwise Region, plus the CAT4 mapping tables and the Testwise year group mappings. It does not include permissions, hooks, student exports, or stored academic record data.'));

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
$row->addContent(__('Upload a previously exported JSON backup to restore Academic Records settings, CAT4 mappings and Testwise year group mappings after reinstalling the module or moving configuration between environments.'));

$row = $importForm->addRow();
$row->addContent(__('Restore updates existing module settings and replaces the CAT4 mapping rows and Testwise year group mappings in this module with the contents of the backup file.'));

$row = $importForm->addRow();
$row->addContent(__('Only the parts the file holds are restored. A backup from a school that never set up CAT4 or Testwise year groups restores its settings and leaves those tables alone, rather than being rejected.'));

$row = $importForm->addRow();
$row->addContent(__('Year group mappings are matched on the year group name, then its short name, so a backup can be restored onto another Gibbon install. A year group in the backup that does not exist here is skipped.'));

$row = $importForm->addRow();
$row->addLabel('settingsBackupFile', __('Backup File'))
    ->description(__('Accepted format: JSON exported by this module.'));
$row->addFileUpload('settingsBackupFile')->required()->accepts(['.json']);

$row = $importForm->addRow();
$row->addFooter();
$row->addSubmit(__('Restore Backup'));

echo $importForm->getOutput();
