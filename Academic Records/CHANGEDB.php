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

// v0.3.01
$count++;
$sql[$count][0] = "0.3.01";
$sql[$count][1] = "UPDATE `gibbonAction` SET `categoryPermissionStaff`='Y' WHERE `gibbonModuleID`=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records');end
INSERT INTO `gibbonPermission` (`gibbonRoleID`, `gibbonActionID`) SELECT '001', `gibbonAction`.`gibbonActionID` FROM `gibbonAction` WHERE `gibbonAction`.`gibbonModuleID`=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records') AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM `gibbonPermission`) AS `held` WHERE `held`.`gibbonActionID`=`gibbonAction`.`gibbonActionID` AND `held`.`gibbonRoleID`='001');end";

// v0.4.00
$count++;
$sql[$count][0] = "0.4.00";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `academicRecordsStoredGrade` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
INSERT INTO `gibbonAction` (`gibbonModuleID`, `name`, `precedence`, `category`, `description`, `URLList`, `entryURL`, `entrySidebar`, `menuShow`, `defaultPermissionAdmin`, `defaultPermissionTeacher`, `defaultPermissionStudent`, `defaultPermissionParent`, `defaultPermissionSupport`, `categoryPermissionStaff`, `categoryPermissionStudent`, `categoryPermissionParent`, `categoryPermissionOther`) SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'), 'Transcript Setup', 8, 'Settings', 'Configures the values transcripts are built from.', 'academicRecords_transcriptSetup.php,academicRecords_transcriptSetupProcess.php', 'academicRecords_transcriptSetup.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT * FROM gibbonAction) AS held WHERE held.name='Transcript Setup' AND held.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'));end
INSERT INTO `gibbonPermission` (`gibbonRoleID`, `gibbonActionID`) SELECT '001', `gibbonAction`.`gibbonActionID` FROM `gibbonAction` WHERE `gibbonAction`.`gibbonModuleID`=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records') AND `gibbonAction`.`name`='Transcript Setup' AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM `gibbonPermission`) AS `held` WHERE `held`.`gibbonActionID`=`gibbonAction`.`gibbonActionID` AND `held`.`gibbonRoleID`='001');end
CREATE TABLE IF NOT EXISTS `academicRecordsTranscriptStudent` (
  `academicRecordsTranscriptStudentID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonPersonID` int(10) unsigned zerofill NOT NULL,
  `graduationYear` varchar(4) DEFAULT NULL COMMENT 'Overrides the derived Class of value',
  `graduationDate` date DEFAULT NULL COMMENT 'Overrides the derived graduation date',
  `showGPA` enum('Y','N') NOT NULL DEFAULT 'N' COMMENT 'Print GPA columns and the overall GPA for this student',
  `timestampUpdated` datetime DEFAULT NULL,
  `gibbonPersonIDUpdated` int(10) unsigned zerofill DEFAULT NULL,
  PRIMARY KEY (`academicRecordsTranscriptStudentID`),
  UNIQUE KEY `gibbonPersonID` (`gibbonPersonID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
INSERT IGNORE INTO gibbonSetting (name, nameDisplay, description, scope, value) VALUES ('transcriptYearGroups', 'Transcript Year Groups', 'Which year groups appear on a transcript, as a comma separated list of gibbonYearGroupID. Empty means every year group.', 'Academic Records', '');end
CREATE TABLE IF NOT EXISTS `academicRecordsCourseCredit` (
  `academicRecordsCourseCreditID` int(12) unsigned zerofill NOT NULL AUTO_INCREMENT,
  `gibbonCourseID` int(8) unsigned zerofill NOT NULL,
  `creditPerTerm` decimal(4,2) DEFAULT NULL COMMENT 'Credit available for one term. Null means the course carries no credit',
  `showOnTranscript` enum('Y','N') NOT NULL DEFAULT 'Y',
  `timestampUpdated` datetime DEFAULT NULL,
  `gibbonPersonIDUpdated` int(10) unsigned zerofill DEFAULT NULL,
  PRIMARY KEY (`academicRecordsCourseCreditID`),
  UNIQUE KEY `gibbonCourseID` (`gibbonCourseID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
CREATE TABLE IF NOT EXISTS `academicRecordsGradeSetting` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;end
DROP TABLE IF EXISTS `academicRecordsGradeCredit`;end
INSERT IGNORE INTO gibbonSetting (name, nameDisplay, description, scope, value) VALUES ('transcriptGpaMethod', 'Transcript GPA Method', 'How the overall GPA is worked out: creditWeighted weights each grade by the credit it earned, simpleMean averages the grade points.', 'Academic Records', 'creditWeighted');end
INSERT INTO `gibbonAction` (`gibbonModuleID`, `name`, `precedence`, `category`, `description`, `URLList`, `entryURL`, `entrySidebar`, `menuShow`, `defaultPermissionAdmin`, `defaultPermissionTeacher`, `defaultPermissionStudent`, `defaultPermissionParent`, `defaultPermissionSupport`, `categoryPermissionStaff`, `categoryPermissionStudent`, `categoryPermissionParent`, `categoryPermissionOther`) SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'), 'Course Credits', 9, 'Settings', 'Sets the credit each course carries, and whether it appears on a transcript.', 'academicRecords_courseCredits.php,academicRecords_courseCreditsProcess.php', 'academicRecords_courseCredits.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT * FROM gibbonAction) AS held WHERE held.name='Course Credits' AND held.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'));end
INSERT INTO `gibbonPermission` (`gibbonRoleID`, `gibbonActionID`) SELECT '001', `gibbonAction`.`gibbonActionID` FROM `gibbonAction` WHERE `gibbonAction`.`gibbonModuleID`=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records') AND `gibbonAction`.`name`='Course Credits' AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM `gibbonPermission`) AS `held` WHERE `held`.`gibbonActionID`=`gibbonAction`.`gibbonActionID` AND `held`.`gibbonRoleID`='001');end
DELETE FROM gibbonSetting WHERE scope='Academic Records' AND name='transcriptYears';end
INSERT INTO `gibbonAction` (`gibbonModuleID`, `name`, `precedence`, `category`, `description`, `URLList`, `entryURL`, `entrySidebar`, `menuShow`, `defaultPermissionAdmin`, `defaultPermissionTeacher`, `defaultPermissionStudent`, `defaultPermissionParent`, `defaultPermissionSupport`, `categoryPermissionStaff`, `categoryPermissionStudent`, `categoryPermissionParent`, `categoryPermissionOther`) SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'), 'Generate Transcripts', 2, 'Manage', 'Generates transcripts for students using a Reports template.', 'academicRecords_transcripts.php,academicRecords_transcriptsProcess.php', 'academicRecords_transcripts.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT * FROM gibbonAction) AS held WHERE held.name='Generate Transcripts' AND held.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'));end
INSERT INTO `gibbonPermission` (`gibbonRoleID`, `gibbonActionID`) SELECT '001', `gibbonAction`.`gibbonActionID` FROM `gibbonAction` WHERE `gibbonAction`.`gibbonModuleID`=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records') AND `gibbonAction`.`name`='Generate Transcripts' AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM `gibbonPermission`) AS `held` WHERE `held`.`gibbonActionID`=`gibbonAction`.`gibbonActionID` AND `held`.`gibbonRoleID`='001');end
INSERT INTO `gibbonAction` (`gibbonModuleID`, `name`, `precedence`, `category`, `description`, `URLList`, `entryURL`, `entrySidebar`, `menuShow`, `defaultPermissionAdmin`, `defaultPermissionTeacher`, `defaultPermissionStudent`, `defaultPermissionParent`, `defaultPermissionSupport`, `categoryPermissionStaff`, `categoryPermissionStudent`, `categoryPermissionParent`, `categoryPermissionOther`) SELECT (SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'), 'View Transcripts', 1, 'View', 'Opens the Reports archive for the transcript report.', 'academicRecords_viewTranscripts.php', 'academicRecords_viewTranscripts.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM (SELECT * FROM gibbonAction) AS held WHERE held.name='View Transcripts' AND held.gibbonModuleID=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records'));end
INSERT INTO `gibbonPermission` (`gibbonRoleID`, `gibbonActionID`) SELECT '001', `gibbonAction`.`gibbonActionID` FROM `gibbonAction` WHERE `gibbonAction`.`gibbonModuleID`=(SELECT gibbonModuleID FROM gibbonModule WHERE name='Academic Records') AND `gibbonAction`.`name`='View Transcripts' AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM `gibbonPermission`) AS `held` WHERE `held`.`gibbonActionID`=`gibbonAction`.`gibbonActionID` AND `held`.`gibbonRoleID`='001');end";

// v0.4.01
$count++;
$sql[$count][0] = "0.4.01";
$sql[$count][1] = "-- Report components only, nothing to update";
