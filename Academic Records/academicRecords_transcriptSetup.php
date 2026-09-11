<?php
/**
 * Configures the values transcripts are built from.
 *
 * Three sections, in the order they matter:
 *
 *   Year Groups     which years of school a transcript covers.
 *   Grade to Credit how much of a course credit each grade earns.
 *   Stored Grade Terms  grades stored before the term was recorded at source.
 *
 * Nothing here is a school rule written into the code. Another school sets
 * different year groups, a different scale and a different credit share.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\CourseCreditGateway;
use Gibbon\Module\AcademicRecords\Domain\StoredGradeGateway;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_transcriptSetup.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Transcript Setup'));

$settingGateway = $container->get(SettingGateway::class);
$storedGradeGateway = $container->get(StoredGradeGateway::class);
$transcriptGateway = $container->get(TranscriptGateway::class);
$creditGateway = $container->get(CourseCreditGateway::class);

$processURL = $session->get('absoluteURL') . '/modules/Academic Records/academicRecords_transcriptSetupProcess.php';

/* -----------------------------------------------------
   Year Groups
----------------------------------------------------- */

echo '<h2>' . __('Transcript Options') . '</h2>';

$yearGroupSetting = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'transcriptYearGroups', true)['value'] ?? ''));
$chosenYearGroups = normalizeRequestList($yearGroupSetting);
$gpaMethod = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'transcriptGpaMethod', true)['value'] ?? ''));

$yearGroupOptions = [];
foreach ($transcriptGateway->selectYearGroupOptions() as $yearGroup) {
    $yearGroupOptions[(string) $yearGroup['gibbonYearGroupID']] = (string) $yearGroup['name'];
}

if (empty($chosenYearGroups)) {
    echo Format::alert(
        __('No year groups are chosen, so a transcript covers every year a student has been in. Choose the year groups a transcript should cover, such as Grade 9 to Grade 12.'),
        'warning'
    );
}

echo Format::alert(
    __('A school year appears on a transcript only when the student was in one of these year groups that year, and was in at least one class. This is what keeps the earlier years of school off a high school transcript.'),
    'message'
);

$yearGroupForm = Form::create('transcriptYearGroups', $processURL);
$yearGroupForm->addHiddenValue('address', $session->get('address'));
$yearGroupForm->addHiddenValue('action', 'options');

$row = $yearGroupForm->addRow();
$row->addLabel('transcriptYearGroups', __('Year Groups'));
$row->addCheckbox('transcriptYearGroups[]')
    ->fromArray($yearGroupOptions)
    ->checked($chosenYearGroups)
    ->addCheckAllNone();

$row = $yearGroupForm->addRow();
$row->addLabel('transcriptGpaMethod', __('Overall GPA'))
    ->description(__('Credit weighted counts each grade in proportion to the credit it earned, which is the usual American convention. Simple mean counts every graded term once. GPA is only printed for students switched on for it on the Generate Transcripts page.'));
$row->addSelect('transcriptGpaMethod')
    ->fromArray([
        'creditWeighted' => __('Credit weighted'),
        'simpleMean' => __('Simple mean'),
    ])
    ->selected($gpaMethod !== '' ? $gpaMethod : 'creditWeighted');

$row = $yearGroupForm->addRow();
$row->addFooter();
$row->addSubmit(__('Save Options'));

echo $yearGroupForm->getOutput();

/* -----------------------------------------------------
   Grade to Credit
----------------------------------------------------- */

echo '<h2>' . __('Grade Setup') . '</h2>';

// Scales that stored grades actually sit on come first, because they are the
// only ones a transcript can read. Everything else is available but secondary.
$scalesInUse = $creditGateway->selectScalesInUse();

$inUseOptions = [];
foreach ($scalesInUse as $scale) {
    $inUseOptions[(string) $scale['gibbonScaleID']] = (string) $scale['name'];
}

$otherOptions = [];
foreach ($creditGateway->selectActiveScales() as $scale) {
    $id = (string) $scale['gibbonScaleID'];

    if (!isset($inUseOptions[$id])) {
        $otherOptions[$id] = (string) $scale['name'];
    }
}

$scaleOptions = $inUseOptions + $otherOptions;

$scaleID = (string) ($_GET['gibbonScaleID'] ?? '');

if (!isset($scaleOptions[$scaleID])) {
    // Default to a scale grades are stored on, never to whichever scale
    // happens to sort first in the school's list.
    $scaleID = (string) (array_key_first($inUseOptions) ?? array_key_first($scaleOptions));
}

