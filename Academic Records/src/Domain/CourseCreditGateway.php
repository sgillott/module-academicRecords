<?php
/**
 * Credit values, and which courses a transcript prints.
 *
 * Credit is held in two parts, so that no school's rules are built into the
 * code. A course carries a credit for one term, and a grade earns a share of
 * it. One school awards the whole credit at grade 3 and nothing below; another
 * can award half, or use a different scale entirely.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Domain;

use Gibbon\Domain\QueryableGateway;
use Gibbon\Domain\Traits\TableAware;

class CourseCreditGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'academicRecordsCourseCredit';
    private static $primaryKey = 'academicRecordsCourseCreditID';

    /**
     * Courses of one school year, with whatever credit is set against them.
     *
     * A course with no row yet is returned with empty values, so the page can
     * list every course rather than only the ones already configured.
     *
     * @param string $schoolYearID School year to list.
     *
     * @return array
     */
    public function selectCoursesBySchoolYear(string $schoolYearID): array
    {
        $sql = "SELECT co.gibbonCourseID,
                    co.name,
                    co.nameShort,
                    co.gibbonYearGroupIDList,
                    cc.creditPerTerm,
                    cc.showOnTranscript,
                    (SELECT GROUP_CONCAT(yg.nameShort ORDER BY yg.sequenceNumber SEPARATOR ', ')
                     FROM gibbonYearGroup AS yg
                     WHERE FIND_IN_SET(yg.gibbonYearGroupID, co.gibbonYearGroupIDList)) AS yearGroups
                FROM gibbonCourse AS co
                LEFT JOIN academicRecordsCourseCredit AS cc ON (cc.gibbonCourseID = co.gibbonCourseID)
                WHERE co.gibbonSchoolYearID = :schoolYearID
                ORDER BY co.name";

        $data = ['schoolYearID' => $schoolYearID];

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Credit settings for a set of courses, keyed by gibbonCourseID.
     *
     * @param array $courseIDs Courses to read.
     *
     * @return array
     */
    public function selectCourseCreditKeyed(array $courseIDs): array
    {
        if (empty($courseIDs)) {
            return [];
        }

        $placeholders = [];
        $data = [];

        foreach (array_values($courseIDs) as $index => $courseID) {
            $key = 'c' . $index;
            $placeholders[] = ':' . $key;
            $data[$key] = $courseID;
        }

        $sql = "SELECT gibbonCourseID,
                    creditPerTerm,
                    showOnTranscript
                FROM academicRecordsCourseCredit
                WHERE gibbonCourseID IN (" . implode(', ', $placeholders) . ")";

        $rows = $this->db()->select($sql, $data)->fetchAll();
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['gibbonCourseID']] = $row;
        }

        return $keyed;
    }

    /**
     * Store the credit settings for one course.
     *
     * @param string      $courseID         The course.
     * @param string|null $creditPerTerm    Credit for one term, or null for none.
     * @param string      $showOnTranscript Y or N.
     * @param int         $actorID          Who made the change.
     *
     * @return bool
     */
    public function saveCourseCredit(string $courseID, ?string $creditPerTerm, string $showOnTranscript, int $actorID): bool
    {
        $sql = "INSERT INTO academicRecordsCourseCredit
                    (gibbonCourseID, creditPerTerm, showOnTranscript, timestampUpdated, gibbonPersonIDUpdated)
                VALUES (:courseID, :creditPerTerm, :showOnTranscript, NOW(), :actorID)
                ON DUPLICATE KEY UPDATE
                    creditPerTerm = :creditPerTerm,
                    showOnTranscript = :showOnTranscript,
                    timestampUpdated = NOW(),
                    gibbonPersonIDUpdated = :actorID";

        $data = [
            'courseID' => $courseID,
            'creditPerTerm' => $creditPerTerm,
            'showOnTranscript' => $showOnTranscript === 'N' ? 'N' : 'Y',
            'actorID' => $actorID,
        ];

        return $this->db()->statement($sql, $data) !== false;
    }

    /**
     * Scales that a stored grade could be recorded on.
     *
     * @return array
     */
    public function selectActiveScales(): array
    {
        $sql = "SELECT gibbonScaleID,
                    name,
                    nameShort
                FROM gibbonScale
                WHERE active = 'Y'
                ORDER BY name";

        return $this->db()->select($sql)->fetchAll();
    }

    /**
     * Scales that stored grades are actually recorded on.
     *
     * These are the only scales a transcript can read a grade from, so they
     * are the ones worth setting a credit share for. Every other active scale
     * in the school is beside the point until a grade is stored on it.
     *
     * @return array
     */
    public function selectScalesInUse(): array
    {
        $sql = "SELECT DISTINCT s.gibbonScaleID,
                    s.name,
                    s.nameShort
                FROM academicRecordsStoredGrade AS sg
                JOIN gibbonInternalAssessmentColumn AS ic
                    ON (ic.gibbonInternalAssessmentColumnID = sg.gibbonInternalAssessmentColumnID)
                JOIN gibbonScale AS s ON (s.gibbonScaleID = ic.gibbonScaleIDAttainment)
                ORDER BY s.name";

        return $this->db()->select($sql)->fetchAll();
    }

    /**
     * Grades of one scale, with the settings held against each.
     *
     * @param string $scaleID The scale to list.
     *
     * @return array
     */
    public function selectGradesByScale(string $scaleID): array
    {
        $sql = "SELECT sg.gibbonScaleGradeID,
                    sg.gibbonScaleID,
                    sg.value,
                    sg.descriptor,
                    sg.sequenceNumber,
                    gs.creditFactor,
                    gs.percentMin,
                    gs.percentMax,
                    gs.gpaPoints,
                    gs.gpaLetter
                FROM gibbonScaleGrade AS sg
                LEFT JOIN academicRecordsGradeSetting AS gs
                    ON (gs.gibbonScaleID = sg.gibbonScaleID AND gs.value = sg.value)
                WHERE sg.gibbonScaleID = :scaleID
                ORDER BY sg.sequenceNumber";

        $data = ['scaleID' => $scaleID];

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Every credit share, keyed by scale then grade value.
     *
     * @return array
     */
    public function selectGradeCreditKeyed(): array
    {
        $sql = "SELECT gibbonScaleID,
                    value,
                    creditFactor
                FROM academicRecordsGradeSetting
                WHERE creditFactor IS NOT NULL";

        $rows = $this->db()->select($sql)->fetchAll();
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['gibbonScaleID']][(string) $row['value']] = (float) $row['creditFactor'];
        }

        return $keyed;
    }

    /**
     * Grade point values, keyed by scale then grade value.
     *
     * @return array Each entry holds points and letter.
     */
    public function selectGpaKeyed(): array
    {
        $sql = "SELECT gibbonScaleID,
                    value,
                    gpaPoints,
                    gpaLetter
                FROM academicRecordsGradeSetting
                WHERE gpaPoints IS NOT NULL";

        $rows = $this->db()->select($sql)->fetchAll();
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['gibbonScaleID']][(string) $row['value']] = [
                'points' => (float) $row['gpaPoints'],
                'letter' => (string) ($row['gpaLetter'] ?? ''),
            ];
        }

        return $keyed;
    }

    /**
     * Percentage bands of one scale, highest band first.
     *
     * A band turns a Markbook percentage into a grade, which is how a course
     * still being taught gets a grade before one has been stored.
     *
     * @param string $scaleID The scale to read.
     *
     * @return array
     */
    public function selectGradeBands(string $scaleID): array
    {
        $sql = "SELECT gs.value,
                    gs.percentMin,
                    gs.percentMax,
                    sg.descriptor
                FROM academicRecordsGradeSetting AS gs
                JOIN gibbonScaleGrade AS sg
                    ON (sg.gibbonScaleID = gs.gibbonScaleID AND sg.value = gs.value)
                WHERE gs.gibbonScaleID = :scaleID
                    AND gs.percentMin IS NOT NULL
                    AND gs.percentMax IS NOT NULL
                ORDER BY gs.percentMin DESC";

        $data = ['scaleID' => $scaleID];

        return $this->db()->select($sql, $data)->fetchAll();
    }

    /**
     * Store the settings for one grade of one scale.
     *
     * A null in any field means the school has not set it, and is kept apart
     * from a zero, which is a deliberate choice.
     *
     * @param string      $scaleID      The scale.
     * @param string      $value        The grade value.
     * @param string|null $creditFactor Share of the course credit, 0 to 1.
     * @param string|null $percentMin   Lowest percentage for this grade.
     * @param string|null $percentMax   Highest percentage for this grade.
     * @param string|null $gpaPoints    Grade point value, such as 4.00.
     * @param string|null $gpaLetter    Letter on the GPA scale, such as A.
     *
     * @return bool
     */
    public function saveGradeSetting(string $scaleID, string $value, ?string $creditFactor, ?string $percentMin, ?string $percentMax, ?string $gpaPoints, ?string $gpaLetter): bool
    {
        $sql = "INSERT INTO academicRecordsGradeSetting
                    (gibbonScaleID, value, creditFactor, percentMin, percentMax, gpaPoints, gpaLetter)
                VALUES (:scaleID, :value, :creditFactor, :percentMin, :percentMax, :gpaPoints, :gpaLetter)
                ON DUPLICATE KEY UPDATE
                    creditFactor = :creditFactor,
                    percentMin = :percentMin,
                    percentMax = :percentMax,
                    gpaPoints = :gpaPoints,
                    gpaLetter = :gpaLetter";

        $data = [
            'scaleID' => $scaleID,
            'value' => $value,
            'creditFactor' => $creditFactor,
            'percentMin' => $percentMin,
            'percentMax' => $percentMax,
            'gpaPoints' => $gpaPoints,
            'gpaLetter' => $gpaLetter,
        ];

        return $this->db()->statement($sql, $data) !== false;
    }

    /**
     * Remove every setting for one grade, so nothing is held against it.
     *
     * @param string $scaleID The scale.
     * @param string $value   The grade value.
     *
     * @return bool
     */
    public function deleteGradeSetting(string $scaleID, string $value): bool
    {
        $sql = "DELETE FROM academicRecordsGradeSetting
                WHERE gibbonScaleID = :scaleID
                    AND value = :value";

        $data = [
            'scaleID' => $scaleID,
            'value' => $value,
        ];

        return $this->db()->statement($sql, $data) !== false;
    }
}
