<?php

use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Services\Format;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\AcademicRecords\Domain\StoredGradeGateway;

require_once __DIR__ . '/moduleFunctions.php';

if (!isActionAccessible($guid, $connection2, "/modules/Academic Records/academicRecords_store.php")) {
    $page->addError(__('You do not have access.'));
    return;
}

$settingGateway = $container->get(SettingGateway::class);
$internalAssessmentTypeSetting = $settingGateway->getSettingByScope('Academic Records', 'internalAssessmentType', true);
$internalAssessmentType = trim((string) ($internalAssessmentTypeSetting['value'] ?? ''));

if ($internalAssessmentType === '') {
    $settingsURL = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_settings.php';

    $page->addError(__('Store Grades cannot continue until Academic Records Settings have been configured.'));

    echo '<h2>' . __('Module Setup Required') . '</h2>';
    echo '<div class="warning flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">';
    echo '<div class="flex-1">';
    echo __('Before storing grades, you must choose the Internal Assessment Type in Academic Records Settings.<br />');
    echo __('Open Academic Records Settings, choose an Internal Assessment Type, save the settings, and then return to this page.');
    echo '</div>';
    echo '<div class="text-left sm:text-right sm:ml-auto">';
    echo '<a class="rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 border border-amber-600 bg-white hover:bg-amber-50 text-amber-900 no-underline" href="'
        . htmlspecialchars($settingsURL) . '">'
        . __('Go to Academic Records Settings')
        . '</a>';
    echo '</div>';
    echo '</div>';
    return;
}

/* -----------------------------------------------------
   Step Setup
----------------------------------------------------- */

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$step = max(1, min(4, $step));

$steps = [
    1 => __('Select Grades to store'),
    2 => __('Preview'),
    3 => __('Dry Run'),
    4 => __('Store Grades'),
];

$page->breadcrumbs->add(__('Store Grades'));
$page->breadcrumbs->add(__('Step {number}', ['number' => $step]) . ' - ' . $steps[$step]);

$page->stylesheets->add('module-academicRecords', 'modules/Academic Records/css/module.css');

/* -----------------------------------------------------
   Multi step header UI
----------------------------------------------------- */

echo "<ul class='multiPartForm'>";
printf("<li class='step %s'>%s</li>", ($step >= 1) ? "active" : "", $steps[1]);
printf("<li class='step %s'>%s</li>", ($step >= 2) ? "active" : "", $steps[2]);
printf("<li class='step %s'>%s</li>", ($step >= 3) ? "active" : "", $steps[3]);
printf("<li class='step %s'>%s</li>", ($step >= 4) ? "active" : "", $steps[4]);
echo "</ul>";

echo '<h2>';
echo __('Step {number}', ['number' => $step]) . ' - ' . $steps[$step];
echo '</h2>';

/* -----------------------------------------------------
   Helpers
----------------------------------------------------- */

function buildQueryStringForStep(array $params, int $step): string
{
    $params['step'] = $step;
    return http_build_query($params);
}

/**
 * Used to carry forward selection sets.
 */
function flattenRequestArray(array $data): array
{
    $out = [];
    foreach ($data as $k => $v) {
        $out[$k] = $v;
    }
    return $out;
}

function renderStoreExecutionResults($page, array $results): void
{
    echo $page->fetchFromTemplate('importer.twig.html', $results);

    echo '<table class="smallIntBorder" cellspacing="0" style="margin: 0 auto; width: 60%;">';
    echo '<tr><td class="right" width="50%">' . __('Internal Assessment Columns to Create') . ':</td><td>'
        . (int) ($results['columnsToCreate'] ?? 0) . '</td></tr>';
    echo '<tr><td class="right">' . __('Database Rows with No Change') . ':</td><td>'
        . (int) ($results['noChange'] ?? 0) . '</td></tr>';
    echo '</table><br/>';
}

function buildStorePayloadPreview(array $result): string
{
    $payload = [
        'transferFormat' => $result['payload']['transferFormat'] ?? 'Unknown',
        'warnings' => $result['payload']['warnings'] ?? [],
        'filtersReceived' => $result['payload']['filtersReceived'] ?? [],
        'eligibleReportingRows' => array_map(function ($row) {
            return [
                'student' => $row['studentName'] ?? '',
                'course' => $row['courseName'] ?? '',
                'class' => $row['className'] ?? '',
                'criteriaType' => $row['criteriaTypeName'] ?? '',
                'criteria' => $row['criteriaName'] ?? '',
                'value' => $row['value'] ?? '',
                'sourceDate' => isset($row['timestampModified']) && !empty($row['timestampModified'])
                    ? substr((string) $row['timestampModified'], 0, 10)
                    : (isset($row['timestampCreated']) && !empty($row['timestampCreated'])
                        ? substr((string) $row['timestampCreated'], 0, 10)
                        : null),
            ];
        }, $result['payload']['eligibleReportingRows'] ?? []),
        'storagePlan' => $result['payload']['storagePlan'] ?? [],
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT);
    return $json === false ? '' : $json;
}

