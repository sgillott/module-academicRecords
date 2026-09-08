# Gibbon Module: Academic Records Handover Report

## Important References

- Gibbon Ask: <https://ask.gibbonedu.org/>
- Gibbon Documentation: <https://docs.gibbonedu.org/>
- Gibbon Core GitHub Repository: <https://github.com/GibbonEdu/core>

---

# 1. Project Overview

The **Academic Records** module is a custom Gibbon module intended to support academic data storage, retrieval, mapping, and later transcript generation.

The module currently has two main strands of work:

1. **Reporting Cycle Grade Storage**
   - Filter reporting values from the Gibbon Reports module.
   - Preview them.
   - Dry run the intended Internal Assessment writes.
   - Store them into Formal Assessment / Internal Assessment columns and entries.

2. **External Assessment / CAT4 Mapping**
   - Import predicted grade and assessment data from GL Assessment / Testwise CAT4 spreadsheets.
   - Map spreadsheet columns to internal assessment fields.
   - Store the mappings so imported data can later be used in Gibbon, including the Markbook.

---

# 2. Reporting Cycle Grade Storage

## 2.1 Primary File

```text
academicRecords_store.php
```

## 2.2 Current Status

The **Store Grades** workflow is now substantially complete and working as a 4-step wizard:

1. Select Grades to Store
2. Preview
3. Dry Run
4. Store Grades

This part of the module should now be treated as a completed workflow rather than an early scaffold.

## 2.3 What Step 1 Now Does

Step 1 is no longer a basic criteria selector. It now supports:

- reporting cycle selection
- year group filtering
- student filtering
- subject filtering
- criteria type selection
- per-criteria value selection
- explicit blank-value inclusion or exclusion via `(Blank)` in each value list

The step 1 UX is AJAX-assisted:

- upstream filter changes refresh only the affected downstream sections
- the page no longer does a full reload for most filter interactions
- small inline status icons appear beside affected downstream filters while those updates are happening

There is also a setup guard before the page proceeds:

- the module checks that **Academic Records Settings** has a configured `internalAssessmentType`
- if not, the page blocks and links the user to `academicRecords_settings.php`

## 2.4 What Step 2 Now Does

Step 2 shows a preview DataTable of the reporting values that match the selected filters.

It correctly respects:

- year group filters
- student filters
- subject filters
- criteria type filters
- per-value filters
- blank inclusion/exclusion choices

The Back button returns to step 1 and preserves the user’s selections.

The old bulk-action confirmation message has been removed, because the next step is only a dry run.

## 2.5 What Step 3 Now Does

Step 3 performs a dry run of the same storage logic used by step 4.

It now:

- uses the importer-style result summary
- reports inserts, updates, no-change rows, execution time, and memory usage
- provides a Back button to step 2
- provides a `Run Live Store` button only if the dry run is successful

The **Data** panel in step 3 now exists as a collapsed toggle, hidden by default.

When opened, it shows useful diagnostics rather than a raw POST dump:

- transfer format
- filters received
- eligible reporting rows
- storage plan
- warnings
- source dates from reporting rows
- chosen Internal Assessment `completeDate`
- target Internal Assessment column configuration

## 2.6 What Step 4 Now Does

Step 4 uses the same planning logic as step 3, but commits the changes live.

The store logic has been corrected so that:

- one Internal Assessment column is created per course class
- one Internal Assessment entry is created or updated per class/student
- `Grade`-style criteria map to `attainmentValue`
- `Effort`-style criteria map to `effortValue`
- later partial passes are additive rather than destructive

Example:

- a first run that stores attainment only
- followed later by a second run that stores effort only

should now preserve the earlier attainment while adding/updating effort.

## 2.7 Internal Assessment Column Behaviour

The generated Internal Assessment column now follows these rules:

- `attainment` is enabled only when needed, and preserved on later additive runs
- `effort` is enabled only when needed, and preserved on later additive runs
- `comment` is forced to `N`
- `uploadedResponse` remains `N`

The module does **not** enable the comment field on generated Internal Assessment columns.

## 2.8 Stored Date Behaviour

The stored Internal Assessment `completeDate` no longer uses the reporting cycle end date by default.

Instead, it is derived from the selected reporting rows using:

1. `timestampModified`
2. falling back to `timestampCreated`

