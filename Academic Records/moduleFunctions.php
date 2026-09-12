<?php
/**
 * Helpers shared by the module's pages.
 *
 * Only page fragments and request helpers live here. Database reads sit in
 * the gateways under src/Domain, the GL export in src/Testwise, the CAT4
 * importer in src/CAT4 and the settings backup in src/Backup.php, all of
 * which Gibbon autoloads.
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
use Gibbon\Module\AcademicRecords\Domain\StoreFilterGateway;

/* -----------------------------------------------------
   Shared page fragments

   These blocks used to be pasted into each page that needed them. One
   copy here means the wizards and the setup guards all look the same, and
   a change to one is a change to all of them.
----------------------------------------------------- */

/**
 * A warning box with a message on the left and one button on the right.
 *
 * Used as the setup guard on Store Grades and CAT4 Import, and as the
 * recommendation on the settings page.
 *
 * @param string $message     Already translated. May hold HTML.
 * @param string $buttonLabel Already translated.
 * @param string $url         Where the button goes.
 *
 * @return string
 */
function academicRecordsSetupWarning(string $message, string $buttonLabel, string $url): string
{
    return '<div class="warning flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">'
        . '<div class="flex-1">' . $message . '</div>'
        . '<div class="text-left sm:text-right sm:ml-auto">'
        . '<a class="rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 border border-amber-600 bg-white hover:bg-amber-50 text-amber-900 no-underline" href="'
        . htmlspecialchars($url) . '">'
        . $buttonLabel
        . '</a>'
        . '</div>'
        . '</div>';
}

/**
 * The Back link and the forward button at the foot of a wizard step.
 *
 * @param string $backURL    Where Back goes.
 * @param string $label      Forward button label, already translated.
 * @param bool   $enabled    False draws a greyed out button reading Failed.
 * @param array  $attributes Extra attributes for the forward button, such
 *                           as an id or an onclick. Values are not escaped,
 *                           so pass only what the page itself wrote.
 *
 * @return string
 */
function academicRecordsWizardNav(string $backURL, string $label, bool $enabled = true, array $attributes = []): string
{
    $attributeHtml = '';
    foreach ($attributes as $name => $value) {
        $attributeHtml .= ' ' . $name . '="' . $value . '"';
    }

    $forward = $enabled
        ? '<button type="submit"' . $attributeHtml . ' style="display:inline-flex; align-items:center; padding:8px 16px; border-radius:6px; border:1px solid #1f2937; background:#374151; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18); cursor:pointer;" onmouseover="this.style.backgroundColor=\'#1f2937\';" onmouseout="this.style.backgroundColor=\'#374151\';">'
            . $label
            . '</button>'
        : '<button type="button"' . $attributeHtml . ' disabled style="display:inline-flex; align-items:center; padding:8px 16px; border-radius:6px; border:1px solid #cbd5e1; background:#e2e8f0; color:#64748b; font-size:14px; font-weight:600; line-height:1.25; box-shadow:none; cursor:not-allowed;">'
            . __('Failed')
            . '</button>';

    return '<div style="width:100%; display:flex; justify-content:space-between; align-items:center; gap:16px; padding-top:8px;">'
        . '<a class="no-underline" style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:6px; border:1px solid #475569; background:#475569; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18);" onmouseover="this.style.backgroundColor=\'#334155\';this.style.borderColor=\'#334155\';" onmouseout="this.style.backgroundColor=\'#475569\';this.style.borderColor=\'#475569\';" href="' . htmlspecialchars($backURL) . '">'
        . '&#8592; ' . __('Back')
        . '</a>'
        . $forward
        . '</div>';
}

/**
 * A collapsed Data panel holding a JSON payload, for the dry run steps.
 *
 * @param string $json Pretty printed JSON. An empty string draws nothing.
 *
 * @return string
 */
function academicRecordsPayloadPanel(string $json): string
{
    if ($json === '') {
        return '';
    }

    return '<details class="w-full rounded border border-gray-400 bg-white">'
        . '<summary class="cursor-pointer select-none px-4 py-3 font-semibold text-gray-800">'
        . __('Data')
        . '</summary>'
        . '<div class="px-4 pb-4 pt-2">'
        . '<textarea readonly rows="16" cols="74" class="w-full" style="font-family: monospace;">'
        . htmlspecialchars($json)
        . '</textarea>'
        . '</div>'
        . '</details>';
}

/**
 * Carry a request forward as hidden fields, so the next wizard step gets
 * the same selection. The routing keys q and step are left out, because
 * each step sets its own.
 *
 * @param Form  $form   Form to add the fields to.
 * @param array $values Request values, usually $_GET or $_POST.
 *
 * @return void
 */