if (empty($scaleOptions)) {
    echo Format::alert(__('There are no active grade scales.'), 'error');
} else {
    echo Format::alert(
        '<b>' . __('Credit Share') . '</b> '
        . __('is how much of the credit a course carries that this grade earns. Use 1 for all of it, 0 for none, and 0.5 for half. A grade left empty earns nothing.'),
        'message'
    );

    echo Format::alert(
        '<b>' . __('Percentage From and To') . '</b> '
        . __('is the mark range that reaches this grade. It is used while a term is still being taught, to turn the Markbook average into a grade, so a transcript printed part way through the year carries current information. It also fills the Academic Grades box on the transcript. Leave the range empty and no grade is worked out for that band.'),
        'message'
    );

    echo Format::alert(
        '<b>' . __('Grade Points and Letter Grade') . '</b> '
        . __('is what this grade is worth on the American four point scale, such as 4.0 and A. The grade points are averaged into the GPA. Only students switched on for GPA have either printed. A grade with no points set is left out of the GPA.'),
        'message'
    );

    if (empty($inUseOptions)) {
        echo Format::alert(
            __('No grades have been stored yet, so no scale is in use. Anything set here waits until Store Grades has been run.'),
            'warning'
        );
    }

    $scaleForm = Form::create('gradeCreditScale', $session->get('absoluteURL') . '/index.php', 'get');
    $scaleForm->addHiddenValue('q', '/modules/Academic Records/academicRecords_transcriptSetup.php');

    $groupedOptions = [];

    if (!empty($inUseOptions)) {
        $groupedOptions[__('Used by stored grades')] = $inUseOptions;
    }

    if (!empty($otherOptions)) {
        $groupedOptions[__('Other scales')] = $otherOptions;
    }

    $row = $scaleForm->addRow();
    $row->addLabel('gibbonScaleID', __('Grade Scale'))
        ->description(__('Stored grades keep the scale they were recorded on, so set a share for every scale a transcript uses.'));
    $row->addSelect('gibbonScaleID')
        ->fromArray($groupedOptions)
        ->selected($scaleID);

    $row = $scaleForm->addRow();
    $row->addFooter();
    $row->addSubmit(__('Show Grades'));

    echo $scaleForm->getOutput();

    // Changing the scale reloads at once, so the table below can never
    // disagree with the name shown in the box.
    echo "<script>
        (function () {
            var select = document.querySelector('form#gradeCreditScale select[name=\"gibbonScaleID\"]');
            if (select) {
                select.addEventListener('change', function () { select.form.submit(); });
            }
        })();
    </script>";

    $grades = $creditGateway->selectGradesByScale($scaleID);

    // The heading carries whichever scale is chosen above, so it reads
    // correctly at any school.
    $scaleName = $scaleOptions[$scaleID] ?? '';
    echo '<h4>' . __('Grades on {name}', ['name' => htmlspecialchars($scaleName)]) . '</h4>';

    if (empty($grades)) {
        echo Format::alert(__('This scale has no grades.'), 'error');
    } else {
        $creditForm = Form::create('gradeCredit', $processURL);
        $creditForm->addHiddenValue('address', $session->get('address'));
        $creditForm->addHiddenValue('action', 'gradeCredit');
        $creditForm->addHiddenValue('gibbonScaleID', $scaleID);

        $trim = function ($number) {
            return $number !== null ? rtrim(rtrim((string) $number, '0'), '.') : '';
        };

        $html = '<table class="w-full colorOddEven" cellspacing="0">';
        $html .= '<tr class="head">';
        $html .= '<th>' . __('Grade') . '</th>';
        $html .= '<th>' . __('Descriptor') . '</th>';
        $html .= '<th>' . __('Credit Share') . '</th>';
        $html .= '<th>' . __('Percentage From') . '</th>';
        $html .= '<th>' . __('Percentage To') . '</th>';
        $html .= '<th>' . __('Grade Points') . '</th>';
        $html .= '<th>' . __('Letter Grade') . '</th>';
        $html .= '</tr>';

        foreach ($grades as $grade) {
            $value = (string) $grade['value'];
            $key = htmlspecialchars($value);

            $html .= '<tr>';
            $html .= '<td>' . $key . '</td>';
            $html .= '<td>' . htmlspecialchars((string) $grade['descriptor']) . '</td>';
            $html .= '<td><input type="number" class="w-20" min="0" max="1" step="0.05" autocomplete="off" placeholder="' . __('None') . '"'
                . ' name="creditFactor[' . $key . ']"'
                . ' value="' . htmlspecialchars($trim($grade['creditFactor'])) . '"/></td>';
            $html .= '<td><input type="number" class="w-20" min="0" max="100" step="0.5" autocomplete="off" placeholder="' . __('None') . '"'
                . ' name="percentMin[' . $key . ']"'
                . ' value="' . htmlspecialchars($trim($grade['percentMin'])) . '"/></td>';
            $html .= '<td><input type="number" class="w-20" min="0" max="100" step="0.5" autocomplete="off" placeholder="' . __('None') . '"'
                . ' name="percentMax[' . $key . ']"'
                . ' value="' . htmlspecialchars($trim($grade['percentMax'])) . '"/></td>';
            $html .= '<td><input type="number" class="w-20" min="0" max="9.99" step="0.1" autocomplete="off" placeholder="' . __('None') . '"'
                . ' name="gpaPoints[' . $key . ']"'
                . ' value="' . htmlspecialchars($trim($grade['gpaPoints'])) . '"/></td>';
            $html .= '<td><input type="text" class="w-16 uppercase" maxlength="4" autocomplete="off"'
                . ' name="gpaLetter[' . $key . ']"'
                . ' value="' . htmlspecialchars((string) ($grade['gpaLetter'] ?? '')) . '"/></td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        $creditForm->addRow()->addContent($html);

        $creditForm->addRow()->addContent(
            '<span class="text-xs italic">'
            . __('Ranges must not overlap. Where they do, the highest grade whose range contains the mark is the one used.')
            . '</span>'
        );

        $row = $creditForm->addRow();
        $row->addFooter();
        $row->addSubmit(__('Save Grade Setup'));

        echo $creditForm->getOutput();
    }
}

/* -----------------------------------------------------
   Stored Grade Terms
----------------------------------------------------- */

echo '<h2>' . __('Stored Grade Terms') . '</h2>';

$internalAssessmentType = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType', true)['value'] ?? ''));

if ($internalAssessmentType === '') {
    $settingsURL = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_settings.php';

    echo Format::alert(
        __('No Internal Assessment Type is set yet. Set it in Academic Records Settings first, so this page knows which columns this module owns.'),
        'error'
    );
    echo '<p><a href="' . htmlspecialchars($settingsURL) . '">' . __('Open Academic Records Settings') . '</a></p>';
    return;
}

$columns = $storedGradeGateway->selectUnindexedColumns($internalAssessmentType);

if (empty($columns)) {
    echo Format::alert(
        __('Every stored grade column carries a term. Nothing needs attention here.'),
        'success'
    );
    return;
}

echo Format::alert(
    __('These stored grade columns carry no term, so a transcript cannot place them. Each was stored before the term was recorded at source. Check the suggestion on every row and save.'),
    'warning'
);

echo Format::alert(
    __('The suggestion is worked out from the completion date of each column. A column completed after its term closed is suggested against the later term, which is often wrong, so read the dates before you save.'),
    'message'
);

$form = Form::create('transcriptSetup', $processURL);
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('action', 'storedGradeTerms');

// Terms are read once per school year, because many columns share a year.
$termsByYear = [];

$html = '<table class="w-full colorOddEven" cellspacing="0">';
$html .= '<tr class="head">';
$html .= '<th>' . __('School Year') . '</th>';
$html .= '<th>' . __('Column') . '</th>';
$html .= '<th>' . __('Class') . '</th>';
$html .= '<th>' . __('Completed') . '</th>';
$html .= '<th>' . __('Term') . '</th>';
$html .= '</tr>';

$skipped = 0;

foreach ($columns as $column) {
    $columnID = (string) $column['gibbonInternalAssessmentColumnID'];
    $schoolYearID = (string) $column['gibbonSchoolYearID'];
    $completeDate = (string) ($column['completeDate'] ?? '');

    if (!isset($termsByYear[$schoolYearID])) {
        $termsByYear[$schoolYearID] = $storedGradeGateway->selectTermsBySchoolYear($schoolYearID);
    }

    $terms = $termsByYear[$schoolYearID];

    if (empty($terms)) {
        $skipped++;
        continue;
    }

    $suggested = $storedGradeGateway->suggestTermForDate($schoolYearID, $completeDate);

    $options = '';
    foreach ($terms as $term) {
        $termID = (string) $term['gibbonSchoolYearTermID'];
        $selected = $termID === $suggested ? ' selected' : '';
        $options .= '<option value="' . htmlspecialchars($termID) . '"' . $selected . '>'
            . htmlspecialchars((string) $term['name'])
            . '</option>';
    }

    $html .= '<tr>';
    $html .= '<td>' . htmlspecialchars((string) $column['schoolYearName']) . '</td>';
    $html .= '<td>' . htmlspecialchars((string) $column['name']) . '</td>';
    $html .= '<td>' . htmlspecialchars((string) $column['courseName'] . ' - ' . (string) $column['className']) . '</td>';
    $html .= '<td>' . ($completeDate !== '' ? Format::date($completeDate) : '') . '</td>';
    $html .= '<td><select class="w-40" name="termID[' . htmlspecialchars($columnID) . ']">'
        . '<option value="">' . __('Skip') . '</option>'
        . $options
        . '</select></td>';
    $html .= '</tr>';
}

$html .= '</table>';

$form->addRow()->addContent($html);

if ($skipped > 0) {
    $form->addRow()->addContent(
        Format::alert(
            __('{count} columns are not listed, because their school year has no terms. Add the terms for those years and return here.', ['count' => $skipped]),
            'warning'
        )
    );
}

$form->addRow()->addContent(
    '<span class="text-xs italic">'
    . __('A row left on Skip is not written, and stays on this list.')
    . '</span>'
);

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__('Save Terms'));

echo $form->getOutput();
