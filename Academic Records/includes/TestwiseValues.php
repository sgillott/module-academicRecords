<?php
/**
 * Valid GL Assessment field values, and the checks that enforce them.
 *
 * GL reject an import row when SEND, Ethnicity or Nationality holds a value
 * outside their published lists. Gibbon stores these as free text, so anything
 * that does not match is exported blank rather than guessed at.
 *
 * Source, read 2026-08-30:
 * https://support.gl-assessment.co.uk/knowledge-base/platforms/testwise/troubleshooting/available-send-ethnicity-and-nationality-values
 *
 * @category Module
 * @package  Gibbon\Module\AcademicRecords
 * @author   Steve Gillott
 * @license  https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 * @version  GIT: $Id$
 * @link     https://gibbonedu.org
 */

/**
 * Reduce a value to letters and digits so spacing and punctuation cannot cause
 * a false mismatch. 'White British' and 'White - British' both become
 * 'whitebritish'. Nothing else is inferred.
 *
 * @param string $value Value to reduce.
 *
 * @return string
 */
function testwiseMatchKey(string $value): string
{
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
}

/**
 * Valid ethnicity descriptions mapped to their GL code, where one exists.
 *
 * @return array<string,?string>
 */
function testwiseEthnicityValues(): array
{
    return [
        'Afghan' => 'OAFG',
        'African Asian' => 'AAFR',
        'Alaska Native' => null,
        'Albanian' => 'WALB',
        'American Indian' => null,
        'Any Other Asian Background' => 'AOTH',
        'Any Other Black Background' => 'BOTH',
        'Any Other Ethnic Group' => 'OOTH',
        'Any Other Mixed Background' => 'MOTH',
        'Any Other White Background' => 'WOTH',
        'Arab Other' => 'OARA',
        'Asian' => null,
        'Asian and Any Other Ethnic Group' => 'MAOE',
        'Asian and Black' => 'MABL',
        'Asian and Chinese' => 'MACH',
        'Bangladeshi' => 'ABAN',
        'Black - African' => 'BAFR',
        'Black - Angolan' => 'BANN',
        'Black - Congolese' => 'BCON',
        'Black - Ghanaian' => 'BGHA',
        'Black - Nigerian' => 'BNGN',
        'Black - Sierra Leonean' => 'BSLN',
        'Black - Somali' => 'BSOM',
        'Black - Sudanese' => 'BSUD',
        'Black and Any Other Ethnic Group' => 'MBOE',
        'Black and Chinese' => 'MBCH',
        'Black Caribbean' => 'BCRB',
        'Black European' => 'BEUR',
        'Black North American' => 'BNAM',
        'Black or African American' => null,
        'Bosnian- Herzegovinian' => 'WBOS',
        'Chinese' => 'CHNE',
        'Chinese and Any Other Ethnic Group' => 'MCOE',
        'Croatian' => 'WCRO',
        'Egyptian' => 'OEGY',
        'Filipino' => 'OFIL',
        'Greek' => 'WGRK',
        'Greek Cypriot' => 'WGRC',
        'Greek/ Greek Cypriot' => 'WGRE',
        'Gypsy' => 'WROG',
        'Gypsy / Roma' => 'WROM',
        'Hispanic or Latino' => null,
        'Hong Kong Chinese' => 'CHKC',
        'Indian' => 'AIND',
        'Information Not Yet Obtained' => 'NOBT',
        'Iranian' => 'OIRN',
        'Iraqi' => 'OIRQ',
        'Italian' => 'WITA',
        'Japanese' => 'OJPN',
        'Kashmiri Other' => 'AKAO',
        'Kashmiri Pakistani' => 'AKPA',
        'Korean' => 'OKOR',
        'Kosovan' => 'WKOS',
        'Kurdish' => 'OKRD',
        'Latin/ South/ Central American' => 'OLAM',
        'Lebanese' => 'OLEB',
        'Libyan' => 'OLIB',
        'Malay' => 'OMAL',
        'Malaysian Chinese' => 'CMAL',
        'Mirpuri Pakistani' => 'AMPK',
        'Moroccan' => 'OMRC',
        'Native Hawaiian or Other Pacific Islander' => null,
        'Nepali' => 'ANEP',
        'Not Specified' => null,
        'Other Asian' => 'AOTA',
        'Other Black' => 'BOTB',
        'Other Black African' => 'BAOF',
        'Other Chinese' => 'COCH',
        'Other Ethnic Group' => 'OOEG',
        'Other Gypsy/Roma' => 'WROO',
        'Other Mixed Background' => 'MOTM',
        'Other Pakistani' => 'AOPK',
        'Other White British' => 'WOWB',
        'Pakistani' => 'APKN',
        'Polynesian' => 'OPOL',
        'Portuguese' => 'WPOR',
        'Refused' => 'REFU',
        'Roma' => 'WROR',
        'Serbian' => 'WSER',
        'Singaporean Chinese' => 'CSNG',
        'Sri Lankan Other' => 'ASRO',
        'Sri Lankan Sinhalese' => 'ASNL',
        'Sri Lankan Tamil' => 'ASLT',
        'Taiwanese' => 'CTWN',
        'Thai' => 'OTHA',
        'Traveller of Irish Heritage' => 'WIRT',
        'Turkish' => 'WTUK',
        'Turkish Cypriot' => 'WTUC',
        'Turkish/ Turkish Cypriot' => 'WTUR',
        'Unknown Ethnicity' => null,
        'Vietnamese' => 'OVIE',
        'White' => null,
        'White - British' => 'WBRI',
        'White - Cornish' => 'WCOR',
        'White - English' => 'WENG',
        'White - Irish' => 'WIRI',
        'White - Northern Irish' => 'WNIR',
        'White - Scottish' => 'WSCO',
        'White - Welsh' => 'WWEL',
        'White and Any Other Asian Background' => 'MWAO',
        'White and Any Other Ethnic Group' => 'MWOE',
        'White and Asian' => 'MWAS',
        'White and Black African' => 'MWBA',
        'White and Black Caribbean' => 'MWBC',
        'White and Chinese' => 'MWCH',
        'White and Indian' => 'MWAI',
        'White and Pakistani' => 'MWAP',
        'White Eastern European' => 'WEEU',
        'White European' => 'WEUR',
        'White Other' => 'WOTW',
        'White Western European' => 'WWEU',
        'Yemeni' => 'OYEM',
    ];
}

