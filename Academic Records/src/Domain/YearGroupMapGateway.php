<?php
/**
 * Stored Gibbon year group to Testwise year mappings.
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

class YearGroupMapGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'academicRecordsYearGroupMap';
    private static $primaryKey = 'academicRecordsYearGroupMapID';

    /**
     * Every year group in the school, in teaching order.
     *
     * @return array
     */
    public function selectYearGroups(): array
    {
        $sql = "SELECT gibbonYearGroupID, name, nameShort, sequenceNumber
                FROM gibbonYearGroup
                ORDER BY sequenceNumber, name";

        return $this->db()->select($sql)->fetchAll();
    }

    /**
     * Stored mappings, keyed by gibbonYearGroupID.
     *
     * @return array<string,string>
     */
    public function selectMapKeyed(): array
    {
        $sql = "SELECT gibbonYearGroupID, testwiseYear
                FROM academicRecordsYearGroupMap";

        $rows = $this->db()->select($sql)->fetchAll();
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['gibbonYearGroupID']] = (string) $row['testwiseYear'];
        }

        return $map;
    }

    /**
     * Store one mapping, replacing any existing row for that year group.
     *
     * @param string $yearGroupID  gibbonYearGroupID to map.
     * @param string $testwiseYear Validated value such as 'Y7'.
     *
     * @return bool
     */
    public function saveMapping(string $yearGroupID, string $testwiseYear): bool
    {
        $sql = "INSERT INTO academicRecordsYearGroupMap
                    (gibbonYearGroupID, testwiseYear)
                VALUES (:yearGroupID, :testwiseYear)
                ON DUPLICATE KEY UPDATE testwiseYear = :testwiseYear";

        $data = [
            'yearGroupID' => $yearGroupID,
            'testwiseYear' => $testwiseYear,
        ];

        return $this->db()->statement($sql, $data) !== false;
    }

    /**
     * Remove the mapping for a year group, so the region rule applies again.
     *
     * @param string $yearGroupID gibbonYearGroupID to clear.
     *
     * @return bool
     */
    public function deleteMapping(string $yearGroupID): bool
    {
        $sql = "DELETE FROM academicRecordsYearGroupMap
                WHERE gibbonYearGroupID = :yearGroupID";

        $data = ['yearGroupID' => $yearGroupID];

        return $this->db()->statement($sql, $data) !== false;
    }
}