function academicRecordsCarryForward(Form $form, array $values): void
{
    foreach ($values as $key => $val) {
        if ($key === 'q' || $key === 'step') {
            continue;
        }

        if (is_array($val)) {
            foreach ($val as $v) {
                $form->addHiddenValue($key . (substr($key, -2) === '[]' ? '' : '[]'), (string) $v);
            }
        } else {
            $form->addHiddenValue($key, (string) $val);
        }
    }
}

/**
 * The classes Gibbon gives the controls inside its bulk action panel, so a
 * coloured row drawn by hand looks the same as the one on the class
 * enrolment page.
 *
 * @param string $themeColour The session's themeColour, such as purple.
 *
 * @return array Keys input, select, button and cell.
 */
function academicRecordsBulkBarClasses(string $themeColour): array
{
    return [
        'input' => 'rounded-md min-w-0 border py-2 px-2 placeholder:text-gray-500 sm:text-sm sm:leading-5 text-gray-900 focus:ring-1 focus:ring-inset focus:ring-blue-500',
        'select' => 'rounded-md min-w-16 border py-2 text-gray-900 placeholder:text-gray-500 focus:ring-1 focus:ring-inset focus:ring-blue-500 sm:text-sm sm:leading-5',
        'button' => 'rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle items-center font-semibold shadow-sm border border-gray-800 bg-gray-800 hover:bg-gray-900 text-white',
        'cell' => 'bg-' . htmlspecialchars($themeColour) . '-600 p-1 pt-2',
    ];
}

/**
 * The address to return to after a Generate Transcripts action, carrying
 * the same report and selection so the list shows the result.
 *
 * @param string $absoluteURL The site root.
 * @param array  $post        The request the action was posted with.
 *
 * @return string
 */
function transcriptsReturnURL(string $absoluteURL, array $post): string
{
    $url = $absoluteURL . '/index.php?q=/modules/Academic Records/academicRecords_transcripts.php';
    $url .= '&gibbonReportID=' . rawurlencode((string) ($post['gibbonReportID'] ?? ''));

    foreach (normalizeRequestList($post['gibbonYearGroupIDList'] ?? '') as $yearGroupID) {
        $url .= '&gibbonYearGroupIDList[]=' . rawurlencode($yearGroupID);
    }

    foreach (normalizeRequestList($post['studentIDs'] ?? '') as $studentID) {
        $url .= '&studentIDs[]=' . rawurlencode($studentID);
    }

    return $url;
}

/* -----------------------------------------------------
   Request helpers

   IDs arrive from a form or a query string without the leading zeros the
   database stores, and either as a list, an array or a single value.
----------------------------------------------------- */

/**
 * Pad a year group ID to the three digits the database stores.
 *
 * @param mixed $id Raw request value.
 *
 * @return string
 */
function normalizeYearGroupID($id): string
{
    $id = trim((string) $id);

    if (ctype_digit($id) && strlen($id) >= 3) {
        return $id;
    }

    if (ctype_digit($id)) {
        return str_pad($id, 3, '0', STR_PAD_LEFT);
    }

    return $id;
}

/**
 * Pad a school year term ID to the five digits the database stores.
 *
 * @param mixed $id Raw request value.
 *
 * @return string
 */
function normalizeSchoolYearTermID($id): string
{
    $id = trim((string) $id);

    if ($id === '' || !ctype_digit($id)) {
        return $id;
    }

    return strlen($id) >= 5 ? $id : str_pad($id, 5, '0', STR_PAD_LEFT);
}

/**
 * Read a request value that may arrive as an array, a list or a single value.
 *
 * @param mixed $value Raw request value.
 *
 * @return array Trimmed, non-empty strings.
 */
function normalizeRequestList($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $value), function ($item) {
            return $item !== '';
        }));
    }

    if ($value === null) {
        return [];
    }

    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    if (strpos($value, ',') !== false) {
        return array_values(array_filter(array_map('trim', explode(',', $value)), function ($item) {
            return $item !== '';
        }));
    }

    return [$value];
}

/* -----------------------------------------------------
   Selection widgets
----------------------------------------------------- */

/**
 * Source and destination lists for the student picker.
 *
 * @param array $students     Rows from StudentGateway::queryStudentsBySchoolYear().
 * @param array $yearGroupIDs Year groups to offer.
 * @param array $studentIDs   Students already chosen.
 *
 * @return array Keys source, destination and formGroups.
 */