function renderStoreStep1AjaxScript(): void
{
    echo <<<'HTML'
<script>
function initAcademicRecordsStoreStep1() {
  const form = document.querySelector('form#filters');
  const status = document.querySelector('#storeAjaxStatus');

  if (!form || !status) {
    return;
  }

  if (form.dataset.storeAjaxBound === '1') {
    setStatus('AJAX ready. Waiting for filter changes.', 'idle');
    return;
  }

  form.dataset.storeAjaxBound = '1';

  const ajaxUrl = form.dataset.storeAjaxUrl || '';
  let refreshTimer = null;
  let refreshRequest = 0;
  let lastStudentSelectionKey = '';
  const loaderMap = {};

  function ensureLoader(target, key) {
    if (!target) return null;
    if (loaderMap[key]) return loaderMap[key];

    const loader = document.createElement('span');
    loader.className = 'store-filter-loader';
    loader.setAttribute('aria-hidden', 'true');
    loader.innerHTML = '<svg viewBox="0 0 122.61 122.88" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M111.9 61.57a5.36 5.36 0 0 1 10.71 0A61.3 61.3 0 0 1 17.54 104.48v12.35a5.36 5.36 0 0 1-10.72 0V89.31A5.36 5.36 0 0 1 12.18 84H40a5.36 5.36 0 1 1 0 10.71H23a50.6 50.6 0 0 0 88.87-33.1ZM106.6 5.36a5.36 5.36 0 1 1 10.71 0V33.14A5.36 5.36 0 0 1 112 38.49H84.44a5.36 5.36 0 1 1 0-10.71H99A50.6 50.6 0 0 0 10.71 61.57 5.36 5.36 0 1 1 0 61.57 61.31 61.31 0 0 1 91.07 8 61.83 61.83 0 0 1 106.6 20.27V5.36Z"/></svg>';
    target.appendChild(loader);
    loaderMap[key] = loader;

    return loader;
  }

  function getGradesHeadingTarget() {
    const headings = form.querySelectorAll('h3, h4, .heading');
    for (let i = 0; i < headings.length; i++) {
      const heading = headings[i];
      if ((heading.textContent || '').trim() === 'Grades to store') {
        return heading;
      }
    }

    return null;
  }

  function ensureLoaders() {
    ensureLoader(document.querySelector('label[for="filterStudents"]'), 'students');
    ensureLoader(document.querySelector('label[for="filterSubjects"]'), 'subjects');
    ensureLoader(getGradesHeadingTarget(), 'grades');
  }

  function setActiveLoaders(keys) {
    Object.keys(loaderMap).forEach(function (key) {
      const loader = loaderMap[key];
      const isActive = keys.indexOf(key) !== -1;

      loader.classList.toggle('is-active', isActive);

      if (!isActive) {
        loader.classList.remove('is-working', 'is-success', 'is-error');
      }
    });
  }

  function setLoaderState(keys, state) {
    Object.keys(loaderMap).forEach(function (key) {
      const loader = loaderMap[key];
      const isTarget = keys.indexOf(key) !== -1;

      loader.classList.remove('is-working', 'is-success', 'is-error');

      if (!isTarget) {
        return;
      }

      loader.classList.add('is-active');

      if (state === 'working') {
        loader.classList.add('is-working');
      } else if (state === 'success') {
        loader.classList.add('is-success');
      } else if (state === 'error') {
        loader.classList.add('is-error');
      }
    });
  }

  function setStatus(message, tone) {
    status.textContent = message;
    status.className = 'store-ajax-status';

    if (tone === 'working') {
      return;
    }

    if (tone === 'success') {
      return;
    }

    if (tone === 'error') {
      return;
    }
  }

  function getToggleValue(name) {
    const values = new FormData(form).getAll(name).map(function (value) {
      return String(value).toUpperCase();
    });

    if (values.indexOf('Y') !== -1 || values.indexOf('ON') !== -1 || values.indexOf('1') !== -1 || values.indexOf('TRUE') !== -1) {
      return 'Y';
    }

    return 'N';
  }

  function getFieldValues(name) {
    return new FormData(form).getAll(name).map(function (value) {
      return String(value);
    }).filter(function (value) {
      return value !== '';
    });
  }

  function getSelectedValues(selector) {
    const select = document.querySelector(selector);
    if (!select) return [];

    return Array.from(select.options)
      .filter(function (option) { return option.selected; })
      .map(function (option) { return option.value; });
  }

  function getSelectedStudentIDs() {
    const selects = document.querySelectorAll('#studentIDsContainer select[multiple]');
    if (selects.length < 2) return [];

    return Array.from(selects[1].options).map(function (option) {
      return option.value;
    });
  }

  function updateLastStudentSelectionKey() {
    lastStudentSelectionKey = getSelectedStudentIDs().join(',');
  }

  function bindStudentSelectionEvents() {
    const container = document.querySelector('#studentIDsContainer');
    if (!container || container.dataset.ajaxBound === '1') {
      return;
    }

    container.dataset.ajaxBound = '1';

    function queueStudentRefresh() {
      const before = lastStudentSelectionKey;
      window.setTimeout(function () {
        const after = getSelectedStudentIDs().join(',');
        lastStudentSelectionKey = after;

        if (before !== after && getToggleValue('filterStudents') === 'Y') {
          refreshDependentFilters(200, ['subjects', 'grades']);
        }
      }, 120);
    }

    container.addEventListener('click', queueStudentRefresh);
    container.addEventListener('dblclick', queueStudentRefresh);
    container.addEventListener('change', queueStudentRefresh);
    container.addEventListener('keyup', function (event) {
      if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
        queueStudentRefresh();
      }
    });

    updateLastStudentSelectionKey();
  }

  function getCriteriaSelections() {
    const typeIDs = Array.from(document.querySelectorAll('input[name="criteriaTypeIDs[]"]'))
      .filter(function (el) { return el.checked; })
      .map(function (el) { return el.value; });
    const values = {};

    document.querySelectorAll('input[name^="values_"]').forEach(function (checkbox) {
      const match = checkbox.name.match(/^values_(.+)\[\]$/);
      if (!match || !checkbox.checked) return;

      const key = 'values_' + match[1] + '[]';
      if (!values[key]) {
        values[key] = [];
      }

      values[key].push(checkbox.value);
    });

    return {
      typeIDs: typeIDs,
      values: values
    };
  }

  function bindCriteriaToggles(scope) {
    (scope || document).querySelectorAll('input.criteria-toggle').forEach(function (toggle) {
      const typeID = toggle.dataset.type;
      if (!typeID) return;

      const values = document.querySelectorAll('.criteria-values-' + typeID + ' input[type="checkbox"]');

      function sync() {
        values.forEach(function (cb) {
          cb.disabled = !toggle.checked;
        });
      }

      sync();
      toggle.addEventListener('change', sync);
    });
  }

  function renderSubjects(subjects) {
    const select = document.querySelector('#subjectIDsSelect');
    if (!select) return;

    const previous = getSelectedValues('#subjectIDsSelect');
    const entries = Object.entries(subjects || {});

    select.innerHTML = '';

    entries.forEach(function (entry) {
      const option = document.createElement('option');
      option.value = entry[0];
      option.textContent = entry[1];
      option.selected = previous.indexOf(entry[0]) !== -1;
      select.appendChild(option);
    });

    select.setAttribute('size', String(Math.min(12, Math.max(4, entries.length || 4))));
  }

  function renderStudents(studentData) {
    const container = document.querySelector('#studentIDsContainer');
    if (!container || !studentData) return;

    const selects = container.querySelectorAll('select[multiple]');
    if (selects.length < 2) return;

    selects[0].innerHTML = '';
    selects[1].innerHTML = '';

    Object.entries(studentData.source || {}).forEach(function (entry) {
      const option = document.createElement('option');
      option.value = entry[0];
      option.textContent = entry[1];
      selects[0].appendChild(option);
    });

    Object.entries(studentData.destination || {}).forEach(function (entry) {
      const option = document.createElement('option');
      option.value = entry[0];
      option.textContent = entry[1];
      selects[1].appendChild(option);
    });

    container.setAttribute('data-sortable', JSON.stringify({
      'Form Group': studentData.formGroups || {}
    }));

    if (window.jQuery && typeof window.jQuery.fn.gibbonMultiSelect === 'function') {
      window.jQuery('#studentIDsContainer').gibbonMultiSelect('studentIDs');
    }

    updateLastStudentSelectionKey();
  }

  function renderCriteria(criteriaHtml) {
    const container = document.querySelector('#criteriaTableContainer');
    if (!container || !criteriaHtml) return;

    container.outerHTML = criteriaHtml;
    bindCriteriaToggles(document.querySelector('#criteriaTableContainer'));
  }

  function refreshDependentFilters(delay, affectedLoaders) {
    if (!ajaxUrl) {
      setLoaderState(Array.isArray(affectedLoaders) ? affectedLoaders : [], 'error');
      setStatus('AJAX URL missing.', 'error');
      return;
    }

    if (refreshTimer) {
      clearTimeout(refreshTimer);
    }

    refreshTimer = setTimeout(function () {
      const params = new URLSearchParams();
      const cycleSelect = form.querySelector('select[name="gibbonReportingCycleID"]');
      const criteriaSelections = getCriteriaSelections();
      const filterYearGroups = getToggleValue('filterYearGroups');
      const filterSubjects = getToggleValue('filterSubjects');
      const filterStudents = getToggleValue('filterStudents');

      params.set('type', 'dependentFilters');
      params.set('cycleID', cycleSelect ? cycleSelect.value : '');
      params.set('filterYearGroups', filterYearGroups);
      params.set('filterSubjects', filterSubjects);
      params.set('filterStudents', filterStudents);

      if (filterYearGroups === 'Y') {
        getFieldValues('gibbonYearGroupIDList[]').forEach(function (value) {
          params.append('yearGroups[]', value);
        });
      }

      if (filterSubjects === 'Y') {
        getSelectedValues('#subjectIDsSelect').forEach(function (value) {
          params.append('subjects[]', value);
        });
      }

      getSelectedStudentIDs().forEach(function (value) {
        params.append('studentIDs[]', value);
      });

      criteriaSelections.typeIDs.forEach(function (value) {
        params.append('criteriaTypeIDs[]', value);
      });

      Object.keys(criteriaSelections.values).forEach(function (key) {
        criteriaSelections.values[key].forEach(function (value) {
          params.append(key, value);
        });
      });

      const requestID = ++refreshRequest;
      const targetLoaders = Array.isArray(affectedLoaders) ? affectedLoaders : [];
      setLoaderState(targetLoaders, 'working');
      setStatus('Updating downstream filters...', 'working');

      fetch(ajaxUrl + '?' + params.toString(), {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Dependent filter request failed with status ' + response.status + '.');
          }
          return response.json();
        })
        .then(function (data) {
          if (requestID !== refreshRequest) return;

          renderSubjects(data.subjects || {});
          renderStudents(data.students || {});
          renderCriteria(data.criteriaHtml || '');
          ensureLoaders();
          setLoaderState(targetLoaders, 'success');
          window.setTimeout(function () {
            if (requestID !== refreshRequest) return;
            setActiveLoaders([]);
          }, 100);
          setStatus('Downstream filters updated. Year Groups: ' + filterYearGroups + '. Students: ' + filterStudents + '. Subjects: ' + filterSubjects + '.', 'success');
        })
        .catch(function (error) {
          setLoaderState(targetLoaders, 'error');
          setStatus('Downstream filter update failed. See console.', 'error');
          console.error(error);
        });
    }, typeof delay === 'number' ? delay : 150);
  }

  function bindUpstreamEvents() {
    const cycleSelect = form.querySelector('select[name="gibbonReportingCycleID"]');
    if (cycleSelect) {
      cycleSelect.addEventListener('change', function () {
        // Clear the term so the new cycle gets its own suggestion. Keeping the
        // old value would hold Semester 1 while the cycle moved to Semester 2.
        const termSelect = form.querySelector('select[name="gibbonSchoolYearTermID"]');
        if (termSelect) {
          termSelect.selectedIndex = -1;
        }

        form.submit();
      });
    }

    document.querySelectorAll('input[name="gibbonYearGroupIDList[]"]').forEach(function (checkbox) {
      checkbox.addEventListener('change', function () {
        let touched = form.querySelector('input[name="yearGroupsTouched"]');
        if (!touched) {
          touched = document.createElement('input');
          touched.type = 'hidden';
          touched.name = 'yearGroupsTouched';
          form.appendChild(touched);
        }
        touched.value = '1';

        refreshDependentFilters(200, ['students', 'subjects', 'grades']);
      });
    });

    const subjectToggleNodes = form.querySelectorAll('[name="filterSubjects"]');
    subjectToggleNodes.forEach(function (node) {
      node.addEventListener('change', function () {
        refreshDependentFilters(150, ['grades']);
      });
    });

    const yearGroupToggleNodes = form.querySelectorAll('[name="filterYearGroups"]');
    yearGroupToggleNodes.forEach(function (node) {
      node.addEventListener('change', function () {
        refreshDependentFilters(150, ['students', 'subjects', 'grades']);
      });
    });

    const studentToggleNodes = form.querySelectorAll('[name="filterStudents"]');
    studentToggleNodes.forEach(function (node) {
      node.addEventListener('change', function () {
        refreshDependentFilters(150, ['subjects', 'grades']);
      });
    });

    const subjectSelect = document.querySelector('#subjectIDsSelect');
    if (subjectSelect) {
      subjectSelect.addEventListener('change', function () {
        const yes = form.querySelector('input[name="filterSubjects"][value="Y"]');
        if (yes) {
          yes.checked = true;
        }

        refreshDependentFilters(200, ['grades']);
      });
    }
  }

  ensureLoaders();
  bindCriteriaToggles(document);
  bindUpstreamEvents();
  bindStudentSelectionEvents();
  updateLastStudentSelectionKey();
  setStatus('AJAX ready. Waiting for filter changes.', 'idle');
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAcademicRecordsStoreStep1);
} else {
  initAcademicRecordsStoreStep1();
}

