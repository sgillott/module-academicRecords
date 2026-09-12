<?php
/**
 * The student data export for GL Assessment.
 *
 * Builds the rows of the CSV that Testwise imports. Every column is cleaned
 * to a value GL accept, because GL reject a whole row when one value is not
 * one of theirs. Anything that cannot be matched is exported blank.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\Testwise;

use DateTimeImmutable;
use Gibbon\Contracts\Database\Connection;

class StudentExport
{
    /**
     * @var Connection
     */
    private $db;

    /**
     * @param Connection $db Shared database connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * The column headings, in order.
     *
     * These must match the GL Assessment import template exactly. GL reject
     * the file and name the offending columns if any header differs.
     *
     * @return array
     */
    public static function headers(): array
    {
        return [
            'Forename*',
            'Surname*',
            'Unique Identifier*',
            'Date Of Birth*',
            'Gender*',
            'Nationality',
            'Custom 1',
            'Custom 2',
            'English as an additional language',
            'External Reference',
            'Year',
            'Ethnicity',
            'SEND',
            'Group',
            'Date Joined School',
        ];
    }

    /**
     * Rows for the export.
     *
     * @param string|null          $joinedAfterDate Optional Y-m-d lower bound
     *                                              on p.dateStart. Null exports
     *                                              everyone enrolled in the
     *                                              current school year.
     * @param array<string,string> $yearMap         gibbonYearGroupID to Testwise
     *                                              year, from yearMap(). A year
     *                                              group missing from the map
     *                                              falls back to its Gibbon name.
     *
     * @return array
     */
    public function rows(?string $joinedAfterDate = null, array $yearMap = []): array
    {
        $joinedAfterDate = trim((string) $joinedAfterDate);

        $dateFilter = $joinedAfterDate !== ''
            ? "AND p.dateStart IS NOT NULL AND p.dateStart > :joinedAfterDate"
            : '';

        $sql = "SELECT
                    p.gibbonPersonID,
                    p.firstName,
                    p.preferredName,
                    p.surname,
                    p.studentID,
                    p.username,
                    p.dob,
                    p.gender,
                    p.countryOfBirth,
                    p.languageFirst,
                    p.ethnicity,
                    p.dateStart,
                    yg.gibbonYearGroupID,
                    yg.name AS yearName,
                    yg.nameShort AS yearNameShort,
                    fg.nameShort AS formGroup,
                    GROUP_CONCAT(DISTINCT CASE
                        WHEN pdt.document = 'Passport' AND pd.country IS NOT NULL AND pd.country <> ''
                            THEN pd.country
                        ELSE NULL
                    END SEPARATOR '||') AS passportCountries,
                    MAX(CASE WHEN ind.nameShort = 'EAL' THEN 1 ELSE 0 END) AS hasEAL,
                    MAX(CASE WHEN ind.nameShort = 'SEN' THEN 1 ELSE 0 END) AS hasSEND
                FROM gibbonPerson p
                JOIN gibbonStudentEnrolment se ON (se.gibbonPersonID = p.gibbonPersonID)
                JOIN gibbonSchoolYear sy ON (sy.gibbonSchoolYearID = se.gibbonSchoolYearID)
                JOIN gibbonYearGroup yg ON (yg.gibbonYearGroupID = se.gibbonYearGroupID)
                JOIN gibbonFormGroup fg ON (fg.gibbonFormGroupID = se.gibbonFormGroupID)
                LEFT JOIN gibbonPersonalDocument pd
                    ON (pd.foreignTable = 'gibbonPerson'
                    AND pd.foreignTableID = p.gibbonPersonID
                    AND pd.country IS NOT NULL
                    AND pd.country <> '')
                LEFT JOIN gibbonPersonalDocumentType pdt
                    ON (pdt.gibbonPersonalDocumentTypeID = pd.gibbonPersonalDocumentTypeID)
                LEFT JOIN gibbonINPersonDescriptor ipd ON (ipd.gibbonPersonID = p.gibbonPersonID)
                LEFT JOIN gibbonINDescriptor ind ON (ind.gibbonINDescriptorID = ipd.gibbonINDescriptorID)
                WHERE sy.status = 'Current'
                  AND p.status = 'Full'
                  {$dateFilter}
                GROUP BY
                    p.gibbonPersonID, p.firstName, p.preferredName, p.surname, p.studentID, p.username,
                    p.dob, p.gender, p.countryOfBirth, p.languageFirst, p.ethnicity, p.dateStart,
                    yg.gibbonYearGroupID, yg.name, yg.nameShort, yg.sequenceNumber, fg.nameShort
                ORDER BY yg.sequenceNumber, fg.nameShort, p.surname, p.firstName";

        $data = $joinedAfterDate !== '' ? ['joinedAfterDate' => $joinedAfterDate] : [];
        $rows = [];

        foreach ($this->db->select($sql, $data)->fetchAll() as $row) {
            $rows[] = [
                self::forename($row),
                self::clean($row['surname'] ?? ''),
                self::identifier($row),
                self::date($row['dob'] ?? null),
                self::gender($row['gender'] ?? ''),
                Values::validNationality(self::nationality($row)),
                '',
                '',
                self::eal($row),
                '',
                self::clean($yearMap[(string) ($row['gibbonYearGroupID'] ?? '')] ?? ($row['yearName'] ?? '')),
                Values::validEthnicity((string) ($row['ethnicity'] ?? '')),
                !empty($row['hasSEND']) ? Values::validSend('Yes') : '',
                self::clean($row['formGroup'] ?? ''),
                self::joinDate($row),
            ];
        }

        return $rows;
    }

    /**
     * Work out the Testwise year to export for every year group.
     *
     * A confirmed mapping always wins. Where none exists the region rule
     * supplies a value, and where the rule cannot read a number the year
     * group is left out of the map so the caller can report it.
     *
     * @param string               $region     One of the Year::REGION_* values.
     * @param array                $yearGroups Rows from YearGroupMapGateway::selectYearGroups().
     * @param array<string,string> $storedMap  Rows from YearGroupMapGateway::selectMapKeyed().
     *
     * @return array<string,string> gibbonYearGroupID to Testwise year.
     */
    public static function yearMap(string $region, array $yearGroups, array $storedMap): array
    {
        $map = [];
        $suggestions = Year::suggestYearMap($region, $yearGroups);

        foreach ($yearGroups as $yearGroup) {
            $yearGroupID = (string) $yearGroup['gibbonYearGroupID'];
            $stored = trim((string) ($storedMap[$yearGroupID] ?? ''));

            if ($stored !== '' && Year::isValidYear($stored)) {
                $map[$yearGroupID] = $stored;
                continue;
            }

            if (!empty($suggestions[$yearGroupID])) {
                $map[$yearGroupID] = $suggestions[$yearGroupID];
            }
        }

        return $map;
    }

    /**
     * Year groups that have students but no confirmed Testwise mapping.
     *
     * Used to warn before an export, because an unconfirmed year group
     * either exports a guess or falls back to its raw Gibbon name.
     *
     * @param array<string,string> $storedMap Rows from YearGroupMapGateway::selectMapKeyed().
     *
     * @return array List of year group names, in teaching order.
     */
    public function unconfirmedYearGroups(array $storedMap): array
    {
        $sql = "SELECT DISTINCT yg.gibbonYearGroupID, yg.name, yg.sequenceNumber
                FROM gibbonYearGroup yg
                JOIN gibbonStudentEnrolment se ON (se.gibbonYearGroupID = yg.gibbonYearGroupID)
                JOIN gibbonSchoolYear sy ON (sy.gibbonSchoolYearID = se.gibbonSchoolYearID)
                JOIN gibbonPerson p ON (p.gibbonPersonID = se.gibbonPersonID)
                WHERE sy.status = 'Current'
                  AND p.status = 'Full'
                ORDER BY yg.sequenceNumber, yg.name";

        $unconfirmed = [];

        foreach ($this->db->select($sql)->fetchAll() as $row) {
            $yearGroupID = (string) $row['gibbonYearGroupID'];
            $stored = trim((string) ($storedMap[$yearGroupID] ?? ''));

            if ($stored === '' || !Year::isValidYear($stored)) {
                $unconfirmed[] = (string) $row['name'];
            }
        }

        return $unconfirmed;
    }

    /* ---------------------------------------------------------
       Column rules
    --------------------------------------------------------- */

    /**
     * The single forename to export.
     *
     * GL reject a Forename holding more than one name. Gibbon's preferredName
     * is the name a student goes by and is almost always a single name, so it
     * is used ahead of firstName, which often holds every given name. Only
     * the first word is exported either way.
     */
    private static function forename(array $row): string
    {
        $candidates = [
            trim((string) ($row['preferredName'] ?? '')),
            trim((string) ($row['firstName'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            // Split on whitespace only. Hyphenated and apostrophed names such
            // as Anne-Marie or O'Neill are single names and must stay whole.
            $parts = preg_split('/\s+/', $candidate);

            if (!empty($parts[0])) {
                return self::clean($parts[0]);
            }
        }

        return '';
    }

    /**
     * The date joined school, dropped when it cannot be true.
     *
     * GL reject a row whose joining date falls on or before the date of
     * birth. Gibbon allows a start date that predates a student's birth,
     * usually from a bad import, so an impossible date is exported blank.
     */
    private static function joinDate(array $row): string
    {
        $joined = self::date($row['dateStart'] ?? null);
        $dob = trim((string) ($row['dob'] ?? ''));

        if ($joined === '' || $dob === '') {
            return $joined;
        }

        $joinedDate = substr(trim((string) $row['dateStart']), 0, 10);

        return $joinedDate > substr($dob, 0, 10) ? $joined : '';
    }

    private static function identifier(array $row): string
    {
        $studentID = trim((string) ($row['studentID'] ?? ''));
        if ($studentID !== '') {
            return $studentID;
        }

        $username = trim((string) ($row['username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        return (string) ($row['gibbonPersonID'] ?? '');
    }

    /**
     * Format a date for the export.
     *
     * GL expect DD/MM/YYYY and reject MM/DD/YYYY. The source is always a
     * MySQL Y-m-d date column, so it is read explicitly rather than left to
     * DateTimeImmutable to guess a format from.
     *
     * @return string DD/MM/YYYY, or an empty string if there is no usable date.
     */
    private static function date(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));

        return $date === false ? '' : $date->format('d/m/Y');
    }

    private static function gender(string $gender): string
    {
        return match (trim($gender)) {
            'M' => 'Male',
            'F' => 'Female',
            'Other' => 'Other',
            default => 'Unspecified',
        };
    }

    private static function nationality(array $row): string
    {
        $sourceCountry = '';
        $passportCountries = trim((string) ($row['passportCountries'] ?? ''));

        if ($passportCountries !== '') {
            $sourceCountry = trim((string) explode('||', $passportCountries)[0]);
        }

        if ($sourceCountry === '') {
            $sourceCountry = trim((string) ($row['countryOfBirth'] ?? ''));
        }

        if ($sourceCountry === '') {
            return '';
        }

        $mapped = self::countryToNationality($sourceCountry);

        return $mapped !== '' ? $mapped : $sourceCountry;
    }

    private static function eal(array $row): string
    {
        if (!empty($row['hasEAL'])) {
            return 'Yes';
        }

        $languageFirst = strtolower(trim((string) ($row['languageFirst'] ?? '')));
        if ($languageFirst === '' || $languageFirst === 'english') {
            return 'No';
        }

        return 'Yes';
    }

    /**
     * Collapse whitespace in a value.
     */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private static function countryToNationality(string $country): string
    {
        $lookup = [
            'afghanistan' => 'Afghan',
            'albania' => 'Albanian',
            'algeria' => 'Algerian',
            'argentina' => 'Argentine',
            'armenia' => 'Armenian',
            'australia' => 'Australian',
            'austria' => 'Austrian',
            'azerbaijan' => 'Azerbaijani',
            'bahrain' => 'Bahraini',
            'bangladesh' => 'Bangladeshi',
            'belarus' => 'Belarusian',
            'belgium' => 'Belgian',
            'bolivia' => 'Bolivian',
            'bosnia and herzegovina' => 'Bosnian',
            'botswana' => 'Botswanan',
            'brazil' => 'Brazilian',
            'britain' => 'British',
            'bulgaria' => 'Bulgarian',
            'cambodia' => 'Cambodian',
            'cameroon' => 'Cameroonian',
            'canada' => 'Canadian',
            'chile' => 'Chilean',
            'china' => 'Chinese',
            'colombia' => 'Colombian',
            'croatia' => 'Croatian',
            'cuba' => 'Cuban',
            'cyprus' => 'Cypriot',
            'czech republic' => 'Czech',
            'denmark' => 'Danish',
            'dominican republic' => 'Dominican',
            'ecuador' => 'Ecuadorian',
            'egypt' => 'Egyptian',
            'england' => 'English',
            'eritrea' => 'Eritrean',
            'estonia' => 'Estonian',
            'ethiopia' => 'Ethiopian',
            'finland' => 'Finnish',
            'france' => 'French',
            'gambia' => 'Gambian',
            'georgia' => 'Georgian',
            'germany' => 'German',
            'ghana' => 'Ghanaian',
            'greece' => 'Greek',
            'guatemala' => 'Guatemalan',
            'hong kong' => 'Hong Konger',
            'hungary' => 'Hungarian',
            'iceland' => 'Icelandic',
            'india' => 'Indian',
            'indonesia' => 'Indonesian',
            'iran' => 'Iranian',
            'iraq' => 'Iraqi',
            'ireland' => 'Irish',
            'israel' => 'Israeli',
            'italy' => 'Italian',
            'ivory coast' => 'Ivorian',
            'jamaica' => 'Jamaican',
            'japan' => 'Japanese',
            'jordan' => 'Jordanian',
            'kazakhstan' => 'Kazakh',
            'kenya' => 'Kenyan',
            'kuwait' => 'Kuwaiti',
            'latvia' => 'Latvian',
            'lebanon' => 'Lebanese',
            'libya' => 'Libyan',
            'lithuania' => 'Lithuanian',
            'luxembourg' => 'Luxembourger',
            'malaysia' => 'Malaysian',
            'maldives' => 'Maldivan',
            'malta' => 'Maltese',
            'mauritius' => 'Mauritian',
            'mexico' => 'Mexican',
            'morocco' => 'Moroccan',
            'myanmar' => 'Burmese',
            'nepal' => 'Nepalese',
            'netherlands' => 'Dutch',
            'new zealand' => 'New Zealander',
            'nigeria' => 'Nigerian',
            'norway' => 'Norwegian',
            'oman' => 'Omani',
            'pakistan' => 'Pakistani',
            'palestine' => 'Palestinian',
            'peru' => 'Peruvian',
            'philippines' => 'Filipino',
            'poland' => 'Polish',
            'portugal' => 'Portuguese',
            'qatar' => 'Qatari',
            'romania' => 'Romanian',
            'russia' => 'Russian',
            'saudi arabia' => 'Saudi',
            'scotland' => 'Scottish',
            'senegal' => 'Senegalese',
            'serbia' => 'Serbian',
            'singapore' => 'Singaporean',
            'slovakia' => 'Slovak',
            'slovenia' => 'Slovenian',
            'somalia' => 'Somali',
            'south africa' => 'South African',
            'south korea' => 'South Korean',
            'spain' => 'Spanish',
            'sri lanka' => 'Sri Lankan',
            'sudan' => 'Sudanese',
            'sweden' => 'Swedish',
            'switzerland' => 'Swiss',
            'syria' => 'Syrian',
            'taiwan' => 'Taiwanese',
            'tanzania' => 'Tanzanian',
            'thailand' => 'Thai',
            'tunisia' => 'Tunisian',
            'turkey' => 'Turkish',
            'uganda' => 'Ugandan',
            'uk' => 'British',
            'ukraine' => 'Ukrainian',
            'united arab emirates' => 'Emirati',
            'united kingdom' => 'British',
            'united states' => 'American',
            'united states of america' => 'American',
            'uruguay' => 'Uruguayan',
            'uzbekistan' => 'Uzbek',
            'venezuela' => 'Venezuelan',
            'vietnam' => 'Vietnamese',
            'wales' => 'Welsh',
            'yemen' => 'Yemeni',
            'zambia' => 'Zambian',
            'zimbabwe' => 'Zimbabwean',
        ];

        return $lookup[strtolower(trim($country))] ?? '';
    }
}
