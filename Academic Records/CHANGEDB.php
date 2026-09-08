<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

// Blocks are applied when their version is higher than the installed version
// in gibbonModule and no higher than $moduleVersion in version.php. Statements
// are written to be safe to re-run, because an install may reach a block from
// any earlier version.

$sql = [];
$count = 0;

// v0.0.00
$sql[$count][0] = "0.0.00";
$sql[$count][1] = "-- First version, nothing to update";

// v0.1.00
$count++;
$sql[$count][0] = "0.1.00";
$sql[$count][1] = "INSERT IGNORE INTO gibbonSetting (name, nameDisplay, description, scope, value) VALUES ('internalAssessmentType', 'Internal Assessment Type', 'The Internal Assessment Type used for stored grade columns created by this module.', 'Academic Records', '');end
INSERT IGNORE INTO gibbonSetting (name, nameDisplay, description, scope, value) VALUES ('viewableStudents', 'Viewable by Students', 'Whether stored grade columns are viewable by students.', 'Academic Records', 'Y');end
INSERT IGNORE INTO gibbonSetting (name, nameDisplay, description, scope, value) VALUES ('viewableParents', 'Viewable by Parents', 'Whether stored grade columns are viewable by parents.', 'Academic Records', 'Y');end";

// v0.2.00
$count++;
$sql[$count][0] = "0.2.00";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `academicRecordsCAT4Mapping` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
CREATE TABLE IF NOT EXISTS `academicRecordsCAT4MappingField` (
  `academicRecordsCAT4MappingFieldID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `academicRecordsCAT4MappingID` int(12) unsigned zerofill NOT NULL,
  `gibbonExternalAssessmentFieldID` int(6) unsigned zerofill NOT NULL,
  `headerPattern` varchar(160) NOT NULL,
  `active` enum('Y','N') NOT NULL DEFAULT 'Y',
  PRIMARY KEY (`academicRecordsCAT4MappingFieldID`),
  KEY `academicRecordsCAT4MappingID` (`academicRecordsCAT4MappingID`),
  KEY `gibbonExternalAssessmentFieldID` (`gibbonExternalAssessmentFieldID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end";

// v0.3.00
$count++;
$sql[$count][0] = "0.3.00";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `academicRecordsYearGroupMap` (
  `academicRecordsYearGroupMapID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonYearGroupID` int(3) unsigned zerofill NOT NULL,
  `testwiseYear` varchar(4) NOT NULL COMMENT 'Prefix and number, such as Y7, P4 or S3',
  PRIMARY KEY (`academicRecordsYearGroupMapID`),
  UNIQUE KEY `gibbonYearGroupID` (`gibbonYearGroupID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
INSERT IGNORE INTO gibbonSetting (name, nameDisplay, description, scope, value) VALUES ('testwiseRegion', 'Testwise Region', 'The region GL Assessment expect for this school. Sets the year group prefix used by the student export.', 'Academic Records', '');end
INSERT INTO `gibbonAction` (`gibbonModuleID`, `name`, `precedence`, `category`, `description`, `URLList`, `entryURL`, `entrySidebar`, `menuShow`, `defaultPermissionAdmin`, `defaultPermissionTeacher`, `defaultPermissionStudent`, `defaultPermissionParent`, `defaultPermissionSupport`, `categoryPermissionStaff`, `categoryPermissionStudent`, `categoryPermissionParent`, `categoryPermissionOther`) VALUES ((SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'), 'Testwise Year Groups', 6, 'Settings', 'Maps Gibbon year groups to the year values GL Assessment expect.', 'academicRecords_testwiseYearGroups.php,academicRecords_testwiseYearGroupsProcess.php', 'academicRecords_testwiseYearGroups.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'Y', 'N', 'N', 'N', 'N');end
INSERT INTO `gibbonPermission` (`gibbonRoleID`, `gibbonActionID`) VALUES ('001', (SELECT gibbonActionID FROM gibbonAction JOIN gibbonModule ON (gibbonAction.gibbonModuleID=gibbonModule.gibbonModuleID) WHERE gibbonModule.name='Academic Records' AND gibbonAction.name='Testwise Year Groups'));end
INSERT INTO `gibbonPermission` (`gibbonRoleID`, `gibbonActionID`) VALUES ('006', (SELECT gibbonActionID FROM gibbonAction JOIN gibbonModule ON (gibbonAction.gibbonModuleID=gibbonModule.gibbonModuleID) WHERE gibbonModule.name='Academic Records' AND gibbonAction.name='Testwise Year Groups'));end
UPDATE `gibbonAction` SET `precedence`=7 WHERE `name`='CAT4 Import Settings' AND `gibbonModuleID`=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records');end";