window.addEventListener('pageshow', function () {
  initAcademicRecordsStoreStep1();
});
</script>
HTML;
}

/* -----------------------------------------------------
   STEP 1: Select Grades to store
----------------------------------------------------- */

if ($step === 1) {

    /* -----------------------------------------------------
       Reporting Cycles
    ----------------------------------------------------- */

    $cycles  = getReportingCycles($connection2);
    $cycleID = $_GET['gibbonReportingCycleID'] ?? array_key_first($cycles);

    /* -----------------------------------------------------
       Term

       Transcripts read the term from the stored grade index, not from the
       reporting cycle dates. A cycle commonly runs after its term closes.
    ----------------------------------------------------- */

    $storedGradeGateway = $container->get(StoredGradeGateway::class);

    $terms = $storedGradeGateway->selectTermsByCycle((int) $cycleID);

    $termOptions = [];
    foreach ($terms as $term) {
        $termOptions[(string) $term['gibbonSchoolYearTermID']] = (string) $term['name'];
    }

    if (empty($termOptions)) {
        $termsURL = $session->get('absoluteURL')
            . '/index.php?q=/modules/School Admin/schoolYearTerm_manage.php';

        $page->addError(__('Store Grades cannot continue until the school year of this reporting cycle has terms.'));

        echo '<div class="warning flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">';
        echo '<div class="flex-1">';
        echo __('Stored grades are recorded against a school year term, so transcripts can place them in the right column.<br />');
        echo __('Add the terms for this school year, and then return to this page.');
        echo '</div>';
        echo '<div class="text-left sm:text-right sm:ml-auto">';
        echo '<a class="rounded-md px-4 py-2 text-sm sm:leading-5 inline-block align-middle font-semibold shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 border border-amber-600 bg-white hover:bg-amber-50 text-amber-900 no-underline" href="'
            . htmlspecialchars($termsURL) . '">'
            . __('Go to Manage School Year Terms')
            . '</a>';
        echo '</div>';
        echo '</div>';
        return;
    }

    $termID = normalizeSchoolYearTermID($_GET['gibbonSchoolYearTermID'] ?? '');

    if ($termID === '' || !isset($termOptions[$termID])) {
        $termID = $storedGradeGateway->suggestTermForCycle((int) $cycleID);
    }

    /* -----------------------------------------------------
       Year Groups
    ----------------------------------------------------- */

    $filterYearGroups = $_GET['filterYearGroups'] ?? 'N';

    $yearGroupIDs = normalizeRequestList($_GET['gibbonYearGroupIDList'] ?? []);

    $cycleYearGroups = getYearGroupsByReportingCycle($connection2, (int) $cycleID);

    $yearGroupsTouched = ($_GET['yearGroupsTouched'] ?? '0') === '1';
    if (!$yearGroupsTouched && empty($yearGroupIDs)) {
        $yearGroupIDs = array_keys($cycleYearGroups);
    }

    /* -----------------------------------------------------
       Students
    ----------------------------------------------------- */

    $studentIDs = normalizeRequestList($_GET['studentIDs'] ?? []);
    $filterStudents = $_GET['filterStudents'] ?? 'N';

    /* -----------------------------------------------------
       Subjects
    ----------------------------------------------------- */

    $filterSubjects = $_GET['filterSubjects'] ?? 'N';
    $subjectIDs = normalizeRequestList($_GET['subjectIDs'] ?? []);

    $effectiveYearGroupIDs = ($filterYearGroups === 'Y')
        ? $yearGroupIDs
        : array_keys($cycleYearGroups);

    $subjects = getSubjectsByYearGroups(
        $connection2,
        $effectiveYearGroupIDs,
        (int) $cycleID,
        $studentIDs,
        $filterStudents === 'Y'
    );

    /* -----------------------------------------------------
       Grades to store
    ----------------------------------------------------- */

    $criteriaTypeIDs = normalizeRequestList($_GET['criteriaTypeIDs'] ?? []);

    /* -----------------------------------------------------
       Form Setup
    ----------------------------------------------------- */

    $action = $session->get('absoluteURL') . '/index.php?q=/modules/Academic Records/academicRecords_store.php';

    $form = Form::create('filters', $action, 'get');
    $form->setFactory(DatabaseFormFactory::create($container->get('db')));
    $form->setAttribute(
        'data-store-ajax-url',
        $session->get('absoluteURL') . '/modules/' . rawurlencode((string) $session->get('module')) . '/academicRecords_store_ajax.php'
    );

    $form->addHiddenValue('q', '/modules/Academic Records/academicRecords_store.php');
    $form->addHiddenValue('step', '1');

    /* -----------------------------------------------------
       Reporting Cycle
    ----------------------------------------------------- */

    $row = $form->addRow();
    $row->addLabel('gibbonReportingCycleID', __('Reporting Cycle'));
    $row->addSelect('gibbonReportingCycleID')
        ->fromArray($cycles)
        ->selected($cycleID)
        ->required();

    /* -----------------------------------------------------
       Term
    ----------------------------------------------------- */

    $row = $form->addRow();
    $row->addLabel('gibbonSchoolYearTermID', __('Store as Term'))
        ->description(__('The term these grades belong to. Transcripts read this value, because a reporting cycle often runs after its term has closed. Check the suggestion before you continue.'));
    $row->addSelect('gibbonSchoolYearTermID')
        ->fromArray($termOptions)
        ->selected($termID)
        ->required();

    /* -----------------------------------------------------
       Year Groups
    ----------------------------------------------------- */

    $form->addRow()->addHeading(__('Filters'));

    $row = $form->addRow();
    $row->addContent(
        '<div id="storeAjaxStatus" class="store-ajax-status" aria-live="polite">'
        . __('Waiting for filter changes.')
        . '</div>'
    );

    $form->toggleVisibilityByClass('ygPanel')
        ->onClick('filterYearGroups')
        ->when('Y');

    $row = $form->addRow();
    $row->addLabel('filterYearGroups', __('Filter by Year Groups'));
    $row->addYesNo('filterYearGroups')
        ->checked($filterYearGroups);

    $row = $form->addRow()->addClass('ygPanel');
    $row->addCheckbox('gibbonYearGroupIDList[]')
        ->fromArray($cycleYearGroups)
        ->checked($yearGroupIDs)
        ->addCheckAllNone();

    /* -----------------------------------------------------
       Students
    ----------------------------------------------------- */

    $form->toggleVisibilityByClass('studentPanel')
        ->onClick('filterStudents')
        ->when('Y');

    $row = $form->addRow();
    $row->addLabel('filterStudents', __('Filter by Students'));
    $row->addYesNo('filterStudents')
        ->checked($filterStudents);

    $studentGateway = $container->get(StudentGateway::class);
    $criteriaObj = $studentGateway->newQueryCriteria()->sortBy(['surname', 'preferredName']);

    $students = $studentGateway
        ->queryStudentsBySchoolYear($criteriaObj, $session->get('gibbonSchoolYearID'))
        ->toArray();

    $studentSelection = buildStudentSelectionData($students, $effectiveYearGroupIDs, $studentIDs);

    $col = $form->addRow()->addClass('studentPanel')->addColumn();
    $col->addLabel('studentIDs', __('Students'));

    $multi = $col->addMultiSelect('studentIDs');
    $multi->addSortableAttribute(__('Form Group'), $studentSelection['formGroups']);
    $multi->source()->fromArray($studentSelection['source']);
    $multi->destination()->fromArray($studentSelection['destination']);

    /* -----------------------------------------------------
       Subjects
    ----------------------------------------------------- */

    $form->toggleVisibilityByClass('subjectPanel')
        ->onClick('filterSubjects')
        ->when('Y');

    $row = $form->addRow();
    $row->addLabel('filterSubjects', __('Filter by Subjects'));
    $row->addYesNo('filterSubjects')
        ->checked($filterSubjects);

    $row = $form->addRow()->addClass('subjectPanel');

    $row->addSelect('subjectIDs[]')
        ->setID('subjectIDsSelect')
        ->fromArray($subjects)
        ->selected($subjectIDs)
        ->selectMultiple()
        ->setAttribute('size', min(12, max(4, count($subjects))))
        ->required(false);

    /* -----------------------------------------------------
       Grades to store
    ----------------------------------------------------- */

    $form->addRow()->addHeading(__('Grades to store'));

    $criteriaTypes = getCriteriaTypesByReportingCycle(
        $connection2,
        (int) $cycleID,
        $effectiveYearGroupIDs,
        $filterYearGroups === 'Y',
        $subjectIDs,
        $filterSubjects === 'Y',
        $studentIDs,
        $filterStudents === 'Y'
    );

    $valueSelections = [];
    $valueSelectionsSaved = [];

    foreach ($_GET as $key => $val) {
        if (!is_array($val)) {
            continue;
        }

        if (strpos($key, 'values_') === 0) {
            $valueSelections[$key] = $val;
        }
        if (strpos($key, 'valuesSaved_') === 0) {
            $valueSelectionsSaved[$key] = $val;
        }
    }

    $row = $form->addRow();
    $row->addContent(
        renderCriteriaTableHtml(
            $connection2,
            $criteriaTypes,
            $criteriaTypeIDs,
            $valueSelections,
            $valueSelectionsSaved
        )
    );

    /* -----------------------------------------------------
       Footer
    ----------------------------------------------------- */

    $row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(__('Preview'))
        ->setAttribute('onclick', "this.form.step.value='2';");

    echo $form->getOutput();
    renderStoreStep1AjaxScript();
    return;
}

