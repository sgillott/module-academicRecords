<?php

// Basic variables
$name        = 'Academic Records';
$description = 'Stores reported grade scale outcomes as authoritative academic records for long-term use.';
$entryURL    = 'academicRecords_store.php';
$type        = 'Additional';
$category    = 'Assess';
$version     = '0.6.00';
$author      = 'Steve Gillott';
$url         = '';

// -----------------------------------------------------
// Module tables & settings
// -----------------------------------------------------

$moduleTables = [

"CREATE TABLE `academicRecordsCAT4Mapping` (
  `academicRecordsCAT4MappingID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonExternalAssessmentID` int(4) unsigned zerofill NOT NULL,
  `name` varchar(60) NOT NULL,
  `studentMatchField` enum('studentID','gibbonPersonID','username') NOT NULL DEFAULT 'studentID',
  `studentIdentifierHeaderPattern` varchar(120) NOT NULL DEFAULT 'Student ID',
  `dateHeaderPattern` varchar(120) NOT NULL DEFAULT 'Date of test',
  `importCategories` text NOT NULL COMMENT 'JSON array of categories to import',
  `active` enum('Y','N') NOT NULL DEFAULT 'Y',
  `isDefault` enum('Y','N') NOT NULL DEFAULT 'N',
  `timestampCreated` datetime NOT NULL,
  `timestampUpdated` datetime DEFAULT NULL,
  `gibbonPersonIDCreated` int(10) unsigned zerofill DEFAULT NULL,
  `gibbonPersonIDUpdated` int(10) unsigned zerofill DEFAULT NULL,
  PRIMARY KEY (`academicRecordsCAT4MappingID`),
  KEY `gibbonExternalAssessmentID` (`gibbonExternalAssessmentID`),
  KEY `active` (`active`),
  KEY `isDefault` (`isDefault`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;",

"CREATE TABLE `academicRecordsCAT4MappingField` (
  `academicRecordsCAT4MappingFieldID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `academicRecordsCAT4MappingID` int(12) unsigned zerofill NOT NULL,
  `gibbonExternalAssessmentFieldID` int(6) unsigned zerofill NOT NULL,
  `headerPattern` varchar(160) NOT NULL,
  `active` enum('Y','N') NOT NULL DEFAULT 'Y',
  PRIMARY KEY (`academicRecordsCAT4MappingFieldID`),
  KEY `academicRecordsCAT4MappingID` (`academicRecordsCAT4MappingID`),
  KEY `gibbonExternalAssessmentFieldID` (`gibbonExternalAssessmentFieldID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;",

"CREATE TABLE `academicRecordsYearGroupMap` (
  `academicRecordsYearGroupMapID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonYearGroupID` int(3) unsigned zerofill NOT NULL,
  `testwiseYear` varchar(4) NOT NULL COMMENT 'Prefix and number, such as Y7, P4 or S3',
  PRIMARY KEY (`academicRecordsYearGroupMapID`),
  UNIQUE KEY `gibbonYearGroupID` (`gibbonYearGroupID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;",

"CREATE TABLE `academicRecordsStoredGrade` (
  `academicRecordsStoredGradeID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonInternalAssessmentColumnID` int(10) unsigned zerofill NOT NULL,
  `gibbonSchoolYearID` int(3) unsigned zerofill NOT NULL,
  `gibbonSchoolYearTermID` int(5) unsigned zerofill NOT NULL COMMENT 'The term the grades belong to, not the cycle dates',
  `gibbonReportingCycleID` int(10) unsigned zerofill DEFAULT NULL,
  `gibbonCourseClassID` int(8) unsigned zerofill NOT NULL,
  `timestampCreated` datetime NOT NULL,
  `timestampUpdated` datetime DEFAULT NULL,
  `gibbonPersonIDCreated` int(10) unsigned zerofill DEFAULT NULL,
  `gibbonPersonIDUpdated` int(10) unsigned zerofill DEFAULT NULL,
  PRIMARY KEY (`academicRecordsStoredGradeID`),
  UNIQUE KEY `gibbonInternalAssessmentColumnID` (`gibbonInternalAssessmentColumnID`),
  KEY `gibbonSchoolYearTermID` (`gibbonSchoolYearTermID`),
  KEY `gibbonSchoolYearID` (`gibbonSchoolYearID`),
  KEY `gibbonCourseClassID` (`gibbonCourseClassID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;",

"CREATE TABLE `academicRecordsTranscriptStudent` (
  `academicRecordsTranscriptStudentID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonPersonID` int(10) unsigned zerofill NOT NULL,
  `graduationYear` varchar(4) DEFAULT NULL COMMENT 'Overrides the derived Class of value',
  `graduationDate` date DEFAULT NULL COMMENT 'Overrides the derived graduation date',
  `showGPA` enum('Y','N') NOT NULL DEFAULT 'N' COMMENT 'Print GPA columns and the overall GPA for this student',
  `timestampUpdated` datetime DEFAULT NULL,
  `gibbonPersonIDUpdated` int(10) unsigned zerofill DEFAULT NULL,
  PRIMARY KEY (`academicRecordsTranscriptStudentID`),
  UNIQUE KEY `gibbonPersonID` (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;",

"CREATE TABLE `academicRecordsCourseCredit` (
  `academicRecordsCourseCreditID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonCourseID` int(8) unsigned zerofill NOT NULL,
  `creditPerTerm` decimal(4,2) DEFAULT NULL COMMENT 'Credit available for one term. Null means the course carries no credit',
  `showOnTranscript` enum('Y','N') NOT NULL DEFAULT 'Y',
  `timestampUpdated` datetime DEFAULT NULL,
  `gibbonPersonIDUpdated` int(10) unsigned zerofill DEFAULT NULL,
  PRIMARY KEY (`academicRecordsCourseCreditID`),
  UNIQUE KEY `gibbonCourseID` (`gibbonCourseID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;",

"CREATE TABLE `academicRecordsGradeSetting` (
  `academicRecordsGradeSettingID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonScaleID` int(5) unsigned zerofill NOT NULL,
  `value` varchar(10) NOT NULL COMMENT 'Grade value as stored on the assessment entry',
  `creditFactor` decimal(4,2) DEFAULT NULL COMMENT 'Share of the course credit this grade earns',
  `percentMin` decimal(5,2) DEFAULT NULL COMMENT 'Lowest percentage that reaches this grade',
  `percentMax` decimal(5,2) DEFAULT NULL COMMENT 'Highest percentage for this grade',
  `gpaPoints` decimal(3,2) DEFAULT NULL COMMENT 'Grade point value, such as 4.00',
  `gpaLetter` varchar(4) DEFAULT NULL COMMENT 'Letter grade on the GPA scale, such as A',
  PRIMARY KEY (`academicRecordsGradeSettingID`),
  UNIQUE KEY `gibbonScaleIDValue` (`gibbonScaleID`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;"

];

// Internal Assessment Settings
$gibbonSetting[] = "
INSERT INTO gibbonSetting
(name, nameDisplay, description, scope, value)
VALUES
('internalAssessmentType',
 'Internal Assessment Type',
 'The Internal Assessment Type used for stored grade columns created by this module.',
 'Academic Records',
 '')
";

$gibbonSetting[] = "
INSERT INTO gibbonSetting
(name, nameDisplay, description, scope, value)
VALUES
('transcriptYearGroups',
 'Transcript Year Groups',
 'Which year groups appear on a transcript, as a comma separated list of gibbonYearGroupID. Empty means every year group.',
 'Academic Records',
 '')
";

$gibbonSetting[] = "
INSERT INTO gibbonSetting
(name, nameDisplay, description, scope, value)
VALUES
('transcriptGpaMethod',
 'Transcript GPA Method',
 'How the overall GPA is worked out: creditWeighted weights each grade by the credit it earned, simpleMean averages the grade points.',
 'Academic Records',
 'creditWeighted')
";

$gibbonSetting[] = "
INSERT INTO gibbonSetting
(name, nameDisplay, description, scope, value)
VALUES
('viewableStudents',
 'Viewable by Students',
 'Whether stored grade columns are viewable by students.',
 'Academic Records',
 'Y')
";

$gibbonSetting[] = "
INSERT INTO gibbonSetting
(name, nameDisplay, description, scope, value)
VALUES
('viewableParents',
 'Viewable by Parents',
 'Whether stored grade columns are viewable by parents.',
 'Academic Records',
 'Y')
";

$gibbonSetting[] = "
INSERT INTO gibbonSetting
(name, nameDisplay, description, scope, value)
VALUES
('testwiseRegion',
 'Testwise Region',
 'The region GL Assessment expect for this school. Sets the year group prefix used by the student export.',
 'Academic Records',
 '')
";

// -----------------------------------------------------
// Action rows
// -----------------------------------------------------

$actionRows = [

    [
        'name'                      => 'Store Grades',
        'precedence'                => '0',
        'category'                  => 'Manage',
        'description'               => 'Stores reported grade scale values from a reporting cycle as academic records.',
        'URLList'                   => 'academicRecords_store.php,academicRecords_store_ajax.php',
        'entryURL'                  => 'academicRecords_store.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'N',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'Generate Transcripts',
        'precedence'                => '2',
        'category'                  => 'Manage',
        'description'               => 'Generates transcripts for students using a Reports template.',
        'URLList'                   => 'academicRecords_transcripts.php,academicRecords_transcriptsProcess.php',
        'entryURL'                  => 'academicRecords_transcripts.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'N',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'Stored Grade Coverage',
        'precedence'                => '1',
        'category'                  => 'Manage',
        'description'               => 'Shows which classes have a stored grade for each term of a school year.',
        'URLList'                   => 'academicRecords_coverage.php',
        'entryURL'                  => 'academicRecords_coverage.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    // Two actions share the view page. The higher precedence wins where a
    // role holds both, the same as View Markbook_allClassesAllData and
    // View Markbook_myClasses in core.
    [
        'name'                      => 'View Academic Records_all',
        'precedence'                => '2',
        'category'                  => 'View',
        'description'               => 'View the academic record of any student.',
        'URLList'                   => 'academicRecords_view.php',
        'entryURL'                  => 'academicRecords_view.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'View Academic Records_my',
        'precedence'                => '1',
        'category'                  => 'View',
        'description'               => 'View the academic record of a student in a class the user teaches or a form group the user tutors.',
        'URLList'                   => 'academicRecords_view.php',
        'entryURL'                  => 'academicRecords_view.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'N',
        'defaultPermissionTeacher'  => 'Y',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'N',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'View Transcripts',
        'precedence'                => '1',
        'category'                  => 'View',
        'description'               => 'Opens the Reports archive for the transcript report.',
        'URLList'                   => 'academicRecords_viewTranscripts.php',
        'entryURL'                  => 'academicRecords_viewTranscripts.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'N',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'CAT4 Import',
        'precedence'                => '2',
        'category'                  => 'Manage',
        'description'               => 'Imports CAT4 SAS data into External Assessment records.',
        'URLList'                   => 'cat4_import.php',
        'entryURL'                  => 'cat4_import.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'N',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'Export Student Data to GL Assessment',
        'precedence'                => '3',
        'category'                  => 'Export Data',
        'description'               => 'Exports current student data to CSV for GL Assessment.',
        'URLList'                   => 'academicRecords_studentExport.php,academicRecords_studentExportProcess.php',
        'entryURL'                  => 'academicRecords_studentExport.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'Academic Records Settings',
        'precedence'                => '4',
        'category'                  => 'Settings',
        'description'               => 'Configures how academic records and CAT4 imports are managed.',
        'URLList'                   => 'academicRecords_settings.php,academicRecords_settingsProcess.php',
        'entryURL'                  => 'academicRecords_settings.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'Backup and Restore Settings',
        'precedence'                => '5',
        'category'                  => 'Settings',
        'description'               => 'Export and restore Academic Records settings and CAT4 mappings.',
        'URLList'                   => 'backup_restore.php,backup_restoreProcess.php',
        'entryURL'                  => 'backup_restore.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],
	
    [
        'name'                      => 'Testwise Year Groups',
        'precedence'                => '6',
        'category'                  => 'Settings',
        'description'               => 'Maps Gibbon year groups to the year values GL Assessment expect.',
        'URLList'                   => 'academicRecords_testwiseYearGroups.php,academicRecords_testwiseYearGroupsProcess.php',
        'entryURL'                  => 'academicRecords_testwiseYearGroups.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

	[
        'name'                      => 'CAT4 Import Settings',
        'precedence'                => '7',
        'category'                  => 'Settings',
        'description'               => 'Configures how CAT4 imports are managed.',
        'URLList'                   => 'academicRecords_cat4Mapping.php,academicRecords_cat4MappingProcess.php',
        'entryURL'                  => 'academicRecords_cat4Mapping.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'Course Credits',
        'precedence'                => '9',
        'category'                  => 'Settings',
        'description'               => 'Sets the credit each course carries, and whether it appears on a transcript.',
        'URLList'                   => 'academicRecords_courseCredits.php,academicRecords_courseCreditsProcess.php',
        'entryURL'                  => 'academicRecords_courseCredits.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'N',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],

    [
        'name'                      => 'Transcript Setup',
        'precedence'                => '8',
        'category'                  => 'Settings',
        'description'               => 'Configures the values transcripts are built from.',
        'URLList'                   => 'academicRecords_transcriptSetup.php,academicRecords_transcriptSetupProcess.php',
        'entryURL'                  => 'academicRecords_transcriptSetup.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'N',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'N',
        'categoryPermissionStaff'   => 'Y',
        'categoryPermissionStudent' => 'N',
        'categoryPermissionParent'  => 'N',
        'categoryPermissionOther'   => 'N',
    ],
];

// -----------------------------------------------------
// Hooks
// -----------------------------------------------------

$hooks = [];

// -----------------------------------------------------
// Assets
// -----------------------------------------------------

$moduleAssets = [
    'css' => [
        'css/module.css'
    ],
];
