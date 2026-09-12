<?php
/**
 * Builds the academic record of one student.
 *
 * A record is a grid of school years down the page and terms across it.
 * The rows come from course class enrolment, so a course the student is
 * taking now appears with empty cells until its grades are stored. Grades
 * are attached through the stored grade index, which is the only reliable
 * link between a stored grade and the term it belongs to.
 *
 * The transcript PDF and the View Academic Records page both read from
 * here, so what the screen shows is what the transcript prints.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Transcript;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Module\AcademicRecords\Domain\CourseCreditGateway;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;

class RecordBuilder
{
    /**
     * @var TranscriptGateway
     */
    private $gateway;

    /**
     * @var CourseCreditGateway
     */
    private $creditGateway;

    /**
     * @var InterimGrade
     */
    private $interimGrade;

    /**
     * Today, as one value for the whole build, so a record that spans
     * midnight cannot judge two terms against two different dates.
     *
     * @var string
     */
    private $today;

    /**
     * Grade point values keyed by scale then grade, for the current build.
     *
     * @var array
     */
    private $gpaByScale = [];

    /**
     * Whether the student being built has GPA switched on.
     *
     * @var bool
     */
    private $showGPA = false;

    /**
     * Collects every graded term of the current build for the overall GPA.
     *
     * @var GpaCalculator|null
     */
    private $gpaCalculator;

    /**
     * @param Connection $db Shared database connection.
     */
    public function __construct(Connection $db)
    {
        $this->gateway = new TranscriptGateway($db);
        $this->creditGateway = new CourseCreditGateway($db);
        $this->interimGrade = new InterimGrade($db);
        $this->today = date('Y-m-d');
    }

    /**
     * The shape of an empty record.
     *
     * @return array
     */
    public static function empty(): array
    {
        return [
            'classOf' => '',
            'graduationDate' => '',
            'showGPA' => false,
            'gpaOverall' => '',
            'gpaProvisional' => false,
            'termLabels' => [],
            'gradeBands' => [],
            'hasProvisional' => false,
            'years' => [],
        ];
    }

    /**
     * Build the record for one student.
     *
     * @param string $personID       The student.
     * @param array  $anchor         The gibbonSchoolYear row the record is
     *                               anchored on. Years after it are not
     *                               shown, and Class of is counted from it.
     * @param bool   $includeInterim Whether a cell with no stored grade in a
     *                               term being taught is filled from the
     *                               Markbook.
     *
     * @return array
     */
    public function build(string $personID, array $anchor, bool $includeInterim): array
    {
        $empty = self::empty();

        if ($personID === '' || empty($anchor)) {
            return $empty;
        }

        $anchorSeq = (int) $anchor['sequenceNumber'];

        // Which year groups a transcript covers. An empty setting means the
        // school has not chosen, so every year group is included.
        $yearGroupList = trim($this->gateway->getSetting('transcriptYearGroups'));

        $stored = $this->gateway->getStudentValues($personID);
        $graduation = $this->buildGraduation($personID, $anchorSeq, $stored);

        // GPA is per student, because only students applying to American
        // universities need it, and a figure nobody asked for invites
        // comparison between students who were never on the same scale.
        $this->showGPA = ($stored['showGPA'] ?? 'N') === 'Y';
        $this->gpaByScale = $this->showGPA ? $this->creditGateway->selectGpaKeyed() : [];
        $this->gpaCalculator = new GpaCalculator($this->gateway->getSetting('transcriptGpaMethod'));

        $schoolYears = $this->gateway->selectSchoolYears($personID, $anchorSeq, $yearGroupList);

        if (empty($schoolYears)) {
            return $graduation + ['showGPA' => $this->showGPA] + $empty;
        }

        $schoolYearIDs = array_column($schoolYears, 'gibbonSchoolYearID');

        $termsByYear = $this->groupTermsByYear($this->gateway->selectTerms($schoolYearIDs));
        $classes = $this->gateway->selectCourseClasses($personID, $schoolYearIDs);
        $classesByYear = $this->groupClassesByYear($classes);
        $gradesByClassTerm = $this->keyGradesByClassAndTerm($this->gateway->selectStoredGrades($personID, $schoolYearIDs));

        $courseCredit = $this->creditGateway->selectCourseCreditKeyed(array_unique(array_column($classes, 'gibbonCourseID')));
        $gradeCredit = $this->creditGateway->selectGradeCreditKeyed();

        // A cell with no stored grade has no scale of its own, so the scale
        // the student's other grades sit on is the one an interim grade is
        // expressed on.
        $scaleID = $this->resolveScale($gradesByClassTerm);
        $bands = $scaleID !== '' ? $this->creditGateway->selectGradeBands($scaleID) : [];

        if (!empty($bands)) {
            $this->interimGrade->setBands($scaleID, $bands);
        }

        $years = [];

        foreach ($schoolYears as $schoolYear) {
            $schoolYearID = (string) $schoolYear['gibbonSchoolYearID'];
            $terms = $termsByYear[$schoolYearID] ?? [];
            $yearClasses = $classesByYear[$schoolYearID] ?? [];

            // An interim grade only makes sense while the year is still
            // running, and only when this run asked for one. A finished year
            // with a gap has simply not been stored.
            $yearInProgress = !empty($schoolYear['lastDay']) && (string) $schoolYear['lastDay'] >= $this->today;

            $courses = $this->buildCourses(
                $yearClasses,
                $terms,
                $gradesByClassTerm,
                $courseCredit,
                $gradeCredit,
                $personID,
                $includeInterim && $yearInProgress ? $scaleID : ''
            );

            if (empty($courses)) {
                continue;
            }

            $years[] = [
                'gibbonSchoolYearID' => $schoolYearID,
                'name' => (string) $schoolYear['name'],
                'label' => $this->buildYearLabel($schoolYear),
                'yearGroup' => (string) ($schoolYear['yearGroupName'] ?? ''),
                'terms' => array_column($terms, 'name'),
                'courses' => $courses,
            ];
        }

        return $graduation + [
            'showGPA' => $this->showGPA,
            'gpaOverall' => $this->showGPA ? $this->gpaCalculator->result() : '',
            'gpaProvisional' => $this->showGPA && $this->gpaCalculator->isProvisional(),
            'termLabels' => $this->buildTermLabels($termsByYear),
            'gradeBands' => $bands,
            // Counted here, because a Twig set inside a loop does not survive
            // the loop, so the template cannot work this out for itself.
            'hasProvisional' => $this->hasProvisional($years),
            'years' => $years,
        ];
    }

    /**
     * Grade point value and letter for one grade, and feed the overall GPA.
     *
     * @param string $scaleID     Scale the grade sits on.
     * @param string $value       The grade.
     * @param string $credit      Credit the grade earned, as printed.
     * @param bool   $provisional Whether the grade is still in progress.
     *
     * @return array Keys gpa and gpaLetter, both empty when GPA is off for
     *               this student or the grade has no points set.
     */
    private function gpaFor(string $scaleID, string $value, string $credit, bool $provisional): array
    {
        $none = ['gpa' => '', 'gpaLetter' => ''];

        if (!$this->showGPA || $scaleID === '' || $value === '') {
            return $none;
        }

        $entry = $this->gpaByScale[$scaleID][$value] ?? null;

        if ($entry === null) {
            return $none;
        }

        $this->gpaCalculator->add((float) $entry['points'], (float) ($credit !== '' ? $credit : 0), $provisional);

        return [
            'gpa' => number_format((float) $entry['points'], 1, '.', ''),
            'gpaLetter' => (string) $entry['letter'],
        ];
    }

    /**
     * Does any cell carry a grade that is still being taught?
     *
     * @param array $years The built record years.
     *
     * @return bool
     */
    private function hasProvisional(array $years): bool
    {
        foreach ($years as $year) {
            foreach ($year['courses'] as $course) {
                foreach ($course['cells'] as $cell) {
                    if (!empty($cell['provisional'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The scale a record's grades sit on.
     *
     * Taken from the student's own stored grades, so a school is never asked
     * to name it twice. Where a student has grades on more than one scale, the
     * one used most often wins.
     *
     * @param array $gradesByClassTerm Stored grades keyed by class and term.
     *
     * @return string Empty when the student has no stored grade at all.
     */
    private function resolveScale(array $gradesByClassTerm): string
    {
        $counts = [];

        foreach ($gradesByClassTerm as $terms) {
            foreach ($terms as $grade) {
                $scaleID = (string) ($grade['gibbonScaleIDAttainment'] ?? '');

                if ($scaleID !== '') {
                    $counts[$scaleID] = ($counts[$scaleID] ?? 0) + 1;
                }
            }
        }

        if (empty($counts)) {
            return '';
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Class of, and the graduation date, derived unless overridden.
     *
     * The graduating year is found by counting the year groups still ahead of
     * the student and stepping that many school years forward. Class of is the
     * calendar year the graduating school year ends in, which is read from the
     * dates rather than the name, so it survives any naming style.
     *
     * @param string $personID  The student.
     * @param int    $anchorSeq sequenceNumber of the anchor school year.
     * @param array  $stored    Values held against the student, if any.
     *
     * @return array
     */
    private function buildGraduation(string $personID, int $anchorSeq, array $stored): array
    {
        $values = ['classOf' => '', 'graduationDate' => ''];

        $yearGroup = $this->gateway->getYearGroupAt($personID, $anchorSeq);

        if (!empty($yearGroup)) {
            $stepsAhead = $this->gateway->countYearGroupsAbove((int) $yearGroup['sequenceNumber']);
            $graduatingYear = $this->gateway->getSchoolYearBySequence($anchorSeq + $stepsAhead);

            if (!empty($graduatingYear['lastDay'])) {
                $values['classOf'] = date('Y', strtotime((string) $graduatingYear['lastDay']));
                $values['graduationDate'] = (string) $graduatingYear['lastDay'];
            }
        }

        if (!empty($stored['graduationYear'])) {
            $values['classOf'] = (string) $stored['graduationYear'];
        }

        if (!empty($stored['graduationDate'])) {
            $values['graduationDate'] = (string) $stored['graduationDate'];
        }

        return $values;
    }

    /**
     * Column headings for the whole record.
     *
     * The year with the most terms sets the headings, so a school that has
     * changed its term structure still prints one consistent header.
     *
     * @param array $termsByYear Terms grouped by school year.
     *
     * @return array
     */
    private function buildTermLabels(array $termsByYear): array
    {
        $labels = [];

        foreach ($termsByYear as $terms) {
            if (count($terms) > count($labels)) {
                $labels = array_column($terms, 'name');
            }
        }

        return $labels;
    }

    /**
     * Short year heading, such as 24-25, taken from the term dates.
     *
     * @param array $schoolYear Row from gibbonSchoolYear.
     *
     * @return string
     */
    private function buildYearLabel(array $schoolYear): string
    {
        $first = !empty($schoolYear['firstDay']) ? date('y', strtotime((string) $schoolYear['firstDay'])) : '';
        $last = !empty($schoolYear['lastDay']) ? date('y', strtotime((string) $schoolYear['lastDay'])) : '';

        if ($first === '' || $last === '') {
            return (string) $schoolYear['name'];
        }

        return $first . '-' . $last;
    }

    /**
     * One row per course class, with a cell for every term of that year.
     *
     * Where two classes in the same year carry the same course name, the class
     * short name is added, so a mid year class change reads as two rows rather
     * than one repeated line.
     *
     * @param array  $classes           Course classes of one school year.
     * @param array  $terms             Terms of that school year.
     * @param array  $gradesByClassTerm Stored grades keyed by class and term.
     * @param array  $courseCredit      Credit settings keyed by course.
     * @param array  $gradeCredit       Credit share keyed by scale then grade.
     * @param string $personID          The student, for the Markbook lookup.
     * @param string $interimScaleID    Scale to express an interim grade on.
     *                                  Empty switches interim grades off for
     *                                  this year.
     *
     * @return array
     */
    private function buildCourses(array $classes, array $terms, array $gradesByClassTerm, array $courseCredit, array $gradeCredit, string $personID, string $interimScaleID): array
    {
        // A course switched off on the Course Credits page never reaches the
        // record, which is how a school drops its homerooms and its pastoral
        // courses from the printed transcript.
        $classes = array_values(array_filter($classes, function ($class) use ($courseCredit) {
            $settings = $courseCredit[(string) $class['gibbonCourseID']] ?? null;

            return $settings === null || $settings['showOnTranscript'] !== 'N';
        }));

        $nameCounts = array_count_values(array_column($classes, 'courseName'));
        $courses = [];

        foreach ($classes as $class) {
            $classID = (string) $class['gibbonCourseClassID'];
            $courseID = (string) $class['gibbonCourseID'];
            $courseName = (string) $class['courseName'];

            $label = ($nameCounts[$courseName] ?? 0) > 1
                ? $courseName . ' (' . (string) $class['classNameShort'] . ')'
                : $courseName;

            $creditPerTerm = $courseCredit[$courseID]['creditPerTerm'] ?? null;
            $cells = [];

            foreach ($terms as $index => $term) {
                $termID = (string) $term['gibbonSchoolYearTermID'];
                $grade = $gradesByClassTerm[$classID][$termID] ?? null;

                if ($grade !== null) {
                    $value = (string) ($grade['attainmentValue'] ?? '');
                    $credit = $this->earnedCredit($creditPerTerm, $grade, $gradeCredit);

                    $cells[$index] = [
                        'grade' => $value,
                        'descriptor' => (string) ($grade['attainmentDescriptor'] ?? ''),
                        'effort' => (string) ($grade['effortValue'] ?? ''),
                        'effortDescriptor' => (string) ($grade['effortDescriptor'] ?? ''),
                        'credit' => $credit,
                        'percent' => '',
                        'provisional' => false,
                    ] + $this->gpaFor((string) ($grade['gibbonScaleIDAttainment'] ?? ''), $value, $credit, false);

                    continue;
                }

                $cells[$index] = $this->buildInterimCell(
                    $classID,
                    $personID,
                    $term,
                    $interimScaleID,
                    $creditPerTerm,
                    $gradeCredit
                );
            }

            $courses[] = [
                'gibbonCourseID' => $courseID,
                'gibbonCourseClassID' => $classID,
                'label' => $label,
                'courseName' => $courseName,
                'courseNameShort' => (string) $class['courseNameShort'],
                'className' => (string) $class['className'],
                'classNameShort' => (string) $class['classNameShort'],
                'creditPerTerm' => $creditPerTerm !== null ? $this->formatCredit((float) $creditPerTerm) : '',
                'cells' => $cells,
            ];
        }

        return $courses;
    }

    /**
     * A cell with no stored grade.
     *
     * While the term is running, the Markbook can still say how the student
     * is doing, so the cell carries a provisional grade and the credit that
     * grade would earn. Both are flagged, so the template can show that they
     * are not yet a record.
     *
     * @param string      $classID       gibbonCourseClassID.
     * @param string      $personID      The student.
     * @param array       $term          Row from gibbonSchoolYearTerm.
     * @param string      $scaleID       Scale to express the grade on.
     * @param string|null $creditPerTerm Credit the course carries per term.
     * @param array       $gradeCredit   Credit share keyed by scale then grade.
     *
     * @return array
     */
    private function buildInterimCell(string $classID, string $personID, array $term, string $scaleID, ?string $creditPerTerm, array $gradeCredit): array
    {
        $empty = [
            'grade' => '',
            'descriptor' => '',
            'effort' => '',
            'effortDescriptor' => '',
            'credit' => '',
            'percent' => '',
            'provisional' => false,
            'gpa' => '',
            'gpaLetter' => '',
        ];

        $firstDay = (string) ($term['firstDay'] ?? '');

        // Nothing to report on a term that has not started.
        if ($scaleID === '' || $firstDay === '' || $firstDay > $this->today) {
            return $empty;
        }

        $interim = $this->interimGrade->getGrade($classID, $personID, (string) $term['gibbonSchoolYearTermID'], $scaleID);

        if (empty($interim)) {
            return $empty;
        }

        $asStored = [
            'gibbonScaleIDAttainment' => $scaleID,
            'attainmentValue' => $interim['grade'],
        ];

        $credit = $this->earnedCredit($creditPerTerm, $asStored, $gradeCredit);

        return [
            'grade' => (string) $interim['grade'],
            'descriptor' => (string) $interim['descriptor'],
            'effort' => '',
            'effortDescriptor' => '',
            'credit' => $credit,
            'percent' => (string) $interim['percent'],
            'provisional' => true,
        ] + $this->gpaFor($scaleID, (string) $interim['grade'], $credit, true);
    }

    /**
     * Credit earned for one term of one course.
     *
     * A course with no credit configured earns nothing and prints nothing, so
     * a course such as Theory of Knowledge can be listed as studied without
     * claiming credit. A graded course earns its credit times the share the
     * school has set for that grade.
     *
     * @param string|null $creditPerTerm Credit available for one term.
     * @param array|null  $grade         The stored grade, if there is one.
     * @param array       $gradeCredit   Credit share keyed by scale then grade.
     *
     * @return string Empty when there is nothing to print.
     */
    private function earnedCredit(?string $creditPerTerm, ?array $grade, array $gradeCredit): string
    {
        if ($creditPerTerm === null || $grade === null) {
            return '';
        }

        $scaleID = (string) ($grade['gibbonScaleIDAttainment'] ?? '');
        $value = (string) ($grade['attainmentValue'] ?? '');

        if ($scaleID === '' || $value === '') {
            return '';
        }

        // A grade with no share set earns nothing, rather than a full credit.
        $factor = $gradeCredit[$scaleID][$value] ?? 0.0;

        return $this->formatCredit((float) $creditPerTerm * $factor);
    }

    /**
     * Trim a credit to the shortest readable form, so 0.50 prints as 0.5.
     *
     * @param float $credit Credit value.
     *
     * @return string
     */
    private function formatCredit(float $credit): string
    {
        return rtrim(rtrim(number_format($credit, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * @param array $terms Rows from gibbonSchoolYearTerm.
     *
     * @return array
     */
    private function groupTermsByYear(array $terms): array
    {
        $grouped = [];

        foreach ($terms as $term) {
            $grouped[(string) $term['gibbonSchoolYearID']][] = $term;
        }

        return $grouped;
    }

    /**
     * @param array $classes Rows from the course class query.
     *
     * @return array
     */
    private function groupClassesByYear(array $classes): array
    {
        $grouped = [];

        foreach ($classes as $class) {
            $grouped[(string) $class['gibbonSchoolYearID']][] = $class;
        }

        return $grouped;
    }

    /**
     * @param array $grades Rows from the stored grade query, oldest first.
     *
     * @return array
     */
    private function keyGradesByClassAndTerm(array $grades): array
    {
        $keyed = [];

        foreach ($grades as $grade) {
            $classID = (string) $grade['gibbonCourseClassID'];
            $termID = (string) $grade['gibbonSchoolYearTermID'];

            // Rows arrive oldest first, so a later stored column wins.
            $keyed[$classID][$termID] = $grade;
        }

        return $keyed;
    }
}