Because Gibbon reporting values do not have a dedicated assessment date field, this is currently the best available proxy for source date.

If multiple reporting rows in the same class selection have different source dates, the most recent one is used and reported in the dry-run payload.

## 2.9 Reporting Storage Summary

This part of the module is now considered **done for the current phase**.

Working:

- 4-step wizard flow
- setup guard for missing Academic Records settings
- AJAX-driven cascading filters in step 1
- preview with preserved state
- dry run with useful diagnostics
- live run using the same planning logic
- blank-value filtering
- attainment/effort field separation
- additive reruns
- source-date driven IA `completeDate`

Not currently planned as immediate work:

- row-level deselection inside step 2 preview
- custom per-row store selection

Those could still be future enhancements, but they are not current blockers.

---

# 3. Academic Records Settings

## 3.1 Settings Page

```text
academicRecords_settings.php
```

Current behaviour:

- stores the module settings needed by `Store Grades`
- includes a recommendation warning if there is no Internal Assessment Type called `Final Grade`
- links directly to Gibbon’s `formalAssessmentSettings.php`

This page now visually matches the module warning/button style more closely than before.

---

# 4. CAT4 / External Assessment Mapping

## 4.1 Current State

There is a working CAT4 / External Assessment mapping UI.

It currently:

- loads multiple assessments dynamically
- supports assessments such as CAT4, GCSE, and IB
- displays fields grouped by assessment
- allows each field to be mapped to a spreadsheet column
- saves mappings via `academicRecords_cat4MappingProcess.php`
- uses `CAT4MappingService` for default matching

The structure is sound and functioning end to end.

## 4.2 Mapping Architecture

The current separation of responsibilities is correct:

- gateway handles database access
- service handles matching logic
- UI remains generic and reusable across assessments

This architecture should not be changed.

## 4.3 CAT4 Import Guard

The CAT4 import page now has a setup guard similar to `Store Grades`.

It blocks import unless CAT4 mapping has been configured, and links the user to:

```text
academicRecords_cat4Mapping.php
```

## 4.4 Remaining CAT4 / Import Concerns

The mapping UI is in better shape than the actual CAT4 import execution path.

The main concern remains the import-side process logic, not the mapping interface itself.

The separate CAT4 import processing code should be reviewed carefully before it is treated as production-ready.

---

# 5. Fuzzy Matching

The previous handover correctly identified that CAT4 / IB fuzzy matching became overcomplicated.

That assessment still stands.

Recommended direction:

- keep matching generic
- avoid overfitting IB
- favour stable, predictable matching over clever heuristics
- rely on manual override where needed

This remains an open improvement area, but it is no longer the highest priority compared with verifying the CAT4 import process itself.

---

# 6. Remaining Work

## 6.1 Highest Priority Remaining Work

The biggest remaining area is no longer `Store Grades`.

The next major work area is:

1. **CAT4 / external assessment import execution**
   - confirm the process script matches the current mapping schema
   - confirm dry-run/live-run behaviour
   - verify it writes the intended Internal Assessment data safely

## 6.2 Secondary Future Work

Possible future enhancements:

- row-level deselection in the reporting preview
- transcript generation from stored academic records
- richer diagnostics or summary presentation in step 3
- configurable mapping between reporting criteria and IA target fields instead of the current name-based `Effort` detection

---

# 7. Overall Current Status

Completed or Working:

- `Store Grades` 4-step workflow
- Academic Records settings guard
- CAT4 import setup guard
- reporting filtering and preview
- dry run and live run storage flow
- attainment/effort split into IA fields
- blank-value exclusion by default
- source-date based IA completion date
- CAT4 mapping UI
- CAT4 mapping persistence

Needs Further Work:

- CAT4 import processing path
- possible fuzzy matching simplification / stabilization
- later transcript-generation work

---

# 8. Core Mental Model

The Academic Records module is not just a CAT4 import tool or a transcript tool.

It is a broader academic data pipeline:

External data / Reporting data
        ↓
Mapping and filtering
        ↓
Internal assessment storage
        ↓
Later retrieval
        ↓
Transcript and markbook use

At this point, the reporting-storage workflow is in a strong state.

The priority has shifted from stabilising `Store Grades` to stabilising and validating the remaining external import workflow and then continuing toward transcript generation.