function buildStudentSelectionData(array $students, array $yearGroupIDs, array $studentIDs = []): array
{
    $source = [];
    $dest = [];
    $formGroups = [];

    foreach ($students as $student) {
        if (!in_array($student['gibbonYearGroupID'], $yearGroupIDs)) {
            continue;
        }

        $label = Format::name('', $student['preferredName'], $student['surname'], 'Student', true)
            . ' - ' . $student['formGroup'];

        $personID = (string) $student['gibbonPersonID'];
        $formGroups[$personID] = $student['formGroup'];

        if (in_array($personID, array_map('strval', $studentIDs), true) || in_array((int) $personID, $studentIDs, true)) {
            $dest[$personID] = $label;
        } else {
            $source[$personID] = $label;
        }
    }

    return [
        'source' => $source,
        'destination' => $dest,
        'formGroups' => $formGroups,
    ];
}

/**
 * The criteria table on Store Grades step 1: one row per criteria type,
 * with a tick box for each value of its scale.
 *
 * @param StoreFilterGateway $filterGateway        Reads the scale values.
 * @param array              $criteriaTypes        From selectCriteriaTypesByCycle().
 * @param array              $criteriaTypeIDs      Types ticked. Empty ticks all.
 * @param array              $valueSelections      values_{typeID} lists from the request.
 * @param array              $valueSelectionsSaved valuesSaved_{typeID} lists from the request.
 *
 * @return string
 */
function renderCriteriaTableHtml(
    StoreFilterGateway $filterGateway,
    array $criteriaTypes,
    array $criteriaTypeIDs = [],
    array $valueSelections = [],
    array $valueSelectionsSaved = []
): string {
    if (empty($criteriaTypes)) {
        return '<div id="criteriaTableContainer"><div class="criteriaTable">'
            . '<div class="criteriaRow"><div class="criteriaCell criteriaCell-right">'
            . htmlspecialchars(__('No criteria are available for the current filter combination.'))
            . '</div></div></div></div>';
    }

    $html = '<div id="criteriaTableContainer"><div class="criteriaTable">';
    $html .= '<div class="criteriaHeaderRow">';
    $html .= '<div class="criteriaHeader-left"><strong>' . htmlspecialchars(__('Criteria')) . '</strong></div>';
    $html .= '<div class="criteriaHeader-right"><strong>' . htmlspecialchars(__('Values to store')) . '</strong></div>';
    $html .= '</div>';

    foreach ($criteriaTypes as $typeID => $typeData) {
        $typeID = (string) $typeID;
        $values = $filterGateway->selectScaleValues((int) $typeData['scaleID']);
        $blankLabel = $values['__BLANK__'] ?? '(Blank)';
        unset($values['__BLANK__']);
        $isChecked = empty($criteriaTypeIDs) || in_array($typeID, array_map('strval', $criteriaTypeIDs), true);

        $html .= '<div class="criteriaRow criteriaRow-tight">';
        $html .= '<div class="criteriaCell criteriaCell-left">';
        $html .= '<label>';
        $html .= '<input class="criteria-toggle" data-type="' . htmlspecialchars($typeID) . '" type="checkbox" name="criteriaTypeIDs[]" value="' . htmlspecialchars($typeID) . '"'
            . ($isChecked ? ' checked' : '') . '> ';
        $html .= htmlspecialchars($typeData['name']);
        $html .= '</label>';
        $html .= '</div>';

        $checkedValues =
            $valueSelections["values_{$typeID}"] ??
            $valueSelectionsSaved["valuesSaved_{$typeID}"] ??
            array_keys($values);

        $checkedValues = array_map('strval', $checkedValues);

        $html .= '<div class="criteriaCell criteriaCell-right">';
        $html .= '<div class="criteria-values criteria-values-' . htmlspecialchars($typeID) . '">';

        foreach ($values as $valueKey => $valueLabel) {
            $valueKey = (string) $valueKey;
            $isValueChecked = in_array($valueKey, $checkedValues, true);

            $html .= '<label>';
            $html .= '<input type="checkbox" name="values_' . htmlspecialchars($typeID) . '[]" value="' . htmlspecialchars($valueKey) . '"'
                . ($isValueChecked ? ' checked' : '')
                . ($isChecked ? '' : ' disabled')
                . '> ';
            $html .= htmlspecialchars($valueLabel);
            $html .= '</label>';
        }

        $isBlankChecked = in_array('__BLANK__', $checkedValues, true);
        $html .= '<label>';
        $html .= '<input type="checkbox" name="values_' . htmlspecialchars($typeID) . '[]" value="__BLANK__"'
            . ($isBlankChecked ? ' checked' : '')
            . ($isChecked ? '' : ' disabled')
            . '> ';
        $html .= htmlspecialchars($blankLabel);
        $html .= '</label>';

        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
    }

    $html .= '</div></div>';

    return $html;
}
