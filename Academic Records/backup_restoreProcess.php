<?php
/**
 * Downloads a settings backup, or restores one.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Module\AcademicRecords\Backup;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/' . getModuleName($_POST['address'] ?? '') . '/backup_restore.php';
$action = trim((string) ($_GET['action'] ?? ''));

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/backup_restore.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

$backupService = $container->get(Backup::class);

if ($action === 'export') {
    $filename = 'academic-records-settings-' . date('Ymd-His') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($backupService->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action !== 'import' || empty($_FILES['settingsBackupFile']['tmp_name'])) {
    header("Location: {$URL}&return=error1");
    exit;
}

$raw = file_get_contents($_FILES['settingsBackupFile']['tmp_name']);

if ($raw === false || trim($raw) === '') {
    header("Location: {$URL}&return=error1");
    exit;
}

$backup = Backup::validate(json_decode($raw, true));

if ($backup === null) {
    header("Location: {$URL}&return=error1");
    exit;
}

$success = $backupService->restore($backup, (int) ($session->get('gibbonPersonID') ?? 0));

header("Location: {$URL}&return=" . ($success ? 'success0' : 'error2'));
exit;
