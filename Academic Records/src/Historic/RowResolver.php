<?php
/**
 * Turns one spreadsheet row into the target it names, or into a reason.
 *
 * The file speaks in words: a school year name, a semester number, a
 * subject name and a student number. Each of those has to become an ID
 * before anything can be written, and any one of them can fail. A row
 * that fails carries the reason it failed, so the import page can tell
 * the user what to correct rather than only that a row was lost.
 *
 * Nothing here reads the database. The lookups are prefetched once and
 * handed in, so a file of thousands of rows costs no queries at all.
 *
 * Only the checks that one row can answer alone live here. Whether two
 * rows collide, and which scale a class sits on, are answered across the
 * whole file by the importer.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Historic;

use Gibbon\Module\AcademicRecords\Domain\HistoricGradeGateway;

class RowResolver
{
    /**
     * Reasons that stop the live run, because they suggest the wrong file
     * rather than a gap in one row.
     */
    const REASON_UNKNOWN_STUDENT = 'unknownStudent';
    const REASON_UNKNOWN_YEAR = 'unknownYear';
    const REASON_DUPLICATE = 'duplicate';

    /**
     * Reasons that skip one row and let the rest of the file import.
     */
    const REASON_MISSING_FIELD = 'missingField';
    const REASON_UNKNOWN_TERM = 'unknownTerm';
    const REASON_UNKNOWN_COURSE = 'unknownCourse';
    const REASON_AMBIGUOUS_COURSE = 'ambiguousCourse';
    const REASON_NOT_ENROLLED = 'notEnrolled';
    const REASON_AMBIGUOUS_CLASS = 'ambiguousClass';
    const REASON_NO_SCALE = 'noScale';

    /**
     * The fields a row must carry to name a target at all.
     *
     * Grade Level and Credit Awarded are left out on purpose. They are
     * cross checks against what Gibbon already holds, not inputs, so a
     * file without them still imports.
     *
     * @var array
     */
    const REQUIRED_FIELDS = ['studentID', 'year', 'semester', 'subject', 'grade'];

    /**
     * @var array School years keyed by folded name.
     */
    private $schoolYears;

    /**
     * @var array Terms keyed by school year then position.
     */
    private $terms;

    /**
     * @var array Person IDs keyed by folded studentID.
     */
    private $personIDs;

    /**
     * @var array Year groups keyed by person then school year.
     */
    private $yearGroups;

    /**
     * @var array Courses keyed by school year then folded name.
     */
    private $courses;

    /**
     * @var array Class enrolments keyed by person then course.
     */
    private $enrolments;

    /**
     * @param array $lookups Keys schoolYears, terms, personIDs, yearGroups,
     *                       courses and enrolments, as the gateway returns
     *                       them.
     */
    public function __construct(array $lookups)
    {
        $this->schoolYears = $lookups['schoolYears'] ?? [];
        $this->terms = $lookups['terms'] ?? [];
        $this->personIDs = $lookups['personIDs'] ?? [];
        $this->yearGroups = $lookups['yearGroups'] ?? [];
        $this->courses = $lookups['courses'] ?? [];
        $this->enrolments = $lookups['enrolments'] ?? [];
    }

    /**
     * Which of the reason codes stop a live run.
     *
     * @return array
     */
    public static function blockingReasons(): array
    {
        return [
            self::REASON_UNKNOWN_STUDENT,
            self::REASON_UNKNOWN_YEAR,
            self::REASON_DUPLICATE,
        ];
    }

    /**
     * Resolve one row.
     *
     * @param array $row       Row keyed by canonical field name.
     * @param int   $rowNumber Line number in the file, counting the
     *                         heading as line one.
     *
     * @return array The row as read, its status, and once it is ready,
     *               the IDs it resolved to.
     */
    public function resolve(array $row, int $rowNumber): array
    {
        $values = [];

        foreach (['studentID', 'year', 'gradeLevel', 'semester', 'subject', 'grade', 'credit'] as $field) {
            $values[$field] = trim((string) ($row[$field] ?? ''));
        }

        $result = [
            'rowNumber' => $rowNumber,
            'studentID' => $values['studentID'],
            'year' => $values['year'],
            'semester' => $values['semester'],
            'subject' => $values['subject'],
            'grade' => $values['grade'],
            'gradeLevel' => $values['gradeLevel'],
            'credit' => $values['credit'],
            'status' => 'ready',
            'reasonCode' => '',
            'reason' => '',
            'warnings' => [],
        ];

        // A wholly empty line is the tail of a spreadsheet export, not a
        // fault. It is counted apart and never reported as an error.
        if (implode('', $values) === '') {
            $result['status'] = 'blank';

            return $result;
        }

        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if ($values[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return $this->skip($result, self::REASON_MISSING_FIELD, __('The row has no {fields}.', [
                'fields' => implode(', ', array_map([self::class, 'fieldLabel'], $missing)),
            ]));
        }

        $personID = $this->personIDs[HistoricGradeGateway::foldKey($values['studentID'])] ?? '';

        if ($personID === '') {
            return $this->skip($result, self::REASON_UNKNOWN_STUDENT, __('No student in Gibbon has the student ID {id}.', [
                'id' => $values['studentID'],
            ]));
        }

        $schoolYear = $this->schoolYears[HistoricGradeGateway::foldKey($values['year'])] ?? [];

        if (empty($schoolYear)) {
            return $this->skip($result, self::REASON_UNKNOWN_YEAR, __('No school year is named {year}.', [
                'year' => $values['year'],
            ]));
        }

        $schoolYearID = (string) $schoolYear['gibbonSchoolYearID'];
        $position = ctype_digit($values['semester']) ? (int) $values['semester'] : 0;
        $term = $this->terms[$schoolYearID][$position] ?? [];

        if (empty($term)) {
            return $this->skip($result, self::REASON_UNKNOWN_TERM, __('{year} has no term {number}. It has {count}.', [
                'year' => (string) $schoolYear['name'],
                'number' => $values['semester'],
                'count' => count($this->terms[$schoolYearID] ?? []),
            ]));
        }

        $matches = $this->courses[$schoolYearID][HistoricGradeGateway::foldKey($values['subject'])] ?? [];

        if (empty($matches)) {
            return $this->skip($result, self::REASON_UNKNOWN_COURSE, __('{year} has no course named {subject}.', [
                'year' => (string) $schoolYear['name'],
                'subject' => $values['subject'],
            ]));
        }

        if (count($matches) > 1) {
            return $this->skip($result, self::REASON_AMBIGUOUS_COURSE, __('{year} has {count} courses named {subject}, so the row cannot say which one.', [
                'year' => (string) $schoolYear['name'],
                'count' => count($matches),
                'subject' => $values['subject'],
            ]));
        }

        $course = $matches[0];
        $courseID = (string) $course['gibbonCourseID'];
        $classes = $this->enrolments[$personID][$courseID] ?? [];

        if (empty($classes)) {
            return $this->skip($result, self::REASON_NOT_ENROLLED, __('Student {id} is not enrolled in any class of {subject} in {year}, so a grade stored against it would never reach a transcript.', [
                'id' => $values['studentID'],
                'subject' => (string) $course['name'],
                'year' => (string) $schoolYear['name'],
            ]));
        }

        if (count($classes) > 1) {
            return $this->skip($result, self::REASON_AMBIGUOUS_CLASS, __('Student {id} is enrolled in {count} classes of {subject} in {year}, so the row cannot say which one.', [
                'id' => $values['studentID'],
                'count' => count($classes),
                'subject' => (string) $course['name'],
                'year' => (string) $schoolYear['name'],
            ]));
        }

        $class = $classes[0];

        $result['gibbonPersonID'] = $personID;
        $result['gibbonSchoolYearID'] = $schoolYearID;
        $result['schoolYearName'] = (string) $schoolYear['name'];
        $result['gibbonSchoolYearTermID'] = (string) $term['gibbonSchoolYearTermID'];
        $result['termName'] = (string) $term['name'];
        $result['termNameShort'] = (string) $term['nameShort'];
        $result['termLastDay'] = (string) $term['lastDay'];
        $result['gibbonCourseID'] = $courseID;
        $result['courseName'] = (string) $course['name'];
        $result['gibbonCourseClassID'] = (string) $class['gibbonCourseClassID'];
        $result['classNameShort'] = (string) $class['classNameShort'];

        $yearGroupWarning = $this->checkYearGroup($values, $personID, $schoolYearID);

        if ($yearGroupWarning !== '') {
            $result['warnings'][] = $yearGroupWarning;
        }

        return $result;
    }

    /**
     * Mark a row as skipped, with the reason to show the user.
     *
     * @param array  $result  The row so far.
     * @param string $code    One of the REASON_ constants.
     * @param string $message Already translated.
     *
     * @return array
     */
    private function skip(array $result, string $code, string $message): array
    {
        $result['status'] = 'skipped';
        $result['reasonCode'] = $code;
        $result['reason'] = $message;

        return $result;
    }

    /**
     * Does the Grade Level column agree with the student's year group?
     *
     * Compared on the digits alone, so Grade 9, G9 and 9 all agree. A
     * year group with no digit in its name is not checked, because there
     * is nothing to compare.
     *
     * @param array  $values       The row values.
     * @param string $personID     The student.
     * @param string $schoolYearID The school year.
     *
     * @return string Empty when there is nothing to report.
     */
    private function checkYearGroup(array $values, string $personID, string $schoolYearID): string
    {
        if ($values['gradeLevel'] === '') {
            return '';
        }

        $yearGroup = $this->yearGroups[$personID][$schoolYearID] ?? [];

        if (empty($yearGroup)) {
            return __('The student has no year group recorded for this school year, so the Grade Level column could not be checked.');
        }

        $held = preg_replace('/\D+/', '', (string) $yearGroup['nameShort']) ?: preg_replace('/\D+/', '', (string) $yearGroup['name']);
        $stated = preg_replace('/\D+/', '', $values['gradeLevel']);

        if ($held === '' || $stated === '' || (int) $held === (int) $stated) {
            return '';
        }

        return __('The file says Grade Level {stated}, but Gibbon has the student in {held} for this year. The grade is still stored against the class they were enrolled in.', [
            'stated' => $values['gradeLevel'],
            'held' => (string) $yearGroup['name'],
        ]);
    }

    /**
     * The heading a canonical field name came from, for a message.
     *
     * @param string $field Canonical field name.
     *
     * @return string
     */
    public static function fieldLabel(string $field): string
    {
        $labels = [
            'studentID' => __('Student ID'),
            'year' => __('Year'),
            'gradeLevel' => __('Grade Level'),
            'semester' => __('Semester'),
            'subject' => __('Subject Name'),
            'grade' => __('Semester Grade'),
            'credit' => __('Credit Awarded'),
        ];

        return $labels[$field] ?? $field;
    }
}
