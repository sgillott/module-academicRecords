<?php
/**
 * Opens the Reports archive for the transcript report.
 *
 * A sidebar action can only point at a file in its own module, so this page
 * exists to send the reader on to Reports > Archive by Report, with the
 * school year, the report and its name filled in. With one transcript report
 * it goes straight there. With more than one it lists them.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Services\Format;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_viewTranscripts.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$reports = $container->get(TranscriptGateway::class)->selectTranscriptReports($gibbonSchoolYearID);

$archiveURL = function (array $report) use ($session, $gibbonSchoolYearID) {
    return $session->get('absoluteURL') . '/index.php?' . http_build_query([
        'q' => '/modules/Reports/archive_byReport_view.php',
        'gibbonSchoolYearID' => $gibbonSchoolYearID,
        'gibbonReportID' => (string) $report['gibbonReportID'],
        'reportIdentifier' => (string) $report['name'],
    ]);
};

if (count($reports) === 1) {
    header('Location: ' . $archiveURL($reports[0]));
    exit;
}

$page->breadcrumbs->add(__('View Transcripts'));
echo '<h2>' . __('View Transcripts') . '</h2>';

if (empty($reports)) {
    echo Format::alert(
        __('There are no transcript reports for this school year yet. A transcript is defined in the Reports module, as an active report using a template built on the Student Enrolment context.'),
        'warning'
    );
    return;
}

echo '<ul>';
foreach ($reports as $report) {
    echo '<li><a href="' . htmlspecialchars($archiveURL($report)) . '">' . htmlspecialchars((string) $report['name']) . '</a></li>';
}
echo '</ul>';
