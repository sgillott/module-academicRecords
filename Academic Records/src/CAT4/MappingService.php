<?php
/**
 * Suggests a spreadsheet column for each External Assessment field.
 *
 * A saved mapping always wins. Otherwise the field name is matched against
 * the known headings for the assessment type, with a confidence level so the
 * page can show which suggestions need checking.
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

namespace Gibbon\Module\AcademicRecords\CAT4;

class MappingService
{
    private array $headerSets;

    public function __construct()
    {
        $this->headerSets = HeaderSets::all();
    }

    public function buildHeaderOptionsForAssessment(string $type): array
    {
        $headers = $this->getHeadersForAssessmentType($type);

        $options = ['' => __('Select a spreadsheet column')];

        foreach ($headers as $header) {
            $header = trim((string) $header);

            if ($header === '') {
                continue;
            }

            $options[$header] = $header;
        }

        return $options;
    }

    public function guessAssessmentType(string $title): string
    {
        $title = strtolower(trim($title));

        if (str_contains($title, 'cat4')) {
            return 'ks3';
        }

        if (str_contains($title, 'gcse') || str_contains($title, 'igcse')) {
            return 'gcse_grade';
        }

        if (str_contains($title, 'ib diploma') || str_contains($title, 'ibdp') || str_contains($title, 'ib dp')) {
            return 'ibdp';
        }

        if (str_contains($title, 'myp')) {
            return 'myp';
        }

        if (str_contains($title, 'as level') || str_contains($title, 'aslevel') || preg_match('/\bas\b/', $title)) {
            return 'aslevel';
        }

        if (str_contains($title, 'a level') || str_contains($title, 'alevel')) {
            return 'alevel';
        }

        return 'ks3';
    }

    public function getHeadersForAssessmentType(string $type): array
    {
        return $this->headerSets[$type] ?? [];
    }

    public function buildSavedMappingLookup(array $mappingRows): array
    {
        $lookup = [];

        foreach ($mappingRows as $row) {
            $fieldID = (int) ($row['gibbonExternalAssessmentFieldID'] ?? 0);
            if ($fieldID <= 0) {
                continue;
            }

            $lookup[$fieldID] = [
                'headerPattern' => (string) ($row['headerPattern'] ?? ''),
                'active' => (string) ($row['active'] ?? 'Y'),
            ];
        }

        return $lookup;
    }

    public function getDefaultHeaderForField(array $field, array $headerOptions, array $savedMappings): string
    {
        return $this->getSuggestedHeaderMatch($field, $headerOptions, $savedMappings)['header'];
    }

    public function getSuggestedHeaderMatch(array $field, array $headerOptions, array $savedMappings): array
    {
        $fieldID = (int) ($field['gibbonExternalAssessmentFieldID'] ?? 0);

        if ($fieldID > 0 && !empty($savedMappings[$fieldID]['headerPattern'])) {
            return [
                'header' => (string) $savedMappings[$fieldID]['headerPattern'],
                'confidenceLevel' => 'high',
                'confidenceLabel' => __('Saved'),
                'matchType' => 'saved',
            ];
        }

        $headers = array_keys(array_filter(
            $headerOptions,
            fn ($key) => $key !== '',
            ARRAY_FILTER_USE_KEY
        ));

        return $this->fuzzyMatchWithConfidence((string) ($field['name'] ?? ''), $headers);
    }

    public function getDefaultActiveForField(array $field, array $savedMappings): string
    {
        $fieldID = (int) ($field['gibbonExternalAssessmentFieldID'] ?? 0);

        if ($fieldID > 0 && isset($savedMappings[$fieldID]['active'])) {
            return $savedMappings[$fieldID]['active'] === 'N' ? 'N' : 'Y';
        }

        return 'Y';
    }

    public function shouldSkipField(array $field): bool
    {
        $category = strtolower(trim((string) ($field['category'] ?? '')));
        $name = strtolower(trim((string) ($field['name'] ?? '')));

        if (str_contains($category, 'final grade')) {
            return true;
        }

        if ($name === 'final grade') {
            return true;
        }

        return false;
    }

    public function fuzzyMatch(string $fieldName, array $headers): string
    {
        return $this->fuzzyMatchWithConfidence($fieldName, $headers)['header'];
    }

    public function fuzzyMatchWithConfidence(string $fieldName, array $headers): array
    {
        $fieldNeedle = $this->normalise($fieldName);
        $fieldLower = strtolower($fieldNeedle);
        $fieldLevel = $this->detectLevel($fieldLower);
        $fieldLanguageGroup = $this->detectIBLanguageGroup($fieldLower);
        $fieldIBGroup = $this->detectIBSubjectGroup($fieldLower);

        $subjectPhrases = $this->extractSubjectPhrases($fieldLower);

        $bestHeader = '';
        $bestScore = 0;
        $bestMatchType = 'none';

        foreach ($headers as $header) {
            $headerLower = $this->normalise($header);
            $headerLevel = $this->detectLevel($headerLower);
            $score = 0;
            $matchType = 'similarity';
            $subjectMatched = false;

            foreach ($subjectPhrases as $subjectPhrase) {
                if ($subjectPhrase === '') {
                    continue;
                }

                if (str_contains($headerLower, $subjectPhrase)) {
                    $score += 140 + (strlen($subjectPhrase) * 2);
                    $matchType = 'subject';
                    $subjectMatched = true;
                    break;
                }
            }

            if ($fieldLevel !== '') {
                if ($subjectMatched) {
                    if ($headerLevel === $fieldLevel) {
                        $score += 80;
                    } elseif ($headerLevel === '') {
                        $score += 25;
                    } else {
                        $score -= 80;
                    }
                } else {
                    if ($headerLevel === $fieldLevel) {
                        $score += 20;
                    } elseif ($headerLevel !== '') {
                        $score -= 20;
                    }
                }
            }

            if ($fieldLanguageGroup !== '') {
                if ($fieldLanguageGroup === 'g1') {
                    if (str_contains($headerLower, 'g1') || str_contains($headerLower, 'studies in language and literature')) {
                        $score += 120;
                    } elseif (str_contains($headerLower, 'g2') || str_contains($headerLower, 'language acquisition')) {
                        $score -= 120;
                    }
                }

                if ($fieldLanguageGroup === 'g2') {
                    if (str_contains($headerLower, 'g2') || str_contains($headerLower, 'language acquisition')) {
                        $score += 120;
                    } elseif (str_contains($headerLower, 'g1') || str_contains($headerLower, 'studies in language and literature')) {
                        $score -= 120;
                    }
                }
            }

            if ($fieldIBGroup !== '') {
                if (str_contains($headerLower, $fieldIBGroup)) {
                    $score += 90;
                }
            }

            $headerNeedle = $this->normaliseForComparison($header);
            $comparisonScore = 0;
            similar_text($fieldNeedle, $headerNeedle, $comparisonScore);
            $score += $comparisonScore;

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestHeader = $header;
                $bestMatchType = $matchType;
            }
        }

        if ($bestMatchType === 'subject' && $bestHeader !== '') {
            return [
                'header' => $bestHeader,
                'confidenceLevel' => 'high',
                'confidenceLabel' => __('High'),
                'matchType' => 'subject',
            ];
        }

        if ($bestScore >= 75 && $bestHeader !== '') {
            return [
                'header' => $bestHeader,
                'confidenceLevel' => 'high',
                'confidenceLabel' => __('High'),
                'matchType' => 'similarity',
            ];
        }

        if ($bestScore >= 50 && $bestHeader !== '') {
            return [
                'header' => $bestHeader,
                'confidenceLevel' => 'medium',
                'confidenceLabel' => __('Medium'),
                'matchType' => 'similarity',
            ];
        }

        return [
            'header' => '',
            'confidenceLevel' => 'missing',
            'confidenceLabel' => __('Needs Mapping'),
            'matchType' => 'none',
        ];
    }

    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));

        $map = [
            '&' => ' and ',
            '/' => ' ',
            '-' => ' ',
            '_' => ' ',
            'art design' => 'art',
            'art & design' => 'art',
            'design & tech' => 'design technology',
            'd&t' => 'design technology',
            'dt' => 'design technology',
            'business management' => 'business and management',
            'english language' => 'english language',
            'english literature' => 'english literature',
            'math' => 'maths',
            'mathematics' => 'maths',
            'theatre arts' => 'theatre studies',
            'drama' => 'theatre studies',
            'physical education' => 'pe',
            'combined science' => 'science combined',
            'science double award' => 'science combined',
            'double science' => 'science combined',
            'average sas' => 'mean sas',
        ];

        $value = strtr($value, $map);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim((string) $value);
    }

    private function extractSubjectPhrases(string $fieldLower): array
    {
        $subject = preg_replace(
            '/\b(ib|dp|myp|g1|g2|g3|g4|g5|hl|sl|a|b|studies|study|language|literature|acquisition|individuals|societies|sciences|theory|of|knowledge|total|diploma|points)\b/',
            ' ',
            $fieldLower
        );

        $subject = preg_replace('/\s+/', ' ', (string) $subject);
        $subject = trim((string) $subject);

        $phrases = [];

        if ($subject !== '') {
            $phrases[] = $subject;
        }

        $aliases = [
            'maths' => ['maths', 'mathematics'],
            'business and management' => ['business and management', 'business management'],
            'design technology' => ['design technology', 'design and technology'],
            'theatre studies' => ['theatre studies', 'theatre arts', 'drama'],
            'visual arts' => ['visual arts', 'art'],
            'pe' => ['pe', 'physical education'],
        ];

        foreach ($aliases as $canonical => $variants) {
            if ($subject === $canonical || in_array($subject, $variants, true)) {
                foreach ($variants as $variant) {
                    if (!in_array($variant, $phrases, true)) {
                        $phrases[] = $variant;
                    }
                }
            }
        }

        usort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a));

        return array_values(array_filter(array_unique($phrases)));
    }

    private function detectLevel(string $value): string
    {
        if (str_contains($value, ' hl')) {
            return 'hl';
        }

        if (str_contains($value, ' sl')) {
            return 'sl';
        }

        return '';
    }

    private function detectIBLanguageGroup(string $value): string
    {
        if (preg_match('/\bab initio\b/', $value)) {
            return 'g2';
        }

        if (preg_match('/\blanguage a\b/', $value) || preg_match('/\b[a-z]+\s+a\b/', $value)) {
            return 'g1';
        }

        if (preg_match('/\blanguage b\b/', $value) || preg_match('/\b[a-z]+\s+b\b/', $value)) {
            return 'g2';
        }

        return '';
    }

    private function detectIBSubjectGroup(string $value): string
    {
        if (str_contains($value, 'economics') || str_contains($value, 'geography') || str_contains($value, 'history')) {
            return 'g3';
        }

        if (str_contains($value, 'chemistry') || str_contains($value, 'physics') || str_contains($value, 'biology')) {
            return 'g4';
        }

        if (str_contains($value, 'maths') || str_contains($value, 'mathematics')) {
            return 'g5';
        }

        return '';
    }

    private function normaliseForComparison(string $header): string
    {
        $header = $this->normalise($header);

        $remove = [
            'ks3 ',
            'gcse ',
            'as ',
            'a ',
            'ib myp ',
            'ib dp ',
            ' if challenged',
            ' level',
            ' point score',
            ' fine level',
            ' grade',
            ' points scale',
        ];

        $header = str_replace($remove, '', $header);
        $header = preg_replace('/\s+/', ' ', $header);

        return trim((string) $header);
    }

    public function guessHeaderTypeForField(array $field, string $assessmentName): string
    {
        $assessment = strtolower($assessmentName);
        $fieldName = $this->normalise((string) ($field['name'] ?? ''));

        if (str_contains($assessment, 'cat4') || str_contains($assessment, 'cognitive abilities')) {
            if ($this->isCAT4ScoreFieldName($fieldName)) {
                return 'cat4_scores';
            }

            $category = strtolower((string) ($field['category'] ?? ''));

            if (str_contains($category, 'ks3')) {
                return 'ks3';
            }

            if (str_contains($category, 'gcse') || str_contains($category, 'igcse')) {
                return 'gcse_grade';
            }

            if (str_contains($category, 'as')) {
                return 'aslevel';
            }

            if (str_contains($category, 'a level')) {
                return 'alevel';
            }

            return 'ks3';
        }

        if (str_contains($assessment, 'gcse') || str_contains($assessment, 'igcse')) {
            return 'gcse_grade';
        }

        if (str_contains($assessment, 'ib')) {
            return 'ibdp';
        }

        return 'ks3';
    }

    private function isCAT4ScoreFieldName(string $fieldName): bool
    {
        // Field names are compared after normalise(), which collapses hyphens
        // and underscores to spaces and maps "average sas" to "mean sas".
        $cat4ScoreFields = [
            'mean sas',
            'verbal',
            'quantitative',
            'non verbal',
            'spatial',
        ];

        return in_array($fieldName, $cat4ScoreFields, true);
    }
}