/**
 * Valid SEND values. GL require these typed in full, exactly as listed.
 *
 * @return array<int,string>
 */
function testwiseSendValues(): array
{
    return [
        'Autistic spectrum disorder (ASD)',
        'E',
        'Hearing impairment (HI)',
        'K',
        'Maybe',
        'Moderate learning difficulty (MLD)',
        'Multisensory impairment (MSI)',
        'N',
        'No',
        'Physical disability (PD)',
        'Profound and multiple learning difficulty (PMLD)',
        'SEND support but no specialist assessment of type of need (NSA)',
        'Severe learning difficulty (SLD)',
        'Social, emotional and mental health (SEMH)',
        'Specific learning difficulties (SPLD)',
        'Speech, language and communication needs (SLCN)',
        'Unknown SEND',
        'Visual impairment (VI)',
        'Yes',
    ];
}

/**
 * Valid nationality values.
 *
 * @return array<int,string>
 */
function testwiseNationalityValues(): array
{
    return [
        'Afghan', 'Afghani', 'Ålandic', 'Ålandish', 'Albanian', 'Algerian',
        'American', 'Andorran', 'Angolan', 'Anguillan', 'Antarctic', 'Antiguan',
        'Argentine', 'Argentinean', 'Argentinian', 'Armenian', 'Arubian',
        'Australian', 'Austrian', 'Azerbaijani', 'Bahameese', 'Bahamian',
        'Bahraini', 'Bajan', 'Bangladeshi', 'Barbadan', 'Barbadian',
        'Barthélemois', 'Basotho', 'Batswana', 'Belarusian', 'Belgian',
        'Belizean', 'Beninese', 'Bermudian', 'Bhutanese', 'Bolivian', 'Bosnian',
        'Botswanan', 'Brazilian', 'British', 'British Virgin Islander',
        'Bruneian', 'Bulgarian', 'Burkinabe', 'Burmese', 'Burundian',
        'Cambodian', 'Cameroonian', 'Canadian', 'Cape Verdean', 'Cayman Islander',
        'Caymanian', 'Central African', 'Chadian', 'Chilean', 'Chinese',
        'Christmas Islander', 'Citizen of Kiribati', 'Citizen of Seychelles',
        'Citizen of Vanuatu', 'Cocos Islandia', 'Cocossian', 'Colombian',
        'Columbian', 'Comoran', 'Congolese', 'Cook Islander', 'Costa Rican',
        'Croat', 'Croatian', 'Cuban', 'Curaçaoan', 'Cymraes', 'Cymro',
        'Cypriot', 'Czech', 'Dane', 'Danish', 'Djibouti', 'Djiboutian',
        'Dominican', 'Dutch', 'East Timorese', 'Ecuadorean', 'Ecuadorian',
        'Ecudorean', 'Egyptian', 'Emirati', 'Emirian', 'English',
        'Equatoguinean', 'Equatorial Guinean', 'Eritrean', 'Estonian',
        'Ethiopian', 'Falkland Islander', 'Faroese', 'Fijian', 'Filipino',
        'Finnish', 'French', 'French Guianese', 'French Polynesian', 'Futunan',
        'Gabonese', 'Gambian', 'Georgian', 'German', 'Ghanaian', 'Ghanian',
        'Gibraltarian', 'Greek', 'Greenlander', 'Greenlandic', 'Grenadian',
        'Guadeloupean', 'Guamanian', 'Guatemalan', 'Guinean', 'Guyanese',
        'Haitian', 'Hayastani', 'Herzegovinian', 'Honduran', 'Hong Konger',
        'Hungarian', 'Icelander', 'Icelandic', 'I-Kiribati', 'Indian',
        'Indonesian', 'Iranian', 'Iraqi', 'Irish', 'Israeli', 'Italian',
        'Ivorian', 'Jamaican', 'Japanese', 'Jordanian', 'Kazakh', 'Kazakhstani',
        'Kenyan', 'Kittian', 'Korean', 'Kosovan', 'Kuwaiti', 'Kyrgyzstani',
        'Lao', 'Laotian', 'Latvian', 'Lebanese', 'Liberian', 'Libyan',
        'Liechtensteiner', 'Lithuanian', 'Luxembourger', 'Macanese',
        'Macedonian', 'Mahoran', 'Malagasy', 'Malawian', 'Malaysian', 'Maldivan',
        'Malian', 'Maltese', 'Manx', 'Marshallese', 'Martinican',
        'Martiniquaís', 'Mauritanian', 'Mauritian', 'Mexican', 'Micronesian',
        'Miquelonnais', 'Moldovan', 'Monacan', 'Monegasque', 'Mongolian',
        'Montenegrin', 'Montserratian', 'Moroccan', 'Mosotho', 'Motswana',
        'Mozambican', 'Myanmarese', 'N/A', 'Namibian', 'Nauruan', 'Nepalese',
        'Nevisian', 'New Caledonian', 'New Caledonians', 'New Zealander',
        'Nicaraguan', 'Nicoya', 'Nigerian', 'Nigerien', 'Niuean', 'Ni-Vanuatu',
        'Norfolk Islander', 'North Korean', 'Northern Irish',
        'Northern Mariana Islander', 'Norwegian', 'Omani', 'Pakistani',
        'Palauan', 'Palestinian', 'Panamanian', 'Papua New Guinean',
        'Paraguayan', 'Peruvian', 'Pitcairn Islander', 'Pole', 'Polish',
        'Portugese', 'Portuguese', 'Prydeinig', 'Puerto Rican', 'Qatari',
        'Romanian', 'Russian', 'Rwandan', 'Rwandese', 'Sahrawi',
        'Saint Helenian', 'Saint Lucian', 'Saint Vincentian', 'Saint-Pierrais',
        'Salvadoran', 'Salvadorean', 'Salvadorian', 'Sammarinese', 'Samoan',
        'Sanmarinese', 'Sao Tomean', 'São Tomean', 'Saudi', 'Saudi Arabian',
        'Scottish', 'Senegalese', 'Serb', 'Serbian', 'Sierra Leonean',
        'Singaporean', 'Slovak', 'Slovakian', 'Slovene', 'Slovenian',
        'Solomon Islander', 'Somali', 'South African', 'South Korean',
        'South Sudanese', 'Spanish', 'Sri Lankan', 'St Helenian', 'St Lucian',
        'Stateless', 'Sudanese', 'Surinamer', 'Surinamese', 'Swazi', 'Swede',
        'Swedish', 'Swiss', 'Syrian', 'Taiwanese', 'Tajik', 'Tanzanian', 'Thai',
        'Timorese', 'Tobagonian', 'Togolese', 'Tokelauan', 'Tongan',
        'Trinidadian', 'Tunisian', 'Turk', 'Turkish', 'Turkmen',
        'Turks and Caicos Islander', 'Tuvaluan', 'Ugandan', 'Ukrainian',
        'Unknown', 'Uruguayan', 'Uzbek', 'Uzbekistani', 'Vatican citizen',
        'Venezuelan', 'Vietnamese', 'Vincentian', 'Virgin Islander',
        'Wallisian', 'Welsh', 'Western Saharan', 'Yemenese', 'Yemeni',
        'Zambian', 'Zimbabwean',
    ];
}

