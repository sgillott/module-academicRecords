<?php
/**
 * Copies every course credit of one school year to the next.
 *
 * Courses are matched by short name, the same way a copied course keeps its
 * short name when Manage Courses copies it forward. The page lands on the
 * next year afterwards, so the result is in view.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Module\AcademicRecords\Domain\CourseCreditGateway;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$gibbonSchoolYearID = (string) ($_GET['gibbonSchoolYearID'] ?? '');
$gibbonSchoolYearIDNext = (string) ($_GET['gibbonSchoolYearIDNext'] ?? '');

$URL = $session->get('absoluteURL')
    . '/index.php?q=/modules/Academic Records/academicRecords_courseCredits.php'
    . '&gibbonSchoolYearID=' . rawurlencode($gibbonSchoolYearIDNext !== '' ? $gibbonSchoolYearIDNext : $gibbonSchoolYearID);

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_courseCredits.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

if ($gibbonSchoolYearID === '' || $gibbonSchoolYearIDNext === '' || $gibbonSchoolYearID === $gibbonSchoolYearIDNext) {
    header("Location: {$URL}&return=error1");
    exit;
}

$counts = $container->get(CourseCreditGateway::class)->copyToSchoolYear(
    $gibbonSchoolYearID,
    $gibbonSchoolYearIDNext,
    (int) $session->get('gibbonPersonID')
);

$URL .= '&copied=' . $counts['copied'] . '&unmatched=' . $counts['unmatched'];

if ($counts['failed'] > 0) {
    header("Location: {$URL}&return=error2");
    exit;
}

header("Location: {$URL}&return=success1");
exit;