/* -----------------------------------------------------
   STEP 2: Preview
----------------------------------------------------- */

if ($step === 2) {

    $data = flattenRequestArray($_GET);

    $cycleID = (int)($data['gibbonReportingCycleID'] ?? 0);
    if (empty($cycleID)) {
        echo Format::alert(__('Your request failed because your inputs were invalid.'));
        return;
    }

    echo Format::alert(
        __('Preview the records that may be written. If any are incorrect, go back to step 1, otherwise continue to dry run.'),
        'message'
    );

    /** @var \Gibbon\Module\AcademicRecords\Domain\AcademicRecordsGateway $gateway */
    $gateway = $container->get(\Gibbon\Module\AcademicRecords\Domain\AcademicRecordsGateway::class);

    $criteria = $gateway->newQueryCriteria(true)
        ->sortBy(['p.surname', 'p.preferredName', 'c.nameShort', 'rc.name'])
        ->pageSize(50)
        ->fromPOST();

    $actionStep2 = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_store.php&step=2';

    $actionStep3 = $session->get('absoluteURL')
        . '/index.php?q=/modules/Academic Records/academicRecords_store.php&step=3';

    $form = Form::create('previewStep', $actionStep2);
    $form->setFactory(DatabaseFormFactory::create($container->get('db')));

    $form->addHiddenValue('q', '/modules/Academic Records/academicRecords_store.php');
    $form->addHiddenValue('step', '2');

    foreach ($data as $key => $val) {
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

    $dataSet = $gateway->queryEligibleReportingGrades($criteria, $cycleID, $data);

    $table = $form->addRow()
        ->addDataTable('academicRecordsPreview', $criteria)
        ->withData($dataSet);

    $table->addColumn('studentName', __('Student'));
    $table->addColumn('courseName', __('Course'));
    $table->addColumn('className', __('Class'));
    $table->addColumn('criteriaName', __('Criteria'));
    $table->addColumn('value', __('Value'));

    $backQuery = $data;
    $backQuery['q'] = '/modules/Academic Records/academicRecords_store.php';
    $backQuery['step'] = 1;

    $backURL = $session->get('absoluteURL') . '/index.php?' . http_build_query($backQuery);

    $row = $form->addRow();
    $row->addContent(
        '<div style="width:100%; display:flex; justify-content:space-between; align-items:center; gap:16px; padding-top:8px;">'
        . '<a class="no-underline" style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:6px; border:1px solid #475569; background:#475569; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18);" onmouseover="this.style.backgroundColor=\'#334155\';this.style.borderColor=\'#334155\';" onmouseout="this.style.backgroundColor=\'#475569\';this.style.borderColor=\'#475569\';" href="' . htmlspecialchars($backURL) . '">'
        . '&#8592; ' . __('Back')
        . '</a>'
        . '<button type="submit" style="display:inline-flex; align-items:center; padding:8px 16px; border-radius:6px; border:1px solid #1f2937; background:#374151; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18); cursor:pointer;" onmouseover="this.style.backgroundColor=\'#1f2937\';" onmouseout="this.style.backgroundColor=\'#374151\';" onclick="this.form.action=\'' . addslashes($actionStep3) . '\'; this.form.step.value=\'3\';">'
        . __('Continue to Dry Run')
        . '</button>'
        . '</div>'
    );

    echo $form->getOutput();
    return;
}

/* -----------------------------------------------------
   STEP 3 & 4: Dry Run and Live Run (same structure as import_run.php)
----------------------------------------------------- */

if ($step === 3 || $step === 4) {

    $isLive = ($step === 4);
    $memoryStart = memory_get_usage();
    $timeStart = microtime(true);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Format::alert(__('Your request failed because your inputs were invalid.'));
        return;
    }

    $cycleID = (int)($_POST['gibbonReportingCycleID'] ?? 0);

    if (empty($cycleID)) {
        echo Format::alert(__('Invalid Reporting Cycle.'));
        return;
    }

    $filters = $_POST;

    /** @var \Gibbon\Module\AcademicRecords\Domain\AcademicRecordsGateway $gateway */
    $gateway = $container->get(\Gibbon\Module\AcademicRecords\Domain\AcademicRecordsGateway::class);

    try {
        $result = $gateway->dryRunStoreReportingGrades(
            $cycleID,
            $filters,
            (int) $session->get('gibbonPersonID')
        );

        $overallSuccess = ($result['importSuccess'] ?? false)
            && ($result['buildSuccess'] ?? false)
            && ($result['databaseSuccess'] ?? false);

        if ($overallSuccess) {
            if ($isLive) {
                echo Format::alert(
                    __('The store completed successfully and all relevant Internal Assessment columns and entries have been created and/or updated.'),
                    'success'
                );
            } else {
                echo Format::alert(
                    __('The data was successfully validated. This is a <b>DRY RUN!</b> No changes have been made to the database.<br />If everything looks good here, you can click "Run Live Store" to complete this import.'),
                    'message'
                );
            }
        } else {
            echo Format::alert($result['lastError'] ?? __('An unknown error occurred, so the import will be aborted.'));
        }
    } catch (\Throwable $e) {
        $result = [
            'importSuccess' => true,
            'buildSuccess' => true,
            'databaseSuccess' => false,
            'rows' => 0,
            'rowerrors' => 1,
            'errors' => 1,
            'warnings' => 0,
            'inserts' => 0,
            'inserts_skipped' => 0,
            'updates' => 0,
            'updates_skipped' => 0,
            'columnsToCreate' => 0,
            'noChange' => 0,
            'lastError' => $e->getMessage(),
        ];
        $overallSuccess = false;
        echo Format::alert($e->getMessage());
    }

    $result['step'] = $step;
    $result['executionTime'] = mb_substr((string) (microtime(true) - $timeStart), 0, 6) . ' sec';
    $result['memoryUsage'] = Format::filesize(max(0, memory_get_usage() - $memoryStart));

    renderStoreExecutionResults($page, $result);

    if (!$isLive) {
        $action = $session->get('absoluteURL')
            . '/index.php?q=/modules/Academic Records/academicRecords_store.php&step=4';

        $backQuery = $_POST;
        $backQuery['q'] = '/modules/Academic Records/academicRecords_store.php';
        $backQuery['step'] = 2;
        $backURL = $session->get('absoluteURL') . '/index.php?' . http_build_query($backQuery);

        $form = Form::createBlank('dryRunConfirm', $action);
        $form->setFactory(DatabaseFormFactory::create($container->get('db')));
        $form->addHiddenValue('q', '/modules/Academic Records/academicRecords_store.php');
        $form->addHiddenValue('step', '4');

        foreach ($_POST as $key => $val) {
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

        $payloadPreview = buildStorePayloadPreview($result);

        if ($payloadPreview !== '') {
            $row = $form->addRow();
            $row->addContent(
                '<details class="w-full rounded border border-gray-400 bg-white">'
                . '<summary class="cursor-pointer select-none px-4 py-3 font-semibold text-gray-800">'
                . __('Data')
                . '</summary>'
                . '<div class="px-4 pb-4 pt-2">'
                . '<textarea readonly rows="16" cols="74" class="w-full" style="font-family: monospace;">'
                . htmlspecialchars($payloadPreview)
                . '</textarea>'
                . '</div>'
                . '</details>'
            );
        }

        $row = $form->addRow();
        $row->addContent(
            '<div style="width:100%; display:flex; justify-content:space-between; align-items:center; gap:16px; padding-top:8px;">'
            . '<a class="no-underline" style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:6px; border:1px solid #475569; background:#475569; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18);" onmouseover="this.style.backgroundColor=\'#334155\';this.style.borderColor=\'#334155\';" onmouseout="this.style.backgroundColor=\'#475569\';this.style.borderColor=\'#475569\';" href="' . htmlspecialchars($backURL) . '">'
            . '&#8592; ' . __('Back')
            . '</a>'
            . ($overallSuccess
                ? '<button type="submit" id="submitStep3" style="display:inline-flex; align-items:center; padding:8px 16px; border-radius:6px; border:1px solid #1f2937; background:#374151; color:#ffffff; font-size:14px; font-weight:600; line-height:1.25; box-shadow:0 1px 2px rgba(15, 23, 42, 0.18); cursor:pointer;" onmouseover="this.style.backgroundColor=\'#1f2937\';" onmouseout="this.style.backgroundColor=\'#374151\';">'
                . __('Run Live Store')
                . '</button>'
                : '<button type="button" id="submitStep3" disabled style="display:inline-flex; align-items:center; padding:8px 16px; border-radius:6px; border:1px solid #cbd5e1; background:#e2e8f0; color:#64748b; font-size:14px; font-weight:600; line-height:1.25; box-shadow:none; cursor:not-allowed;">'
                . __('Failed')
                . '</button>')
            . '</div>'
        );

        echo $form->getOutput();
    }

    return;
}
