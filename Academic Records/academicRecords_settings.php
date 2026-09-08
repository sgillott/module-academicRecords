<?php

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Academic Records/academicRecords_settings.php')) {
    $page->addError(__('You do not have access to this action.'));
    return;
}

$page->breadcrumbs->add(__('Academic Records Settings'));
echo '<h2>' . __('Academic Records Settings') . '</h2>';

$settingGateway = $container->get(SettingGateway::class);

// Fetch the Formal Assessment types list from gibbonSetting
$formalTypesSetting = $settingGateway->getSettingByScope('Formal Assessment', 'internalAssessmentTypes', true);
$typeOptions = [];
$hasRecommendedFinalGradeType = false;

if (!empty($formalTypesSetting['value'])) {
    $types = array_map('trim', explode(',', $formalTypesSetting['value']));
    foreach ($types as $t) {
        if ($t !== '') {
            $typeOptions[$t] = $t;
            if (strcasecmp($t, 'Final Grade') === 0) {
                $hasRecommendedFinalGradeType = true;
            }
        }
    }
}

if (empty($typeOptions)) {
    $page->addError(__('No Internal Assessment Types were found in Formal Assessment Settings. Please add at least one type.'));
    echo '<p>' . __('Go to School Admin → Formal Assessment Settings and add an Internal Assessment Type (for example, "Final Grade").') . '</p>';
    return;
}

// Current module settings
$internalAssessmentType = $settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType', true)['value'] ?? '';
$viewableStudents = $settingGateway->getSettingByScope('Academic Records', 'viewableStudents', true)['value'] ?? 'Y';
$viewableParents  = $settingGateway->getSettingByScope('Academic Records', 'viewableParents', true)['value'] ?? 'Y';

// The region has no meaningful default until a user confirms it, so seed the
// field from Gibbon's country the first time the page is opened.
$gibbonCountry = (string) ($settingGateway->getSettingByScope('System', 'country', true)['value'] ?? '');
$testwiseRegion = trim((string) ($settingGateway->getSettingByScope('Academic Records', 'testwiseRegion', true)['value'] ?? ''));

if ($testwiseRegion === '') {
    $testwiseRegion = testwiseDefaultRegion($gibbonCountry);
}

$form = Form::create(
    'academicRecordsSettings',
    $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_settingsProcess.php'
);
$form->addHiddenValue('address', $session->get('address'));

$form->addRow()->addHeading(__('Internal Assessment Storage'));

if (!$hasRecommendedFinalGradeType) {
    $formalAssessmentSettingsURL = $session->get('absoluteURL')
        . '/index.php?q=%2Fmodules%2FSchool+Admin%2FformalAssessmentSettings.php';

    $form->addRow()->addContent(
        '<div class="warning flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">'
        . '<div class="flex-1">'
        . __('It is recommended that you create an Internal Assessment Type called \'Final Grade\'.')
        . '</div>'
        . '<div class="text-left sm:text-right sm:ml-auto">'
        . '<a class="rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 border border-amber-600 bg-white hover:bg-amber-50 text-amber-900 no-underline" href="' . htmlspecialchars($formalAssessmentSettingsURL) . '">'
        . __('Open Formal Assessment Settings')
        . '</a>'
        . '</div>'
        . '</div>'
    );
}

$row = $form->addRow();
$row->addLabel('internalAssessmentType', __('Internal Assessment Type'))
    ->description(__('This type will be used for all stored grade columns created by this module. Choose a stable type and do not change it later.'));
$row->addSelect('internalAssessmentType')
    ->fromArray($typeOptions)
    ->required()
    ->selected($internalAssessmentType)
    ->placeholder();

$row = $form->addRow();
$row->addLabel('viewableStudents', __('Viewable by Students'));
$row->addYesNo('viewableStudents')->required()->selected($viewableStudents);

$row = $form->addRow();
$row->addLabel('viewableParents', __('Viewable by Parents'));
$row->addYesNo('viewableParents')->required()->selected($viewableParents);

$form->addRow()->addHeading(__('GL Assessment Export'));

$form->addRow()->addContent(
    Format::alert(
        __('Gibbon records a country but has a single "United Kingdom" entry, so it cannot tell England, Scotland and Northern Ireland apart. Set the region here. It decides the year group prefix used by the student export. Gibbon\'s country is currently set to {country}.', ['country' => $gibbonCountry !== '' ? $gibbonCountry : __('not set')]),
        'message'
    )
);

$row = $form->addRow();
$row->addLabel('testwiseRegion', __('Testwise Region'))
    ->description(__('England uses a "Y" prefix, Scotland uses "P" and "S", Northern Ireland uses "P" and "Y", and Republic of Ireland uses "Y".'));
$row->addSelect('testwiseRegion')
    ->fromArray(testwiseRegionOptions())
    ->required()
    ->selected($testwiseRegion);

$row = $form->addRow();
$row->addFooter();
$row->addSubmit(__('Save Settings'));

echo $form->getOutput();
