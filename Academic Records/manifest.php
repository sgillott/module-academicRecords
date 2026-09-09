<?php

// Basic variables
$name        = 'Academic Records';
$description = 'Stores reported grade scale outcomes as authoritative academic records for long-term use.';
$entryURL    = 'academicRecords_store.php';
$type        = 'Additional';
$category    = 'Assess';
$version     = '0.3.03';
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
        'URLList'                   => 'academicRecords_store.php',
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
        'name'                      => 'View Academic Records',
        'precedence'                => '1',
        'category'                  => 'View',
        'description'               => 'Allows the user to view stored academic records for students.',
        'URLList'                   => 'academicRecords_view.php',
        'entryURL'                  => 'academicRecords_view.php',
        'entrySidebar'              => 'Y',
        'menuShow'                  => 'Y',
        'defaultPermissionAdmin'    => 'Y',
        'defaultPermissionTeacher'  => 'Y',
        'defaultPermissionStudent'  => 'N',
        'defaultPermissionParent'   => 'N',
        'defaultPermissionSupport'  => 'Y',
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
        'URLList'                   => 'cat4_import.php,cat4_import_process.php',
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
        'URLList'                   => 'academicRecords_settings.php',
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
        'URLList'                   => 'academicRecords_cat4Mapping.php',
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
