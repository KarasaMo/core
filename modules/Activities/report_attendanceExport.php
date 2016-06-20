<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

@session_start();

//Increase max execution time, as this stuff gets big
ini_set('max_execution_time', 600);

//System includes
include '../../config.php';
include '../../functions.php';
include '../../version.php';

//New PDO DB connection
try {
    $connection2 = new PDO("mysql:host=$databaseServer;dbname=$databaseName;charset=utf8", $databaseUsername, $databasePassword);
    $connection2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo $e->getMessage();
}

//Set timezone from session variable
date_default_timezone_set($_SESSION[$guid]['timezone']);

//Module includes
include './moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Activities/report_attendanceExport.php') == false) {
    //Acess denied
    echo "<div class='error'>";
    echo __($guid, 'You do not have access to this action.');
    echo '</div>';
} else {

    /** Include PHPExcel */
    require_once $_SESSION[$guid]['absolutePath'].'/lib/PHPExcel/Classes/PHPExcel.php';

    // Create new PHPExcel object
    $excel = new PHPExcel();

    //Create border styles
    $style_head_fill = array('fill' => array('type' => PHPExcel_Style_Fill::FILL_SOLID, 'color' => array('rgb' => 'eeeeee')),'borders' => array('top' => array('style' => PHPExcel_Style_Border::BORDER_THIN, 'color' => array('argb' => '444444')), 'bottom' => array('style' => PHPExcel_Style_Border::BORDER_THIN, 'color' => array('argb' => '444444'))),);

    // Set document properties
    $excel->getProperties()->setCreator(formatName('', $_SESSION[$guid]['preferredName'], $_SESSION[$guid]['surname'], 'Staff'))
         ->setLastModifiedBy(formatName('', $_SESSION[$guid]['preferredName'], $_SESSION[$guid]['surname'], 'Staff'))
         ->setTitle(__($guid, 'Activity Attendance'))
         ->setDescription(__($guid, 'This information is confidential. Generated by Gibbon (https://gibbonedu.org).'));

    $gibbonActivityID = $_GET['gibbonActivityID'];
    $filename = __($guid, 'Activity').__($guid, 'Attendance').'-'.$gibbonActivityID;

    if (empty($gibbonActivityID)) { //Seems like things are not configured, so show error
        $excel->setActiveSheetIndex(0)->setCellValue('A1', __($guid, 'An error has occurred.'));
    } else {

        // Get the activity info
        try {
            $data = array('gibbonActivityID' => $gibbonActivityID);
            $sql = 'SELECT gibbonActivity.name, description, programStart, programEnd, gibbonSchoolYearTermIDList, gibbonYearGroupIDList, gibbonSchoolYear.gibbonSchoolYearID, gibbonSchoolYear.name as schoolYearName FROM gibbonActivity, gibbonSchoolYear WHERE gibbonSchoolYear.gibbonSchoolYearID=gibbonActivity.gibbonSchoolYearID AND gibbonActivityID=:gibbonActivityID';
            $activityResult = $connection2->prepare($sql);
            $activityResult->execute($data);
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
        $activity = $activityResult->fetch();

        // Get the students
        try {
            $data = array('gibbonSchoolYearID' => $_SESSION[$guid]['gibbonSchoolYearID'], 'gibbonActivityID' => $gibbonActivityID);
            $sql = "SELECT gibbonPerson.gibbonPersonID as gibbonPersonID, surname, preferredName FROM gibbonPerson JOIN gibbonStudentEnrolment ON (gibbonPerson.gibbonPersonID=gibbonStudentEnrolment.gibbonPersonID) JOIN gibbonActivityStudent ON (gibbonActivityStudent.gibbonPersonID=gibbonPerson.gibbonPersonID) WHERE gibbonPerson.status='Full' AND (dateStart IS NULL OR dateStart<='".date('Y-m-d')."') AND (dateEnd IS NULL  OR dateEnd>='".date('Y-m-d')."') AND gibbonSchoolYearID=:gibbonSchoolYearID AND gibbonActivityStudent.status='Accepted' AND gibbonActivityID=:gibbonActivityID ORDER BY gibbonActivityStudent.status, surname, preferredName";
            $studentResult = $connection2->prepare($sql);
            $studentResult->execute($data);
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
        $students = $studentResult->fetchAll();

        // Get the recorded attendance
        try {
            $data = array('gibbonActivityID' => $gibbonActivityID);
            $sql = 'SELECT UNIX_TIMESTAMP(gibbonActivityAttendance.date) as date, gibbonActivityAttendance.timestampTaken, gibbonActivityAttendance.attendance, gibbonPerson.preferredName, gibbonPerson.surname FROM gibbonActivityAttendance, gibbonPerson WHERE gibbonActivityAttendance.gibbonPersonIDTaker=gibbonPerson.gibbonPersonID AND gibbonActivityAttendance.gibbonActivityID=:gibbonActivityID ORDER BY DATE ASC';
            $resultAttendance = $connection2->prepare($sql);
            $resultAttendance->execute($data);
        } catch (PDOException $e) {
            echo $e->getMessage();
        }
        $sessions = $resultAttendance->fetchAll();

        // Get the time slots
        try {
            $data = array('gibbonActivityID' => $gibbonActivityID);
            $sql = 'SELECT nameShort, timeStart, timeEnd FROM gibbonActivitySlot JOIN gibbonDaysOfWeek ON (gibbonActivitySlot.gibbonDaysOfWeekID=gibbonDaysOfWeek.gibbonDaysOfWeekID) WHERE gibbonActivityID=:gibbonActivityID ORDER BY gibbonDaysOfWeek.gibbonDaysOfWeekID';
            $resultSlots = $connection2->prepare($sql);
            $resultSlots->execute($data);
        } catch (PDOException $e) {
            echo $e->getMessage();
        }

        // Get the activity staff members
        try {
            $dataStaff = array('gibbonActivityID' => $gibbonActivityID);
            $sqlStaff = "SELECT title, preferredName, surname, role FROM gibbonActivityStaff JOIN gibbonPerson ON (gibbonActivityStaff.gibbonPersonID=gibbonPerson.gibbonPersonID) WHERE gibbonActivityID=:gibbonActivityID AND gibbonPerson.status='Full' AND (dateStart IS NULL OR dateStart<='".date('Y-m-d')."') AND (dateEnd IS NULL  OR dateEnd>='".date('Y-m-d')."') ORDER BY surname, preferredName";
            $resultStaff = $connection2->prepare($sqlStaff);
            $resultStaff->execute($dataStaff);
        } catch (PDOException $e) {
            $e->getMessage();
        }

        $columnStart = 1;
        $columnEnd = count($students) + 1;

        $excel->setActiveSheetIndex(0);

        // Sheet defaults
        $excel->getActiveSheet()->getColumnDimension('A')->setAutoSize(true);
        $excel->getActiveSheet()->getDefaultRowDimension()->setRowHeight(20);
        $excel->getActiveSheet()->getDefaultColumnDimension()->setWidth(12);

        // Output the activity name
        $excel->getActiveSheet()->setCellValue('A1', __($guid, 'Activity'))
                                ->setCellValue('B1', $activity['name']);

        $excel->getActiveSheet()->mergeCells('B1:I1');
        $excel->getActiveSheet()->getRowDimension('1')->setRowHeight(30);
        $excel->getActiveSheet()->getStyle('A1:I1')->getFont()->setSize(18);
        $excel->getActiveSheet()->getStyle('A2:J2')->applyFromArray($style_head_fill);

        // Output some activity details (useful if we're printing this)
        $infoRowLines = 1;

        $slots = array();
        if ($resultSlots->rowCount() > 0) {
            while ($rowSlots = $resultSlots->fetch()) {
                $slots[] = $rowSlots['nameShort'].': '.substr($rowSlots['timeStart'], 0, 5).' - '.substr($rowSlots['timeEnd'], 0, 5);
            }
        }
        $infoRowLines = max($infoRowLines, count($slots));

        // TIME SLOTS
        $excel->getActiveSheet()->setCellValue('A2', __($guid, 'Time Slots'))
                                ->setCellValue('A3', implode(",\r\n", $slots));

        $excel->getActiveSheet()->getStyle('A3')->getAlignment()->setWrapText(true);

        // DATE / TERMS
        $dateType = getSettingByScope($connection2, 'Activities', 'dateType');
        if ($dateType != 'Date') {
            $terms = getTerms($connection2, $activity['gibbonSchoolYearID']);
            $termList = array();
            for ($i = 0; $i < count($terms); $i = $i + 2) {
                if (is_numeric(strpos($activity['gibbonSchoolYearTermIDList'], $terms[$i]))) {
                    $termList[] = $terms[($i + 1)];
                }
            }

            $excel->getActiveSheet()->setCellValue('B2', __($guid, 'Terms'));
            $excel->getActiveSheet()->setCellValue('B3', implode(",\r\n", $termList));
            $excel->getActiveSheet()->mergeCells('B3:C3');
            $infoRowLines = max($infoRowLines, count($termList));
        } else {
            $excel->getActiveSheet()->setCellValue('B2', __($guid, 'Start Date'))
                ->setCellValue('B3', dateConvertBack($guid, $activity['programStart']));

            $excel->getActiveSheet()->setCellValue('C2', __($guid, 'End Date'))
                ->setCellValue('C3', dateConvertBack($guid, $activity['programEnd']));
        }

        // SCHOOL YEAR
        $excel->getActiveSheet()->setCellValue('D2', __($guid, 'School Year'))
            ->setCellValue('D3', $activity['schoolYearName']);

        $excel->getActiveSheet()->setCellValue('E2', __($guid, 'Participants'))
            ->setCellValue('E3', count($students));

        // STAFF
        $staff = array();
        if ($resultStaff->rowCount() > 0) {
            while ($rowStaff = $resultStaff->fetch()) {
                $staff[] = formatName($rowStaff['title'], $rowStaff['preferredName'], $rowStaff['surname'], 'Staff');
            }
        }
        $infoRowLines = max($infoRowLines, count($staff));

        $excel->getActiveSheet()->setCellValue('F2', __($guid, 'Staff'))
            ->setCellValue('F3', implode(",\r\n", $staff));

        $excel->getActiveSheet()->mergeCells('F3:G3');
        $excel->getActiveSheet()->getStyle('F3:G3')->getAlignment()->setWrapText(true);

        // YEAR GROUPS
        $excel->getActiveSheet()->setCellValue('H2', __($guid, 'Year Groups'))
            ->setCellValue('H3', strip_tags(getYearGroupsFromIDList($guid, $connection2, $activity['gibbonYearGroupIDList'])));

        $excel->getActiveSheet()->mergeCells('H3:I3');
        $excel->getActiveSheet()->getStyle('H3:I3')->getAlignment()->setWrapText(true);

        // TOTAL SESSIONS
        $excel->getActiveSheet()->setCellValue('J2', __($guid, 'Total Sessions'))
            ->setCellValue('J3', count($sessions));

        $excel->getActiveSheet()->getRowDimension('3')->setRowHeight($infoRowLines * 16);

        // Iterate over the sessions and output the column headings, plus setup the attendance data array
        $attendance = array();
        $columnStart += 4;
        for ($i = 0; $i < count($sessions); ++$i) {
            $excel->getActiveSheet()->setCellValue(num2alpha($i + 1).($columnStart),
                date('D', $sessions[$i]['date']));

            $excel->getActiveSheet()->setCellValue(num2alpha($i + 1).($columnStart + 1),
                date($_SESSION[$guid]['i18n']['dateFormatPHP'], $sessions[$i]['date']));

            $excel->getActiveSheet()->getStyle(num2alpha($i + 1).($columnStart + 1))->applyFromArray($style_head_fill);

            // Store the unserialized attendance data in an associative array so student rows can access them based on gibbonpPersonID
            $sessionAttendance = (!empty($sessions[$i]['attendance'])) ? unserialize($sessions[$i]['attendance']) : array();
            foreach ($sessionAttendance as $studentID => $value) {
                $attendance[$i][$studentID] = $value;
            }
        }

        // Setup the column heading for students
        $excel->getActiveSheet()->setCellValue('A'.($columnStart), __($guid, 'Days'))
            ->setCellValue('A'.($columnStart + 1), __($guid, 'Student'));
        $excel->getActiveSheet()->getStyle('A'.($columnStart))->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_RIGHT);
        $excel->getActiveSheet()->getStyle('A'.($columnStart + 1))->applyFromArray($style_head_fill);

        $excel->getActiveSheet()->setCellValue(num2alpha(count($sessions) + 1).($columnStart + 1), __($guid, 'Attended:'));
        $excel->getActiveSheet()->getStyle(num2alpha(count($sessions) + 1).($columnStart + 1))
            ->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_RIGHT);

        // Iterate over the students and output each row
        $columnStart += 2;
        for ($i = 0; $i < count($students); ++$i) {
            $excel->getActiveSheet()->setCellValue('A'.($i + $columnStart),
                formatName('', $students[$i]['preferredName'], $students[$i]['surname'], 'Student', true));

            $studentID = $students[$i]['gibbonPersonID'];

            $daysAttended = 0;
            for ($n = 0; $n < count($sessions); ++$n) {
                if (isset($attendance[$n][$studentID]) && !empty($attendance[$n][$studentID])) {
                    $excel->getActiveSheet()->setCellValue(num2alpha($n + 1).($i + $columnStart), '✓');
                    ++$daysAttended;
                }
            }

            // Add the totals for each student to the last column
            $excel->getActiveSheet()->setCellValue(num2alpha(count($sessions) + 1).($i + $columnStart), $daysAttended);
        }

        // Add the totals and timestamp data to the bottom of each column
        $excel->getActiveSheet()->setCellValue('A'.($columnStart + $columnEnd), __($guid, 'Total students:'))
            ->setCellValue('A'.($columnStart + $columnEnd + 1), __($guid, 'Recorded'))
            ->setCellValue('A'.($columnStart + $columnEnd + 2), __($guid, 'By'));

        $excel->getActiveSheet()->getRowDimension($columnStart + $columnEnd + 1)->setRowHeight(2 * 18);

        for ($i = 0; $i < count($sessions); ++$i) {
            $excel->getActiveSheet()->setCellValue(num2alpha($i + 1).($columnStart + $columnEnd),
                count($attendance[$i]));

            $excel->getActiveSheet()->getStyle(num2alpha($i + 1).($columnStart + 1 + $columnEnd))->getAlignment()->setWrapText(true);

            $excel->getActiveSheet()->setCellValue(num2alpha($i + 1).($columnStart + 1 + $columnEnd),
                substr($sessions[$i]['timestampTaken'], 11)."\r".dateConvertBack($guid, substr($sessions[$i]['timestampTaken'], 0, 10)));

            $excel->getActiveSheet()->setCellValue(num2alpha($i + 1).($columnStart + 2 + $columnEnd),
                formatName('', $sessions[$i]['preferredName'], $sessions[$i]['surname'], 'Staff', false, true));
        }
    }

    //FINALISE THE DOCUMENT SO IT IS READY FOR DOWNLOAD
    // Set active sheet index to the first sheet, so Excel opens this as the first sheet
    $excel->setActiveSheetIndex(0);

    // Redirect output to a client’s web browser (Excel2007)
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="'.$filename.'.xlsx"');
    header('Cache-Control: max-age=0');
    // If you're serving to IE 9, then the following may be needed
    header('Cache-Control: max-age=1');

    // If you're serving to IE over SSL, then the following may be needed
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
    header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT'); // always modified
    header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
    header('Pragma: public'); // HTTP/1.0

    $objWriter = PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
    $objWriter->save('php://output');
    exit;
}
