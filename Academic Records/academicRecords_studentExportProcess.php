<?php

use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\YearGroupMapGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_studentExport.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_studentExport.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

$filterByJoinDate = ($_POST['filterByJoinDate'] ?? 'N') === 'Y';
$joinedAfterDate = trim((string) ($_POST['joinedAfterDate'] ?? ''));
$normalizedDate = null;

if ($filterByJoinDate) {
    if ($joinedAfterDate === '') {
        header("Location: {$URL}&return=error1");
        exit;
    }

    $normalizedDate = Format::dateConvert($joinedAfterDate);

    if (empty($normalizedDate)) {
        try {
            $normalizedDate = (new DateTimeImmutable($joinedAfterDate))->format('Y-m-d');
        } catch (Throwable $e) {
            header("Location: {$URL}&return=error1");
            exit;
        }
    }

    if (empty($normalizedDate)) {
        header("Location: {$URL}&return=error1");
        exit;
    }
}

$settingGateway = $container->get(SettingGateway::class);
$region = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'testwiseRegion', true)['value'] ?? ''));

if ($region === '') {
    header("Location: {$URL}&return=error1");
    exit;
}

$mapGateway = $container->get(YearGroupMapGateway::class);
$yearMap = buildTestwiseYearMap($region, $mapGateway->selectYearGroups(), $mapGateway->selectMapKeyed());

$headers = getStudentDataExportHeaders();
$rows = getStudentDataExportRows($connection2, $normalizedDate, $yearMap);
$filename = $normalizedDate !== null
    ? 'student_export_' . str_replace('-', '', $normalizedDate) . '.csv'
    : 'student_export_all_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

if ($output === false) {
    header("Location: {$URL}&return=error2");
    exit;
}

fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $headers);

foreach ($rows as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
