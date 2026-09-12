<?php
/**
 * Saves the Gibbon year group to Testwise year mappings.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Module\AcademicRecords\Domain\YearGroupMapGateway;
use Gibbon\Module\AcademicRecords\Testwise\Year;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$URL = $session->get('absoluteURL')
    . '/index.php?q=/modules/Academic Records/academicRecords_testwiseYearGroups.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_testwiseYearGroups.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

$submitted = $_POST['testwiseYear'] ?? [];

if (!is_array($submitted)) {
    header("Location: {$URL}&return=error1");
    exit;
}

$mapGateway = $container->get(YearGroupMapGateway::class);

// Only year groups that actually exist may be written, so a tampered field
// name cannot create a mapping row for an unknown ID.
$validYearGroupIDs = [];

foreach ($mapGateway->selectYearGroups() as $yearGroup) {
    $validYearGroupIDs[(string) $yearGroup['gibbonYearGroupID']] = true;
}

$partialFail = false;

foreach ($submitted as $yearGroupID => $rawValue) {
    $yearGroupID = (string) $yearGroupID;

    if (!isset($validYearGroupIDs[$yearGroupID])) {
        continue;
    }

    $value = Year::normaliseYear((string) $rawValue);

    // An empty value clears the mapping, which puts the year group back on the
    // region rule rather than leaving a stale value behind.
    if ($value === '') {
        $partialFail = !$mapGateway->deleteMapping($yearGroupID) || $partialFail;
        continue;
    }

    if (!Year::isValidYear($value)) {
        header("Location: {$URL}&return=error1");
        exit;
    }

    $partialFail = !$mapGateway->saveMapping($yearGroupID, $value) || $partialFail;
}

$URL .= $partialFail ? '&return=error2' : '&return=success0';
header("Location: {$URL}");
exit;
