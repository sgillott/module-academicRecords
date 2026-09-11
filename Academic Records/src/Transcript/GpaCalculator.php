<?php
/**
 * The overall GPA for a transcript.
 *
 * Two methods are offered, chosen by a setting, because schools differ:
 *
 *   creditWeighted  each grade counts in proportion to the credit it earned,
 *                   which is the usual American convention. A course that
 *                   earned no credit adds nothing.
 *   simpleMean      every graded term counts once, whatever its credit.
 *
 * The figure is provisional if any grade that fed it was.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Transcript;

class GpaCalculator
{
    public const CREDIT_WEIGHTED = 'creditWeighted';
    public const SIMPLE_MEAN = 'simpleMean';

    /**
     * @var string
     */
    private $method;

    /**
     * @var array Rows of points, credit and provisional.
     */
    private $entries = [];

    /**
     * @param string $method One of the method constants. Anything else is
     *                       treated as credit weighted.
     */
    public function __construct(string $method)
    {
        $this->method = $method === self::SIMPLE_MEAN ? self::SIMPLE_MEAN : self::CREDIT_WEIGHTED;
    }

    /**
     * Add one graded term.
     *
     * @param float $points      Grade point value for the grade.
     * @param float $credit      Credit the grade earned. Zero is allowed.
     * @param bool  $provisional Whether the grade is still in progress.
     *
     * @return void
     */
    public function add(float $points, float $credit, bool $provisional): void
    {
        $this->entries[] = [
            'points' => $points,
            'credit' => $credit,
            'provisional' => $provisional,
        ];
    }

    /**
     * The overall GPA, to one decimal place.
     *
     * @return string Empty when nothing has been added, or when every entry
     *                carries zero credit under the credit weighted method.
     */
    public function result(): string
    {
        if (empty($this->entries)) {
            return '';
        }

        if ($this->method === self::SIMPLE_MEAN) {
            $total = array_sum(array_column($this->entries, 'points'));

            return number_format($total / count($this->entries), 1, '.', '');
        }

        $weighted = 0.0;
        $credit = 0.0;

        foreach ($this->entries as $entry) {
            $weighted += $entry['points'] * $entry['credit'];
            $credit += $entry['credit'];
        }

        if ($credit <= 0) {
            return '';
        }

        return number_format($weighted / $credit, 1, '.', '');
    }

    /**
     * Did any grade that fed the figure come from a term still in progress?
     *
     * @return bool
     */
    public function isProvisional(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['provisional']) {
                return true;
            }
        }

        return false;
    }
}
