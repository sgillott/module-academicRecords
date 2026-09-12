<?php

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Testwise\Year;

require_once '../../gibbon.php';

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/' . getModuleName($_POST['address'] ?? '') . '/academicRecords_settings.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_settings.php')) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
}

$internalAssessmentType = trim($_POST['internalAssessmentType'] ?? '');
$viewableStudents = ($_POST['viewableStudents'] ?? '') === 'N' ? 'N' : 'Y';
$viewableParents  = ($_POST['viewableParents'] ?? '') === 'N' ? 'N' : 'Y';
$testwiseRegion = trim($_POST['testwiseRegion'] ?? '');

if (!array_key_exists($testwiseRegion, Year::regionOptions())) {
    $URL .= '&return=error3';
    header("Location: {$URL}");
    exit;
}

if ($internalAssessmentType === '') {
    $URL .= '&return=error3';
    header("Location: {$URL}");
    exit;
}

$settingGateway = $container->get(SettingGateway::class);

// Validate chosen type against Formal Assessment list
$formalTypesSetting = $settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes', true);
$allowed = [];
if (!empty($formalTypesSetting['value'])) {
    $allowed = array_map('trim', explode(',', $formalTypesSetting['value']));
}
if (!in_array($internalAssessmentType, $allowed, true)) {
    $URL .= '&return=error3';
    header("Location: {$URL}");
    exit;
}

$partialFail = false;
$partialFail = !$settingGateway->updateSettingByScope('Academic Records', 'internalAssessmentType', $internalAssessmentType) || $partialFail;
$partialFail = !$settingGateway->updateSettingByScope('Academic Records', 'viewableStudents', $viewableStudents) || $partialFail;
$partialFail = !$settingGateway->updateSettingByScope('Academic Records', 'viewableParents', $viewableParents) || $partialFail;
$partialFail = !$settingGateway->updateSettingByScope('Academic Records', 'testwiseRegion', $testwiseRegion) || $partialFail;

$URL .= $partialFail ? '&return=error2' : '&return=success0';
header("Location: {$URL}");
exit;