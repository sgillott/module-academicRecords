<?php
/**
 * Testwise year group formatting rules.
 *
 * GL Assessment expect the Year column as <prefix><number>, where the prefix
 * depends on where the school is. Gibbon only stores a country in the System
 * setting 'country', and its country list has a single 'United Kingdom' entry,
 * so England, Scotland and Northern Ireland cannot be told apart from Gibbon
 * data alone. The module therefore keeps its own 'testwiseRegion' setting and
 * only uses 'country' to pick a sensible starting value.
 *
 * These functions never decide the exported value on their own. They produce a
 * suggestion, which a user confirms or corrects on the Testwise Year Groups
 * page. The export reads the confirmed value.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

const TESTWISE_REGION_ENGLAND = 'England';
const TESTWISE_REGION_SCOTLAND = 'Scotland';
const TESTWISE_REGION_NORTHERN_IRELAND = 'Northern Ireland';
const TESTWISE_REGION_ROI = 'Republic of Ireland';
const TESTWISE_REGION_INTERNATIONAL = 'International';

/**
 * The regions GL Assessment distinguish between.
 *
 * @return array<string,string>
 */
function testwiseRegionOptions(): array
{
    return [
        TESTWISE_REGION_ENGLAND => __('England'),
        TESTWISE_REGION_SCOTLAND => __('Scotland'),
        TESTWISE_REGION_NORTHERN_IRELAND => __('Northern Ireland'),
        TESTWISE_REGION_ROI => __('Republic of Ireland'),
        TESTWISE_REGION_INTERNATIONAL => __('International'),
    ];
}

/**
 * Pick a starting region from Gibbon's System 'country' setting.
 *
 * 'United Kingdom' is ambiguous, so England is only a starting point that the
 * user is expected to correct if the school is in Scotland or Northern Ireland.
 *
 * @param string $country Value of the System 'country' setting.
 *
 * @return string One of the TESTWISE_REGION_* values.
 */
function testwiseDefaultRegion(string $country): string
{
    $country = strtolower(trim($country));

    if ($country === 'united kingdom') {
        return TESTWISE_REGION_ENGLAND;
    }

    if ($country === 'ireland') {
        return TESTWISE_REGION_ROI;
    }

    return TESTWISE_REGION_INTERNATIONAL;
}

/**
 * Read any prefix letter and year number out of a year group's names.
 *
 * A prefix is only accepted when the letter sits directly against the number,
 * as in 'Y7', 'P4' or 'S3'. That keeps 'Year 7' from being read as prefix 'Y'
 * plus a stray number, and keeps 'Grade 6' from matching a prefix at all.
 *
 * @param string $name      gibbonYearGroup.name, tried first.
 * @param string $nameShort gibbonYearGroup.nameShort, tried second.
 *
 * @return array{prefix: ?string, number: ?int}
 */
function testwiseParseYearGroup(string $name, string $nameShort): array
{
    $candidates = array_filter([trim($name), trim($nameShort)]);

    foreach ($candidates as $candidate) {
        if (preg_match('/^([PSY])\s*-?\s*(\d{1,2})\b/i', $candidate, $match)) {
            return [
                'prefix' => strtoupper($match[1]),
                'number' => (int) $match[2],
            ];
        }
    }

    foreach ($candidates as $candidate) {
        if (preg_match('/(\d{1,2})/', $candidate, $match)) {
            return ['prefix' => null, 'number' => (int) $match[1]];
        }
    }

    return ['prefix' => null, 'number' => null];
}

/**
 * Is this year group named on the American grade scale?
 *
 * American grades run one year behind English years: Grade 1 and Year 2 are
 * both ages 6 to 7, and Grade 12 and Year 13 are both ages 17 to 18. Detecting
 * the naming scheme lets an international school running American grades get a
 * usable suggestion instead of one that is out by a year.
 *
 * @param string $name      gibbonYearGroup.name.
 * @param string $nameShort gibbonYearGroup.nameShort.
 *
 * @return bool
 */
function testwiseIsGradeScaleName(string $name, string $nameShort): bool
{
    if (preg_match('/\bgrade\b/i', $name) || preg_match('/\bgrade\b/i', $nameShort)) {
        return true;
    }

    // A short name such as 'G6', but not 'GY6' or a bare number.
    return (bool) preg_match('/^G\s*-?\s*\d{1,2}$/i', trim($nameShort));
}

/**
 * Suggest a Testwise year value for a year group in a given region.
 *
 * Returns null when no number can be read from the year group names, which is
 * common for names such as 'Junior Infants' or 'Reception'. Those have to be
 * set by hand on the Testwise Year Groups page.
 *
 * @param string $region    One of the TESTWISE_REGION_* values.
 * @param string $name      gibbonYearGroup.name.
 * @param string $nameShort gibbonYearGroup.nameShort.
 *
 * @return string|null Suggested value such as 'Y7', or null if none can be read.
 */
