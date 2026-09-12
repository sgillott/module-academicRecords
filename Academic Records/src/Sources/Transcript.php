<?php
/**
 * Transcript data source for the Gibbon Reports engine.
 *
 * Name this class in the sources block of a report template component, and
 * the Reports DataFactory will build it. The class name is fully qualified
 * there, which is what makes a module outside Reports able to supply data:
 *
 *   sources:
 *       student: Student
 *       transcript: 'Gibbon\Module\AcademicRecords\Sources\Transcript'
 *
 * The record itself is built by RecordBuilder, which the View Academic
 * Records page also reads, so the screen and the PDF always agree. This
 * class only turns the report context into a student and an anchor year.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Sources;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Module\Reports\DataFactory;
use Gibbon\Module\Reports\DataSource;
use Gibbon\Module\AcademicRecords\Domain\TranscriptGateway;
use Gibbon\Module\AcademicRecords\Transcript\RecordBuilder;

class Transcript extends DataSource
{
    /**
     * @var TranscriptGateway
     */
    private $gateway;

    /**
     * @var RecordBuilder
     */
    private $builder;

    /**
     * The DataFactory passes the shared connection, so the gateways are built
     * here rather than resolved from the container.
     *
     * @param DataFactory $factory The factory building this source.
     * @param Connection  $db      Shared database connection.
     */
    public function __construct(DataFactory $factory, Connection $db)
    {
        parent::__construct($factory, $db);

        $this->gateway = new TranscriptGateway($db);
        $this->builder = new RecordBuilder($db);
    }

    /**
     * Shape of the data, used for the template preview.
     *
     * @return array
     */
    public function getSchema()
    {
        return [
            'classOf' => '2029',
            'graduationDate' => ['date', 'Y-m-d', '+3 years'],
            'showGPA' => true,
            'gpaOverall' => '3.7',
            'gpaProvisional' => false,
            'hasProvisional' => false,
            // Keyed, not a plain list. The Reports mock builder reads any
            // array whose first element is a string as a call to Faker, so a
            // list of labels here would be taken for a formatter name and
            // would break the template preview. Twig loops over both shapes
            // the same way, so the real data still returns a list.
            'termLabels' => [
                'term1' => 'Semester 1',
                'term2' => 'Semester 2',
            ],
            'gradeBands' => [
                0 => ['value' => '7', 'percentMin' => '90.00', 'percentMax' => '100.00', 'descriptor' => 'Excellent'],
                1 => ['value' => '6', 'percentMin' => '80.00', 'percentMax' => '89.00', 'descriptor' => 'Very Good'],
                2 => ['value' => '5', 'percentMin' => '70.00', 'percentMax' => '79.00', 'descriptor' => 'Good'],
            ],
            'years' => [
                0 => [
                    'name' => '2024-2025',
                    'label' => '24-25',
                    'courses' => [
                        0 => [
                            'label' => 'Example Course 9',
                            'courseName' => 'Example Course 9',
                            'className' => 'Example Course 9.1',
                            'creditPerTerm' => '0.5',
                            'cells' => [
                                0 => [
                                    'grade' => ['randomDigit'],
                                    'descriptor' => ['sameAs', 'grade'],
                                    'effort' => 'A',
                                    'credit' => '0.5',
                                    'percent' => '',
                                    'provisional' => false,
                                    'gpa' => '3.7',
                                    'gpaLetter' => 'B',
                                ],
                                1 => [
                                    'grade' => ['randomDigit'],
                                    'descriptor' => ['sameAs', 'grade'],
                                    'effort' => 'B',
                                    'credit' => '0.5',
                                    'percent' => '',
                                    'provisional' => false,
                                    'gpa' => '3.7',
                                    'gpaLetter' => 'B',
                                ],
                            ],
                        ],
                        1 => [
                            'label' => 'Another Course 9',
                            'courseName' => 'Another Course 9',
                            'className' => 'Another Course 9.1',
                            'creditPerTerm' => '0.5',
                            'cells' => [
                                0 => [
                                    'grade' => ['randomDigit'],
                                    'descriptor' => ['sameAs', 'grade'],
                                    'effort' => 'A',
                                    'credit' => '0.5',
                                    'percent' => '',
                                    'provisional' => false,
                                    'gpa' => '3.7',
                                    'gpaLetter' => 'B',
                                ],
                                1 => [
                                    'grade' => ['randomDigit'],
                                    'descriptor' => ['sameAs', 'grade'],
                                    'effort' => 'A',
                                    'credit' => '0.5',
                                    'percent' => '',
                                    'provisional' => false,
                                    'gpa' => '3.7',
                                    'gpaLetter' => 'B',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build the transcript for one student.
     *
     * @param array $ids Identifiers from the report context. Needs
     *                   gibbonStudentEnrolmentID and gibbonReportID. An
     *                   includeInterim of N leaves cells with no stored grade
     *                   empty instead of asking the Markbook. Absent means Y,
     *                   so a run from the Reports module's own Generate page
     *                   prints current marks as before.
     *
     * @return array
     */
    public function getData($ids = [])
    {
        $personID = $this->gateway->getPersonByEnrolment((string) ($ids['gibbonStudentEnrolmentID'] ?? ''));
        $anchor = $this->gateway->getReportSchoolYear((string) ($ids['gibbonReportID'] ?? ''));

        return $this->builder->build(
            $personID,
            $anchor,
            (string) ($ids['includeInterim'] ?? 'Y') !== 'N'
        );
    }
}
