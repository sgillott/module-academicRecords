<?php
/**
 * A grade for a term that is still being taught.
 *
 * A school has to be able to print a transcript at any point in the year with
 * the most recent information on it. Until a grade is stored, the best source
 * is the Markbook, so this asks the Markbook module for the weighted average
 * it already works out, and turns that percentage into a grade using the bands
 * held against the scale.
 *
 * The weighting is not repeated here. Gibbon's own MarkbookView applies the
 * column weighting, the type weighting and the raw attainment setting, and
 * groups by term. Whatever a teacher sees in the Markbook is what appears here.
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
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\Markbook\MarkbookView;
use Gibbon\Services\ModuleLoader;

class InterimGrade
{
    /**
     * @var Connection
     */
    private $db;

    /**
     * Percentage bands, keyed by scale then ordered highest first.
     *
     * @var array
     */
    private $bandsByScale = [];

    /**
     * One MarkbookView per class, because building one runs several queries
     * and a transcript reads the same class for every term of a year.
     *
     * @var array
     */
    private $views = [];

    /**
     * Set once the Markbook module has been added to the autoloader.
     *
     * @var bool|null
     */
    private $markbookReady;

    /**
     * @param Connection $db Shared database connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Percentage bands to use for a scale.
     *
     * @param string $scaleID The scale.
     * @param array  $bands   Rows of value, percentMin and percentMax,
     *                        highest band first.
     *
     * @return void
     */
    public function setBands(string $scaleID, array $bands): void
    {
        $this->bandsByScale[$scaleID] = $bands;
    }

    /**
     * Does this scale have bands set?
     *
     * @param string $scaleID The scale.
     *
     * @return bool
     */
    public function hasBands(string $scaleID): bool
    {
        return !empty($this->bandsByScale[$scaleID]);
    }

    /**
     * Work out a grade for one student, class and term.
     *
     * @param string $classID  gibbonCourseClassID being taught.
     * @param string $personID The student.
     * @param string $termID   gibbonSchoolYearTermID in progress.
     * @param string $scaleID  Scale the grade should be expressed on.
     *
     * @return array Empty when there is nothing to work from. Otherwise the
     *               grade, its descriptor and the percentage behind it.
     */
    public function getGrade(string $classID, string $personID, string $termID, string $scaleID): array
    {
        if (!$this->hasBands($scaleID)) {
            return [];
        }

        $view = $this->getView($classID, $personID);

        if ($view === null) {
            return [];
        }

        $percent = $view->getTermAverage($personID, $termID);

        // An empty average means the class has no completed marked work in
        // that term, so there is nothing honest to print.
        if ($percent === '' || $percent === null || !is_numeric($percent)) {
            return [];
        }

        $band = $this->findBand($scaleID, (float) $percent);

        if (empty($band)) {
            return [];
        }

        return [
            'grade' => (string) $band['value'],
            'descriptor' => (string) ($band['descriptor'] ?? ''),
            'percent' => round((float) $percent, 1),
        ];
    }

    /**
     * The band a percentage falls in.
     *
     * @param string $scaleID The scale.
     * @param float  $percent The weighted average.
     *
     * @return array Empty when the percentage falls outside every band.
     */
    private function findBand(string $scaleID, float $percent): array
    {
        foreach ($this->bandsByScale[$scaleID] as $band) {
            if ($percent >= (float) $band['percentMin'] && $percent <= (float) $band['percentMax']) {
                return $band;
            }
        }

        return [];
    }

    /**
     * A Markbook view for one class, with its weightings already gathered.
     *
     * @param string $classID  gibbonCourseClassID.
     * @param string $personID The student.
     *
     * @return MarkbookView|null Null when the Markbook module cannot be reached.
     */
    private function getView(string $classID, string $personID): ?MarkbookView
    {
        $key = $classID . ':' . $personID;

        if (array_key_exists($key, $this->views)) {
            return $this->views[$key];
        }

        $container = $this->container();

        if ($container === null || !$this->markbookLoaded($container)) {
            return $this->views[$key] = null;
        }

        try {
            $view = new MarkbookView(
                $container->get('config'),
                $this->db,
                $classID,
                $container->get(SettingGateway::class)
            );

            $view->cacheWeightings($personID);
        } catch (\Throwable $e) {
            return $this->views[$key] = null;
        }

        return $this->views[$key] = $view;
    }

    /**
     * Add the Markbook module to the autoloader, once.
     *
     * @param object $container The service container.
     *
     * @return bool
     */
    private function markbookLoaded($container): bool
    {
        if ($this->markbookReady === null) {
            try {
                $this->markbookReady = $container->get(ModuleLoader::class)->registerModuleNamespace('Markbook');
            } catch (\Throwable $e) {
                $this->markbookReady = false;
            }
        }

        return $this->markbookReady;
    }

    /**
     * The service container.
     *
     * A report data source is built by the Reports DataFactory, which passes
     * only a database connection, so the container is reached the same way
     * the Markbook module itself reaches the session.
     *
     * @return object|null
     */
    private function container()
    {
        global $container;

        return is_object($container) ? $container : null;
    }
}