function testwiseSuggestYear(string $region, string $name, string $nameShort): ?string
{
    $parsed = testwiseParseYearGroup($name, $nameShort);
    $prefix = $parsed['prefix'];
    $number = $parsed['number'];

    if ($number === null) {
        return null;
    }

    switch ($region) {
        case TESTWISE_REGION_SCOTLAND:
            // A Scottish install almost always names its year groups P1-S6
            // already, so an existing prefix is trusted and passed straight
            // through.
            if ($prefix === 'P' || $prefix === 'S') {
                return $prefix . $number;
            }

            // Otherwise the year group is numbered on the English scale, which
            // sits one year behind the Scottish primary numbering: English
            // Year 1 is Primary 2, and English Year 7 is Secondary 1. S6 is
            // the last Scottish year, so anything beyond it is held there.
            if ($number <= 6) {
                return 'P' . ($number + 1);
            }

            return 'S' . min($number - 6, 6);

        case TESTWISE_REGION_NORTHERN_IRELAND:
            // Primary 1-7, then Year 8-14 for post-primary.
            return $number <= 7 ? 'P' . $number : 'Y' . $number;

        case TESTWISE_REGION_INTERNATIONAL:
            // International covers both English-system schools, which need no
            // adjustment, and American-system schools, whose grades run a year
            // behind. The year group name says which scale is in use, so
            // Grade 6 becomes Y7 while Year 6 stays Y6.
            if (testwiseIsGradeScaleName($name, $nameShort)) {
                return 'Y' . ($number + 1);
            }

            return 'Y' . $number;

        case TESTWISE_REGION_ENGLAND:
        case TESTWISE_REGION_ROI:
        default:
            return 'Y' . $number;
    }
}

/**
 * Which naming scheme a year group follows, if any is recognised.
 *
 * Early years names such as 'EY3', 'EYC', 'Reception' or 'Junior Infants'
 * belong to no scheme. Those cannot be read from their name, because a digit
 * in a name like 'EY3' means a stage rather than a year number.
 *
 * @param string $name      gibbonYearGroup.name.
 * @param string $nameShort gibbonYearGroup.nameShort.
 *
 * @return string|null 'grade', 'year', 'scottish', or null if unrecognised.
 */
function testwiseRecognisedScale(string $name, string $nameShort): ?string
{
    if (testwiseIsGradeScaleName($name, $nameShort)) {
        return 'grade';
    }

    $candidates = array_filter([trim($name), trim($nameShort)]);

    foreach ($candidates as $candidate) {
        if (preg_match('/^year\s*-?\s*\d{1,2}$/i', $candidate)) {
            return 'year';
        }

        if (preg_match('/^Y\s*-?\s*\d{1,2}$/i', $candidate)) {
            return 'year';
        }

        if (preg_match('/^[PS]\s*-?\s*\d{1,2}$/i', $candidate)) {
            return 'scottish';
        }
    }

    return null;
}

/**
 * Suggest a Testwise year for every year group, using the school's own order.
 *
 * Year groups whose name follows a known scheme are read from their name.
 * The rest, which are almost always early years classes, are placed by their
 * position in gibbonYearGroup.sequenceNumber instead. The step between
 * sequence number and year number is measured from the year groups that were
 * read successfully, then applied to the ones that were not, so a school whose
 * first Grade sits at sequence 4 has its three earlier classes numbered
 * backwards from there. Nothing is suggested below zero, so the earliest
 * classes settle on the reception year.
 *
 * @param string $region     One of the TESTWISE_REGION_* values.
 * @param array  $yearGroups Rows with gibbonYearGroupID, name, nameShort and
 *                           sequenceNumber.
 *
 * @return array<string,?string> gibbonYearGroupID to suggestion, or null where
 *                               none could be worked out.
 */
function testwiseSuggestYearMap(string $region, array $yearGroups): array
{
    $suggestions = [];
    $unrecognised = [];
    $offsets = [];
    $anchors = [];

    foreach ($yearGroups as $yearGroup) {
        $yearGroupID = (string) $yearGroup['gibbonYearGroupID'];
        $name = (string) $yearGroup['name'];
        $nameShort = (string) $yearGroup['nameShort'];
        $sequence = (int) $yearGroup['sequenceNumber'];

        if (testwiseRecognisedScale($name, $nameShort) === null) {
            $suggestions[$yearGroupID] = null;
            $unrecognised[$yearGroupID] = $sequence;
            continue;
        }

        $value = testwiseSuggestYear($region, $name, $nameShort);
        $suggestions[$yearGroupID] = $value;

        if ($value !== null && preg_match('/^([PSY])(\d{1,2})$/', $value, $match)) {
            $offsets[] = (int) $match[2] - $sequence;
            $anchors[$sequence] = $match[1];
        }
    }

    if (empty($unrecognised) || empty($offsets)) {
        return $suggestions;
    }

    // The most common step wins, so one oddly named year group cannot drag the
    // whole school out of line.
    $counts = array_count_values($offsets);
    arsort($counts);
    $offset = (int) array_key_first($counts);

    ksort($anchors);

    foreach ($unrecognised as $yearGroupID => $sequence) {
        $prefix = 'Y';

        // Borrow the prefix of the nearest year group that was read from its
        // name, so a Scottish school gets P and not Y.
        foreach ($anchors as $anchorSequence => $anchorPrefix) {
            $prefix = $anchorPrefix;

            if ($anchorSequence >= $sequence) {
                break;
            }
        }

        // Y0 is the reception year in England, but Scottish and Northern Irish
        // primary numbering starts at 1 and has nothing below it.
        $floor = $prefix === 'Y' ? 0 : 1;
        $suggestions[$yearGroupID] = $prefix . max($floor, $sequence + $offset);
    }

    return $suggestions;
}

/**
 * Check a Testwise year value is a supported prefix followed by a number.
 *
 * @param string $value Value to check.
 *
 * @return bool
 */
function testwiseIsValidYear(string $value): bool
{
    return (bool) preg_match('/^[PSY]\d{1,2}$/', trim($value));
}

/**
 * Tidy a user-entered Testwise year value before it is validated or stored.
 *
 * @param string $value Raw user input.
 *
 * @return string Uppercased value with spaces and dashes removed.
 */
function testwiseNormaliseYear(string $value): string
{
    return strtoupper(preg_replace('/[\s-]+/', '', trim($value)) ?? '');
}
