document.addEventListener('DOMContentLoaded', function () {
  let refreshTimer = null;
  let refreshRequest = 0;

  function getFiltersForm() {
    return document.querySelector('form#filters');
  }

  function getAjaxUrl() {
    const form = getFiltersForm();
    return form ? form.dataset.storeAjaxUrl || '' : '';
  }

  function setAjaxStatus(message, tone) {
    const status = document.querySelector('#storeAjaxStatus');
    if (!status) return;

    status.textContent = message;
    status.className = 'text-xxs italic';

    if (tone === 'working') {
      status.classList.add('text-blue-600');
      return;
    }

    if (tone === 'success') {
      status.classList.add('text-green-600');
      return;
    }

    if (tone === 'error') {
      status.classList.add('text-red-600');
      return;
    }

    status.classList.add('text-gray-500');
  }

  function getCheckedValues(selector) {
    return Array.from(document.querySelectorAll(selector))
      .filter(function (el) { return el.checked; })
      .map(function (el) { return el.value; });
  }

  function getSelectedValues(selector) {
    const select = document.querySelector(selector);
    if (!select) return [];

    return Array.from(select.options)
      .filter(function (option) { return option.selected; })
      .map(function (option) { return option.value; });
  }

  function getToggleValue(toggleName) {
    const form = getFiltersForm();
    if (!form) return 'N';

    const checked = form.querySelector('input[name="' + toggleName + '"]:checked');
    if (checked) return checked.value;

    const select = form.querySelector('select[name="' + toggleName + '"]');
    if (select) return select.value;

    return 'N';
  }

  function setHiddenValue(name, value) {
    const form = getFiltersForm();
    if (!form) return;

    let input = form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }

    input.value = value;
  }

  function applyCriteriaToggleBehavior(scope) {
    const root = scope || document;

    root.querySelectorAll('input.criteria-toggle').forEach(function (toggle) {
      const typeID = toggle.dataset.type;
      if (!typeID) return;

      const inputs = root.querySelectorAll('.criteria-values-' + typeID + ' input[type="checkbox"]');

      function sync() {
        inputs.forEach(function (cb) {
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
    const options = Object.entries(subjects || {});

    select.innerHTML = '';

    options.forEach(function (entry) {
      const option = document.createElement('option');
      option.value = entry[0];
      option.textContent = entry[1];
      option.selected = previous.indexOf(entry[0]) !== -1;
      select.appendChild(option);
    });

    const visibleCount = Math.min(12, Math.max(4, options.length || 4));
    select.setAttribute('size', String(visibleCount));
  }

  function renderCriteria(criteriaHtml) {
    const container = document.querySelector('#criteriaTableContainer');
    if (!container) return;

    container.outerHTML = criteriaHtml;
    const refreshed = document.querySelector('#criteriaTableContainer');
    if (!refreshed) return;

    applyCriteriaToggleBehavior(refreshed);
  }

  function renderStudents(studentData) {
    const container = document.querySelector('#studentIDsContainer');
    if (!container || !studentData) return;

    const multiSelects = container.querySelectorAll('select[multiple]');
    if (multiSelects.length < 2) return;

    const sourceSelect = multiSelects[0];
    const destinationSelect = multiSelects[1];

    sourceSelect.innerHTML = '';
    destinationSelect.innerHTML = '';

    Object.entries(studentData.source || {}).forEach(function (entry) {
      const option = document.createElement('option');
      option.value = entry[0];
      option.textContent = entry[1];
      sourceSelect.appendChild(option);
    });

    Object.entries(studentData.destination || {}).forEach(function (entry) {
      const option = document.createElement('option');
      option.value = entry[0];
      option.textContent = entry[1];
      destinationSelect.appendChild(option);
    });

    container.setAttribute('data-sortable', JSON.stringify({
      'Form Group': studentData.formGroups || {}
    }));

    if (window.jQuery && typeof window.jQuery.fn.gibbonMultiSelect === 'function') {
      window.jQuery('#studentIDsContainer').gibbonMultiSelect('studentIDs');
    }
  }

  function collectSelectedCriteriaTypeIDs() {
    return getCheckedValues('input[name="criteriaTypeIDs[]"]');
  }

  function collectSelectedCriteriaValues() {
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

    return values;
  }

  function refreshDependentFilters(delay) {
    const form = getFiltersForm();
    const ajaxUrl = getAjaxUrl();
    if (!form || !ajaxUrl) {
      setAjaxStatus('AJAX configuration is missing.', 'error');
      return;
    }

    if (refreshTimer) {
      clearTimeout(refreshTimer);
    }

    refreshTimer = setTimeout(function () {
      setAjaxStatus('Updating downstream filters...', 'working');

      const params = new URLSearchParams();
      const cycleSelect = form.querySelector('select[name="gibbonReportingCycleID"]');
      const cycleID = cycleSelect ? cycleSelect.value : '';

      params.set('type', 'dependentFilters');
      params.set('cycleID', cycleID);
      params.set('filterYearGroups', getToggleValue('filterYearGroups'));
      params.set('filterSubjects', getToggleValue('filterSubjects'));

      if (getToggleValue('filterYearGroups') === 'Y') {
        getCheckedValues('input[name="gibbonYearGroupIDList[]"]').forEach(function (value) {
          params.append('yearGroups[]', value);
        });
      }

      if (getToggleValue('filterSubjects') === 'Y') {
        getSelectedValues('#subjectIDsSelect').forEach(function (value) {
          params.append('subjects[]', value);
        });
      }

      const destinationSelect = document.querySelectorAll('#studentIDsContainer select[multiple]');
      if (destinationSelect.length >= 2) {
        Array.from(destinationSelect[1].options).forEach(function (option) {
          params.append('studentIDs[]', option.value);
        });
      }

      collectSelectedCriteriaTypeIDs().forEach(function (value) {
        params.append('criteriaTypeIDs[]', value);
      });

      const selectedCriteriaValues = collectSelectedCriteriaValues();
      Object.keys(selectedCriteriaValues).forEach(function (key) {
        selectedCriteriaValues[key].forEach(function (value) {
          params.append(key, value);
        });
      });

      const requestID = ++refreshRequest;

      fetch(ajaxUrl + '?' + params.toString(), {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Dependent filter request failed.');
          }
          return response.json();
        })
        .then(function (data) {
          if (requestID !== refreshRequest) return;

          renderSubjects(data.subjects || {});
          renderStudents(data.students || {});
          renderCriteria(data.criteriaHtml || '');
          setAjaxStatus('Downstream filters updated.', 'success');
        })
        .catch(function (error) {
          setAjaxStatus('Downstream filter update failed. Check browser console.', 'error');
          if (window.console && typeof window.console.error === 'function') {
            window.console.error(error);
          }
        });
    }, typeof delay === 'number' ? delay : 200);
  }

  applyCriteriaToggleBehavior(document);

  (function wireYearGroupBehaviour() {
    const form = getFiltersForm();
    if (!form) return;

    const master = document.querySelector('#checkallgibbonYearGroupIDList');
    const items = document.querySelectorAll('input[name="gibbonYearGroupIDList[]"]');

    function updateMasterState() {
      const total = items.length;
      const checked = Array.from(items).filter(function (cb) { return cb.checked; }).length;
      if (master) {
        master.checked = total > 0 && checked === total;
      }
    }

    function markTouchedAndRefresh() {
      setHiddenValue('yearGroupsTouched', '1');
      const yes = form.querySelector('input[name="filterYearGroups"][value="Y"]');
      if (yes) {
        yes.checked = true;
      }
      refreshDependentFilters(250);
    }

    updateMasterState();

    items.forEach(function (cb) {
      cb.addEventListener('change', function () {
        updateMasterState();
        markTouchedAndRefresh();
      });
    });

    if (master) {
      master.addEventListener('change', function () {
        items.forEach(function (cb) {
          cb.checked = master.checked;
        });
        updateMasterState();
        markTouchedAndRefresh();
      });
    }
  })();

  (function wireToggleBehaviour() {
    const form = getFiltersForm();
    if (!form) return;

    function setPanelEnabled(panelClass, enabled) {
      document.querySelectorAll('.' + panelClass).forEach(function (node) {
        node.querySelectorAll('input, select, textarea, button').forEach(function (el) {
          el.disabled = !enabled;
        });
      });
    }

    function wireToggle(toggleName, panelClass, options) {
      const nodes = form.querySelectorAll('[name="' + toggleName + '"]');
      if (!nodes.length) return;

      nodes.forEach(function (node) {
        node.addEventListener('change', function () {
          const value = getToggleValue(toggleName);
          const isOn = value === 'Y';

          setPanelEnabled(panelClass, isOn);

          if (options && options.refresh) {
            refreshDependentFilters(options.delay || 150);
          }
        });
      });
    }

    wireToggle('filterYearGroups', 'ygPanel', { refresh: true, delay: 150 });
    wireToggle('filterStudents', 'studentPanel', { refresh: false });
    wireToggle('filterSubjects', 'subjectPanel', { refresh: true, delay: 150 });
  })();

  (function wireSelectBehaviour() {
    const form = getFiltersForm();
    if (!form) return;

    const cycleSelect = form.querySelector('select[name="gibbonReportingCycleID"]');
    if (cycleSelect) {
      cycleSelect.addEventListener('change', function () {
        form.submit();
      });
    }

    const subjectSelect = document.querySelector('#subjectIDsSelect');
    if (subjectSelect) {
      subjectSelect.addEventListener('change', function () {
        const yes = form.querySelector('input[name="filterSubjects"][value="Y"]');
        if (yes) {
          yes.checked = true;
        }

        refreshDependentFilters(250);
      });
    }
  })();
});
