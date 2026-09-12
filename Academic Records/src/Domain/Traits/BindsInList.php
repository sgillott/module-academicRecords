<?php
/**
 * Builds a bound IN list, because the list length varies per call.
 *
 * A bound list, not FIND_IN_SET: the ID columns are ZEROFILL, and
 * FIND_IN_SET would compare their padded string form.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Domain\Traits;

trait BindsInList
{
    /**
     * Placeholders and bindings for an IN list.
     *
     * @param array  $values Values to bind.
     * @param string $prefix Placeholder prefix, unique within one statement.
     * @param bool   $asInt  Cast each value to int, for IDs from a request.
     *
     * @return array The placeholder string, then the bindings.
     */
    private function inList(array $values, string $prefix, bool $asInt = false): array
    {
        $placeholders = [];
        $data = [];

        foreach (array_values($values) as $index => $value) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $data[$key] = $asInt ? (int) $value : $value;
        }

        return [implode(', ', $placeholders), $data];
    }
}
