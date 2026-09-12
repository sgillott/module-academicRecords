/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/* -----------------------------------------------------
   Store Grades, step 1

   Refreshes the downstream filters when an upstream one changes, through
   academicRecords_store_ajax.php, dims the values of a criteria type that
   is not ticked, and shows a small loader beside each filter while a
   refresh is running.

   Added by academicRecords_store.php for step 1 only, with the file's
   modification time as the cache key, so a change here reaches the
   browser without waiting for a module update.
----------------------------------------------------- */

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
