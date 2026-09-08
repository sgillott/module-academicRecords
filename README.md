# Academic Records

## Overview

The **Academic Records** module provides a controlled, authoritative way to store final reported grades as long-term academic records within Gibbon.

In many schools, grades entered during report writing represent a teacher’s professional judgement at a specific point in time. These grades are required later for transcripts, graduation checks, credit calculations, and historical reporting. However, report data in Gibbon is inherently cycle-based and may change if reports are regenerated or criteria are modified.

The Academic Records module bridges this gap by allowing schools to **persist reported grades from a reporting cycle into Internal Assessments**, creating a stable academic record that can be safely used beyond the reporting process.

This module is inspired by the “Store Grades” workflow found in other SIS platforms, adapted to Gibbon’s data model and configuration philosophy.

---

## Design Principles

- **Teacher judgement first**  
  The module does not calculate grades. It stores grades that teachers have already entered into reports.

- **Configuration-driven**  
  What constitutes a storable grade is determined by existing reporting configuration, not hard-coded rules.

- **Non-destructive**  
  Reporting data remains untouched. Stored grades live independently as academic records.

- **Reusable across schools**  
  The module adapts to different reporting setups, grading scales, and reporting cycles.

- **Aligned with core Gibbon concepts**  
  Uses Reporting Cycles, Reporting Criteria, and Internal Assessments without modifying core behaviour.

---

## What the Module Does

When an administrator runs the **Store Grades** action, the module:

1. Selects a reporting cycle (for example Semester 1 or Semester 2).
2. Identifies all reporting criteria in that cycle whose **Criteria Type uses a `Grade Scale` value type**.
3. Optionally filters the scope by:
   - Year Group
   - Course
   - Student
4. Copies the reported grade values for each eligible student.
5. Stores those values as **Internal Assessment entries**, creating or updating the corresponding Internal Assessment columns.

The resulting Internal Assessments represent **official semester grades** suitable for transcripts and long-term academic history.

---

## What the Module Does Not Do

- It does **not** calculate grades from markbook percentages.
- It does **not** define grading policies or percentage-to-grade mappings.
- It does **not** replace the Reports module.
- It does **not** award or store credits directly.

Any logic related to grade calculation, moderation, or credit rules remains outside the scope of this module.

---

## How Grades Are Identified

The module relies on **Reporting Criteria Types**, not naming conventions or scopes.

A grade is eligible for storage if:

- The Reporting Criterion belongs to the selected Reporting Cycle.
- The Criterion’s **Criteria Type has `valueType = 'Grade Scale'`**.
- The Criterion target is `Per Student`.

This approach allows the module to work with:

- Subject grades
- Pass/Fail outcomes
- Programme-specific grades (for example CAS or TOK)

Scopes such as PSHE that do not define grade criteria are automatically excluded.

---

## Data Storage Model

Stored grades are written to Gibbon’s **Internal Assessment** tables:

- `gibbonInternalAssessmentColumn`
- `gibbonInternalAssessmentEntry`

Each stored grade:

- Is linked to a course class and school year.
- Preserves the original grading scale via `gibbonScaleID` and `gibbonScaleGradeID`.
- Can be edited later if corrections are required.

Internal Assessments created by this module are intended to serve as the **authoritative source** for transcripts and academic history.

---

## Edit and Overwrite Behaviour

Grades stored by this module are editable, reflecting real-world needs for correction or moderation.

When storing grades:

- Existing Internal Assessment entries for the same course, cycle, and student may be updated.
- The module is designed to be **idempotent**, allowing the Store Grades action to be run more than once safely.

Future versions may include enhanced audit or change-tracking features.

---

## Typical Use Cases

- End-of-semester grade finalisation
- Preparing data for transcript generation
- Preserving historical grades before report regeneration
- Establishing a clean academic record independent of reports

---

## Menu Location

The module is intended to live under:

**Assess → Academic Records**

This reflects its role as an assessment-related, long-term academic data tool rather than a reporting or administrative feature.

---

## Future Extensions

The following features are intentionally out of scope for the initial version, but supported by the design:

- Derived credit calculation
- Graduation requirement checks
- Transcript rendering
- Audit logs and grade history
- Support for additional academic record types

---

## Status

This module is designed as a custom extension to Gibbon core. It does not modify core tables or workflows and can evolve independently alongside Gibbon updates.

---

## License

This module follows the same open-source licensing model as Gibbon.