/**
 * Return the ethnicity as GL spell it, or an empty string if it is not theirs.
 *
 * Accepts the description, the code on its own, or the two together as
 * 'Description (CODE)'. Always exports the description form.
 *
 * @param string $value Gibbon's free text ethnicity.
 *
 * @return string
 */
function testwiseValidEthnicity(string $value): string
{
    $key = testwiseMatchKey($value);

    if ($key === '') {
        return '';
    }

    foreach (testwiseEthnicityValues() as $description => $code) {
        if ($key === testwiseMatchKey($description)) {
            return $description;
        }

        if ($code !== null
            && ($key === testwiseMatchKey($code) || $key === testwiseMatchKey($description . $code))
        ) {
            return $description;
        }
    }

    return '';
}

/**
 * Return the nationality as GL spell it, or an empty string if it is not theirs.
 *
 * @param string $value Nationality worked out from the student's documents.
 *
 * @return string
 */
function testwiseValidNationality(string $value): string
{
    return testwiseMatchFromList($value, testwiseNationalityValues());
}

/**
 * Return the SEND value as GL spell it, or an empty string if it is not theirs.
 *
 * @param string $value SEND value to check.
 *
 * @return string
 */
function testwiseValidSend(string $value): string
{
    return testwiseMatchFromList($value, testwiseSendValues());
}

/**
 * Find a value in a list of valid values, ignoring case and punctuation.
 *
 * @param string          $value Value to look for.
 * @param array<int,string> $list  Valid values.
 *
 * @return string The value as GL spell it, or an empty string.
 */
function testwiseMatchFromList(string $value, array $list): string
{
    $key = testwiseMatchKey($value);

    if ($key === '') {
        return '';
    }

    foreach ($list as $valid) {
        if ($key === testwiseMatchKey($valid)) {
            return $valid;
        }
    }

    return '';
}
