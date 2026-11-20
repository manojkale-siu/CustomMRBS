<?php

// This file is part of the MRBS block for Moodle
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
global $PAGE, $DB, $USER, $OUTPUT;
include "config.inc.php";
include "functions.php";
require_once('mrbs_auth.php');
include "mrbs_sql.php";

// Merge GET into POST if POST is empty
// if (empty($_POST) && !empty($_GET)) {
//     $_POST = $_GET;
// }

if (isset($_POST['rooms']) && is_array($_POST['rooms'])) {
    $_POST['rooms'] = array_values(array_unique($_POST['rooms']));
}

if (isset($_GET['rooms']) && is_array($_GET['rooms'])) {
    $_GET['rooms'] = array_values(array_unique($_GET['rooms']));
}

// Clean POST rooms - Remove the empty or zero room ids - trying to solve database error , that room is not found error.
if (isset($_POST['rooms']) && is_array($_POST['rooms'])) {
    $_POST['rooms'] = array_values(
        array_filter($_POST['rooms'], function($r) {
            return !empty($r) && $r != 0;
        })
    );
}

// Clean GET rooms
if (isset($_GET['rooms']) && is_array($_GET['rooms'])) {
    $_GET['rooms'] = array_values(
        array_filter($_GET['rooms'], function($r) {
            return !empty($r) && $r != 0;
        })
    );
}

// remove empty array "rooms"

// echo"<pre>";
// echo "edit_entry_handler - Session : ";
// print_r($SESSION);
// echo "<br>";

// echo "edit_entry_handler - GET : ";
// print_r($_GET);
// echo "<br>";

// echo "edit_entry_handler - POST : ";
// print_r($_POST);
// echo "<br>";

// echo"</pre>";

// exit;

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);
$period = optional_param('period', 0, PARAM_INT);
$hour = optional_param('hour', 0, PARAM_INT);
$minute = optional_param('minute', 0, PARAM_INT);
$durationraw = optional_param('duration', 0, PARAM_RAW);
$dur_units = optional_param('dur_units', 'periods', PARAM_TEXT);
$create_by = optional_param('create_by', '', PARAM_TEXT);
$name = optional_param('name', '', PARAM_TEXT);
$description = optional_param('description', '', PARAM_TEXT);
$id = optional_param('id', 0, PARAM_INT);
$programmeid = optional_param('programmeid', 0, PARAM_INT);
$batchid = optional_param('batchid', 0, PARAM_INT);
$semesterid = optional_param('semesterid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$facultyid = optional_param('facultyid', 0, PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$rep_type = optional_param('rep_type', 0, PARAM_INT);
$rep_end_month = optional_param('rep_end_month', 0, PARAM_INT);
$rep_end_day = optional_param('rep_end_day', 0, PARAM_INT);
$rep_end_year = optional_param('rep_end_year', 0, PARAM_INT);
$rep_num_weeks = optional_param('rep_num_weeks', 0, PARAM_INT);
$rep_opt = optional_param('rep_opt', '', PARAM_SEQUENCE);
$rep_enddate = optional_param('rep_enddate', 0, PARAM_INT);
$forcebook = optional_param('forcebook', false, PARAM_BOOL);
$edit_type = optional_param('edit_type', '', PARAM_TEXT);
$type = optional_param('type', '', PARAM_TEXT);
$all_day = optional_param('all_day', false, PARAM_BOOL);
$ampm = optional_param('ampm', 'NO_VALUE_FOUND_FOR_ampm', PARAM_TEXT);
// Deal with the 'array' params differently, depending on installed Moodle version
$rep_day = optional_param_array('rep_day', array(), PARAM_RAW);

$rooms = optional_param_array('rooms', array(), PARAM_INT);
$rooms = array_values(array_unique(array_filter($rooms)));

// print_r($rooms);
// exit;

$doublebook = optional_param('doublebook', 0, PARAM_INT);
$roomchange = optional_param('roomchange', false, PARAM_BOOL);

define('MRBS_ERR_DOUBLEBOOK', 1);
define('MRBS_ERR_TOOMANY', 2);

//If we dont know the right date then make it up
if (($day == 0) or ($month == 0) or ($year == 0)) {
    $day = date("d");
    $month = date("m");
    $year = date("Y");
}

if (!$area && !empty($rooms)) {
    $firstroom = reset($rooms);
    $roomarea = $DB->get_field('block_mrbs_room', 'area_id', array('id' => (int)$firstroom), IGNORE_MISSING);
    if ($roomarea) {
        $area = (int)$roomarea;
    }
}

if (!$area) {
    $area = get_default_area();
}

//TODO - put in some proper params for this (low priority)
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/edit_entry_handler.php'));
require_login();

if (!getAuthorised(1)) {
    // echo "AccessDenied FROM START... <br>";
    showAccessDenied($day, $month, $year, $area);
    exit;
}

$context = context_system::instance();
$PAGE->set_context($context);
//echo "102";

$prerequisites = block_mrbs_require_prerequisites($day, $month, $year, $area, 0, false, true);
$area = $prerequisites->areaid;

$roomadmin = false;
$editunconfirmed = mrbsCanOnlyEditUnconfirmed();
if ($id) {
    $storedentry = $DB->get_record('block_mrbs_entry', ['id' => $id], '*', MUST_EXIST);
    mrbsRequireEntryWrite($storedentry);
    $create_by = $storedentry->create_by;
    if ($edit_type === 'series' && $storedentry->repeat_id) {
        $storedrepeat = $DB->get_record('block_mrbs_repeat', ['id' => $storedentry->repeat_id], '*', MUST_EXIST);
        mrbsRequireEntryWrite($storedrepeat);
        try {
            mrbsRequireEditableSeries($storedrepeat->id);
        } catch (moodle_exception $error) {
            block_mrbs_print_user_error($day, $month, $year, $area, $error->getMessage());
            exit;
        }
        foreach ($DB->get_records('block_mrbs_entry', ['repeat_id' => $storedrepeat->id]) as $seriesentry) {
            mrbsRequireEntryWrite($seriesentry);
        }
        $create_by = $storedrepeat->create_by;
    }
} else {
    $create_by = $USER->username;
}

// Make sure that confirmed bookings can't be made by non-room admins
if (authGetUserLevel(getUserName()) < 2 && $editunconfirmed) {
    foreach ($rooms as $room) {
        $adminemail = $DB->get_field('block_mrbs_room', 'room_admin_email', array('id' => $room));
        if ($adminemail != $USER->email) {
            $type = 'U';
            break;
        }
    }
}

require_sesskey();

$description = trim($description);
if ($description == '') {
    print_header_mrbs($day, $month, $year, $area);
    echo('<h1>'.get_string('invalid_booking', 'block_mrbs').'<h1>');
    echo get_string('must_set_description', 'block_mrbs');
    echo $OUTPUT->footer();
    exit;
}

if (empty($rooms)) {
    block_mrbs_print_user_error($day, $month, $year, $area, get_string('valid_room', 'block_mrbs'));
    exit;
}

$roomdetails = $DB->get_records_list('block_mrbs_room', 'id', $rooms);
if (count($roomdetails) !== count($rooms)) {
    block_mrbs_print_user_error($day, $month, $year, $area, get_string('mrbsinvalidroom', 'block_mrbs'));
    exit;
}
foreach ($roomdetails as $roomdetail) {
    if ((int)$roomdetail->area_id !== (int)$area) {
        block_mrbs_print_user_error($day, $month, $year, $area, get_string('mrbsinvalidroom', 'block_mrbs'));
        exit;
    }
}

if (count($rooms) > 1 && !empty($facultyid)) {
    block_mrbs_print_user_error($day, $month, $year, $area, get_string('facultymultiroomnotallowed', 'block_mrbs'));
    exit;
}

$selectionerrors = \block_mrbs\local\booking_options::validate_selection(
    $programmeid,
    $batchid,
    $semesterid,
    $courseid,
    $facultyid,
    $groupid
);
if (!empty($selectionerrors)) {
    block_mrbs_print_user_error($day, $month, $year, $area, implode(' ', $selectionerrors));
    exit;
}

$name = \block_mrbs\local\reservation::build_name($courseid, $facultyid, $groupid);
if ($name === '') {
    block_mrbs_print_user_error($day, $month, $year, $area, get_string('must_set_name', 'block_mrbs'));
    exit;
}

if (!check_max_advance_days($day, $month, $year)) {
    print_header_mrbs($day, $month, $year, $area);
    echo('<h1>'.get_string('invalid_booking', 'block_mrbs').'<h1>');
    echo get_string('toofaradvance', 'block_mrbs', $max_advance_days);
    echo $OUTPUT->footer();
    exit;
}

// echo "<pre><br>179";

foreach ($roomdetails as $room) {
    if (!allowed_to_book($USER, $room)) {
        // TODO: Should admin users be allowed to override this?
        print_header_mrbs($day, $month, $year, $area);
        echo('<h1>'.get_string('invalid_booking', 'block_mrbs').'<h1>');
        echo get_string('notallowedbook', 'block_mrbs', $max_advance_days);
        echo $OUTPUT->footer();
        exit;
    }
}

// echo "<br>193";

// Support locales where ',' is used as the decimal point
$durationparts = explode(':', $durationraw, 2);
if ($dur_units == 'hours' && count($durationparts) == 2) {
    $duration = (float)intval($durationparts[0]) + ((float)intval($durationparts[1]) / 60.0);
} else {
    $duration = unformat_float($durationraw);
}

if ($enable_periods) {
    $schedulevalid = \block_mrbs\local\settings_manager::has_valid_custom_time_range();
    $resolution = $schedulevalid ? \block_mrbs\local\settings_manager::get_period_interval_seconds() : 60;
    $max_periods = $schedulevalid ? \block_mrbs\local\settings_manager::get_period_count() : count($periods);
}

// Units start in seconds
$units = 1.0;
// echo "<br>203";
// exit;
switch ($dur_units) {
    case "years":
        $units *= 52;
    case "weeks":
        $units *= 7;
    case "days":
        $units *= 24;
    case "hours":
        $units *= 60;
    case "periods":
    case "minutes":
        $units *= 60;
    case "seconds":
        break;
}

// echo "<br> Units : $units";
// echo "<br>220";

// Units are now in "$dur_units" numbers of seconds

if ($all_day) {
    if ($enable_periods) {
        if ($schedulevalid) {
            [$starttime, $endtime] = \block_mrbs\local\settings_manager::get_period_day_bounds($year, $month, $day);
        } else {
            $starttime = mktime(12, 0, 0, $month, $day, $year);
            $endtime = mktime(12, $max_periods, 0, $month, $day, $year);
        }
    } else {
        $starttime = mktime($morningstarts, 0, 0, $month, $day, $year);
        $end_minutes = $eveningends_minutes + $morningstarts_minutes;
        ($eveningends_minutes > 59) ? $end_minutes += 60 : '';
        $endtime = mktime($eveningends, $end_minutes, 0, $month, $day, $year);
    }
} else {
    if ($enable_periods && $schedulevalid) {
        $periodindex = (int)$period;
        if ($periodindex < 0 || $periodindex >= $max_periods) {
            block_mrbs_print_user_error($day, $month, $year, $area, get_string('sessionimportperiod', 'block_mrbs'));
            exit;
        }

        $starttime = \block_mrbs\local\settings_manager::get_period_start_timestamp($year, $month, $day, $periodindex);
        if ($dur_units == 'periods') {
            $periodduration = max(1, (int)round($duration));
            $endtime = $starttime + ($periodduration * $resolution);
        } else if ($dur_units == 'days') {
            $endtime = $starttime + (max(1, (int)round($duration)) * 86400);
        } else {
            $endtime = $starttime + ((int)$units * $duration);
            $diff = $endtime - $starttime;
            if (($tmp = $diff % $resolution) != 0 || $diff == 0) {
                $endtime += $resolution - $tmp;
            }
        }

        $endtime += cross_dst($starttime, $endtime);
    } else {
    if (!$twentyfourhour_format) {
        if (!is_null($ampm) && ($ampm == "pm") && ($hour < 12)) {
            $hour += 12;
        }
        if (!is_null($ampm) && ($ampm == "am") && ($hour > 11)) {
            $hour -= 12;
        }
    }

    $starttime = mktime($hour, $minute, 0, $month, $day, $year);
    $endtime = mktime($hour, $minute, 0, $month, $day, $year) + ($units * $duration);

    // Round up the duration to the next whole resolution unit.
    // If they asked for 0 minutes, push that up to 1 resolution unit.
    $diff = $endtime - $starttime;
    if (($tmp = $diff % $resolution) != 0 || $diff == 0) {
        $endtime += $resolution - $tmp;
    }

    $endtime += cross_dst($starttime, $endtime);
    }
}

if ($rep_type !== 6) {
    $rep_day = [];
    $rep_num_weeks = 0;
}
if ($rep_type === 2) {
    $rep_day[(int)date('w', $starttime)] = 1;
}
if ($rep_type !== 0) {
    if (!checkdate($rep_end_month, $rep_end_day, $rep_end_year)) {
        block_mrbs_print_user_error($day, $month, $year, $area, get_string('invalidrepeatenddate', 'block_mrbs'));
        exit;
    }
    $rep_enddate = mktime(23, 59, 59, $rep_end_month, $rep_end_day, $rep_end_year);
} else {
    $rep_enddate = $starttime;
}

if (!isset($rep_day)) {
    $rep_day = array();
}

// For weekly repeat(2), build string of weekdays to repeat on:
$rep_opt = "";
if (($rep_type == 2) || ($rep_type == 6)) {
    for ($i = 0; $i < 7; $i++) {
        $rep_opt .= empty($rep_day[$i]) ? "0" : "1";
    }
}

$reps = array();
if ($rep_type < 0 || $rep_type > 6) {
    block_mrbs_print_user_error(
        $day,
        $month,
        $year,
        $area,
        get_string('invalidrepeat', 'block_mrbs'),
        'MRBS invalid repeat type submitted: '.$rep_type
    );
    exit;
}

if (($rep_type == 2 || $rep_type == 6) && strpos($rep_opt, '1') === false) {
    block_mrbs_print_user_error($day, $month, $year, $area, get_string('rep_no_days_selected', 'block_mrbs'));
    exit;
}

if ($rep_type == 6 && (int)$rep_num_weeks < 2) {
    block_mrbs_print_user_error($day, $month, $year, $area, get_string('useful_n-weekly_value', 'block_mrbs'));
    exit;
}

if ($rep_type != 0 && (!isset($rep_enddate) || $rep_enddate < $starttime)) {
    block_mrbs_print_user_error(
        $day,
        $month,
        $year,
        $area,
        get_string('invalidrepeatenddate', 'block_mrbs'),
        'MRBS repeat end date is before start date. Start='.$starttime.' end='.(isset($rep_enddate) ? $rep_enddate : 0)
    );
    exit;
}

// Expand a series into a list of start times:
if ($rep_type != 0) {
    try {
        $reps = mrbsGetRepeatEntryList($starttime, isset($rep_enddate) ? $rep_enddate : 0,
                                       $rep_type, $rep_opt, $max_rep_entrys, $rep_num_weeks);
    } catch (Exception $e) {
        debugging('MRBS repeat expansion failed: '.$e->getMessage(), DEBUG_DEVELOPER);
        error_log('MRBS repeat expansion failed: '.$e->getMessage());
        block_mrbs_print_user_error($day, $month, $year, $area, get_string('repeatcreatefailed', 'block_mrbs'));
        exit;
    }

    if (!is_array($reps) || empty($reps)) {
        block_mrbs_print_user_error(
            $day,
            $month,
            $year,
            $area,
            get_string('invalidrepeat', 'block_mrbs'),
            'MRBS repeat expansion returned no entries for type '.$rep_type
        );
        exit;
    }
}

// Validate the proposed occurrences before conflict resolution can move any bookings.
try {
    \block_mrbs\local\booking_validator::validate_time_range($starttime, $endtime, (bool)$enable_periods);
    foreach ($reps as $occurrence) {
        $durationseconds = $endtime - $starttime;
        $occurrenceend = $occurrence + $durationseconds + cross_dst($occurrence, $occurrence + $durationseconds);
        \block_mrbs\local\booking_validator::validate_time_range($occurrence, $occurrenceend, (bool)$enable_periods);
    }
    mrbsValidateRepeatIntervals($reps, $starttime, $endtime);
} catch (moodle_exception $error) {
    block_mrbs_print_user_error($day, $month, $year, $area, $error->getMessage());
    exit;
}

// When checking for overlaps, for Edit (not New), ignore this entry and series:
$repeat_id = 0;
if ($id > 0) {
    $ignore_id = $id;
    $repeat_id = $DB->get_field('block_mrbs_entry', 'repeat_id', array('id' => $id));
    if ($repeat_id < 0) {
        $repeat_id = 0;
    }
} else {
    $ignore_id = 0;
}

$forcebook = $forcebook && mrbsHasAdminCapability('block/mrbs:forcebook') && !mrbsCanOnlyEditUnconfirmed();
$seriesignore = $edit_type === 'series' && $rep_type != 0 ? $repeat_id : 0;
$bookinglock = \core\lock\lock_config::get_lock_factory('block_mrbs')->get_lock('save_booking', 10);
if (!$bookinglock) {
    block_mrbs_print_user_error($day, $month, $year, $area, get_string('failed_to_acquire', 'block_mrbs'));
    exit;
}
register_shutdown_function([$bookinglock, 'release']);

$studenttimes = $rep_type != 0 && !empty($reps) ? $reps : [$starttime];
foreach ($studenttimes as $studentstart) {
    $studentend = $studentstart + ($endtime - $starttime) + cross_dst($studentstart, $studentstart + ($endtime - $starttime));
    if (\block_mrbs\local\booking_validator::find_student_conflicts(
            $courseid, $groupid, $studentstart, $studentend, $ignore_id, $seriesignore)) {
        block_mrbs_print_user_error($day, $month, $year, $area, get_string('studentbookingconflict', 'block_mrbs'));
        exit;
    }
}

if ($facultyid) {
    $facultyconflicts = array();
    if ($rep_type != 0 && !empty($reps)) {
        foreach ($reps as $reptime) {
            $diff = $endtime - $starttime;
            $diff += cross_dst($reptime, $reptime + $diff);
            $facultyconflicts += \block_mrbs\local\booking_validator::find_faculty_conflicts(
                $facultyid,
                $reptime,
                $reptime + $diff,
                $ignore_id,
                $seriesignore
            );
        }
    } else {
        $facultyconflicts = \block_mrbs\local\booking_validator::find_faculty_conflicts(
            $facultyid,
            $starttime,
            $endtime,
            $ignore_id,
            $seriesignore
        );
    }

    if (!empty($facultyconflicts)) {
        print_header_mrbs($day, $month, $year, $area);
        echo html_writer::tag('h1', get_string('invalid_booking', 'block_mrbs'));
        echo \block_mrbs\local\booking_validator::faculty_conflict_message($facultyconflicts);
        echo $OUTPUT->footer();
        exit;
    }
}

// echo "<br>296<br>";
// exit;

// Acquire mutex to lock out others trying to book the same slot(s).
//if (!sql_mutex_lock("$tbl_entry"))
//fatal_error(1, get_string('failed_to_acquire','block_mrbs'));

// Check for any schedule conflicts in each room we're going to try and
// book in
$err = "";
$errtype = 0;
$forcemoveoutput = '';

if ($rep_type != 0 && count($reps) >= $max_rep_entrys) {
    $err .= get_string('too_may_entrys', 'block_mrbs')."<P>";
    $errtype = MRBS_ERR_TOOMANY;
    $hide_title = 1;
}

$forcemoves = [];
if ($forcebook && !$err) {
    if ($type === 'U') {
        block_mrbs_print_user_error($day, $month, $year, $area, get_string('forcebookconfirmedtype', 'block_mrbs'));
        exit;
    }

    $slots = [[$starttime, $endtime]];
    if ($rep_type != 0 && !empty($reps)) {
        $slots = [];
        foreach ($reps as $reptime) {
            if (!check_max_advance_days_timestamp($reptime)) {
                break;
            }
            $duration = $endtime - $starttime;
            $slots[] = [$reptime, $reptime + $duration + cross_dst($reptime, $reptime + $duration)];
        }
    }
    require_once(__DIR__ . '/force_book.php');
    $conflicts = mrbsFindForceConflicts($rooms, $slots, $ignore_id, $seriesignore);
    $forcemoves = mrbsPlanForceMoves($rooms, $slots, $conflicts);
    if ($forcemoves === null) {
        print_header_mrbs($day, $month, $year, $area);
        echo $OUTPUT->heading(get_string('sched_conflict', 'block_mrbs'));
        echo $OUTPUT->notification(get_string('forcebooknoroom', 'block_mrbs'), 'warning');
        echo html_writer::tag('p', get_string('forcebookreschedulehelp', 'block_mrbs'));

        $actions = [];
        foreach ($conflicts as $entry) {
            $entryname = \block_mrbs\local\reservation::get_display_name($entry);
            $label = get_string('forcebookrescheduleentry', 'block_mrbs', (object)[
                'name' => $entryname,
                'time' => userdate($entry->start_time),
            ]);
            $editurl = new moodle_url('/blocks/mrbs/web/edit_entry.php', ['id' => $entry->id]);
            $actions[] = html_writer::link($editurl, s($label));
        }
        echo html_writer::alist($actions);
        if ($id) {
            $editurl = new moodle_url('/blocks/mrbs/web/edit_entry.php', ['id' => $id, 'edit_type' => $edit_type]);
            echo html_writer::div(html_writer::link($editurl, get_string('forcebookreturnselected', 'block_mrbs')), 'mb-3');
        }
        $calendarurl = new moodle_url('/blocks/mrbs/web/day.php', compact('day', 'month', 'year', 'area'));
        echo html_writer::link($calendarurl, get_string('returncal', 'block_mrbs'));
        include __DIR__ . '/trailer.php';
        exit;
    }
}

foreach ($rooms as $room_id) {
    if ($forcebook || $errtype == MRBS_ERR_TOOMANY) {
        break;
    }

    if ($rep_type != 0 && !empty($reps)) {
        foreach ($reps as $reptime) {
            // calculate diff each time and correct where events
            // cross DST
            $diff = $endtime - $starttime;
            $diff += cross_dst($reptime, $reptime + $diff);
            $tmp = mrbsCheckFree($room_id, $reptime, $reptime + $diff, $ignore_id, $seriesignore);
            if (!empty($tmp)) {
                $err = $err.$tmp;
                $errtype = MRBS_ERR_DOUBLEBOOK;
            }
        }
        
        // if ($reps < $max_rep_entrys) {
            
        //     echo "I am in second if. <br>";
        //     exit;
            
        //     for ($i = 0; $i < $reps; $i++) {
        //         // calculate diff each time and correct where events
        //         // cross DST
        //         $diff = $endtime - $starttime;
        //         $diff += cross_dst($reps[$i], $reps[$i] + $diff);
        //         $tmp = mrbsCheckFree($room_id, $reps[$i], $reps[$i] + $diff, $ignore_id, $repeat_id);
        //         if (!empty($tmp)) {
        //             $err = $err.$tmp;
        //             $errtype = MRBS_ERR_DOUBLEBOOK;
        //         }
        //     }
        // } else {
        //     echo "I am in else <br>";
        //     exit;
        //     $err .= get_string('too_may_entrys', 'block_mrbs')."<P>";
        //     $errtype = MRBS_ERR_TOOMANY;
        //     $hide_title = 1;
        // }
    } else {
        // echo "I am in first else part <br>";
        if ($doublebook && mrbsHasAdminCapability('block/mrbs:doublebook')) {
            // An explicit authorised override permits the room overlap.
        } else {
            // echo "<br> I am inner else-else. <br> vardump : ";
            // If the user hasn't confirmed they want to double book, check the room is free.
            $err .= mrbsCheckFree($room_id, $starttime, $endtime, $ignore_id, 0);
            
            // var_dump($err);
            // echo "<br></pre>";
        }
    }

} // end foreach rooms

// echo "<br> Line : 434 - Error : . $err . <br>";
// exit;

if (empty($err)) {
    // echo "<br> Line : 438  : Error is empty. <br>";
    // $rooms = array(1,2,3);
    // echo "<br> Rooms : ";
    // print_r($rooms);
    // echo "<br>";
    // exit;
    
    
    
    $transaction = $DB->start_delegated_transaction();
    try {
        if ($forcemoves) {
            mrbsApplyForceMoves($forcemoves);
        }
        foreach ($rooms as $room_id) {

            if ($edit_type == "series" && isset($rep_type) && $rep_type != 0) {
                $rep_num_weeks = (int)$rep_num_weeks;

                $rep_details = mrbsCreateRepeatingEntrys($starttime, $endtime, $rep_type, $rep_enddate, $rep_opt,
                                                             $room_id, $create_by, $name, $type, $description,
                                                             $rep_num_weeks, $roomchange, $id, $courseid, $facultyid, $groupid);

                $new_id = $rep_details->id ?? 0;
                if (!$new_id) {
                    throw new moodle_exception('repeatcreatefailed', 'block_mrbs');
                }

                $enddate = null;
                if ($rep_details->created !== $rep_details->requested) {
                    throw new moodle_exception('repeatcreatefailed', 'block_mrbs');
                }

                $sql = "SELECT r.id, r.room_name, r.area_id, a.area_name ";
                $sql .= "FROM {block_mrbs_room} r, {block_mrbs_area} a ";
                $sql .= "WHERE r.id=? AND r.area_id = a.id";
                $dbroom = $DB->get_record_sql($sql, array($room_id), MUST_EXIST);
                $room_name = $dbroom->room_name;
                $area_name = $dbroom->area_name;

                //Add to moodle logs
                $params = array(
                    'objectid' => $new_id,
                    'context' => $context,
                    'other' => array('name' => $name, 'room' => $room_name),
                );
                $eventclass = $id > 0 ? '\block_mrbs\event\booking_updated' : '\block_mrbs\event\booking_created';
                $event = $eventclass::create($params);
                $event->trigger();

                if ($rep_details->repeating) {
                    $sessionids = \block_mrbs\local\attendance_manager::sync_for_repeat($new_id, $courseid, $facultyid, $groupid);
                    if (count($sessionids) !== $rep_details->created) {
                        throw new moodle_exception('sessioncreatefailed', 'block_mrbs');
                    }
                } else if (!\block_mrbs\local\attendance_manager::sync_for_entry($new_id, $courseid, $facultyid, $groupid)) {
                    throw new moodle_exception('sessioncreatefailed', 'block_mrbs');
                }

            } else {
                // Mark changed entry in a series with entry_type 2:
                if ($repeat_id > 0) {
                    $entry_type = 2;
                } else {
                    $entry_type = 0;
                }
                $new_id = mrbsCreateSingleEntry($starttime, $endtime, $entry_type, $repeat_id, $room_id,
                                                    $create_by, $name, $type, $description, $id, $roomchange,
                                                    $courseid, $facultyid, $groupid);
                if (empty($new_id)) {
                    throw new moodle_exception('bookingcreatefailed', 'block_mrbs');
                }

                $sql = "SELECT r.id, r.room_name, r.area_id, a.area_name ";
                $sql .= "FROM {block_mrbs_room} r, {block_mrbs_area} a ";
                $sql .= "WHERE r.id=? AND r.area_id = a.id";
                $dbroom = $DB->get_record_sql($sql, array($room_id), MUST_EXIST);
                $room_name = $dbroom->room_name;
                $area_name = $dbroom->area_name;

                //Add to moodle logs
                $params = array(
                    'objectid' => $new_id,
                    'context' => $context,
                    'other' => array('name' => $name, 'room' => $room_name),
                );
                $eventclass = $id > 0 ? '\block_mrbs\event\booking_updated' : '\block_mrbs\event\booking_created';
                $event = $eventclass::create($params);
                $event->trigger();

                if (!\block_mrbs\local\attendance_manager::sync_for_entry($new_id, $courseid, $facultyid, $groupid)) {
                    throw new moodle_exception('sessioncreatefailed', 'block_mrbs');
                }

            }
        }
        $transaction->allow_commit();
    } catch (Throwable $error) {
        try {
            $transaction->rollback($error);
        } catch (Throwable $rolledback) {
            debugging('MRBS booking save rolled back: ' . $rolledback->getMessage(), DEBUG_DEVELOPER);
            block_mrbs_print_user_error($day, $month, $year, $area, get_string('bookingsaverolledback', 'block_mrbs'));
            exit;
        }
    }

    $attendancewarnings = \block_mrbs\local\attendance_manager::take_warnings();
    if ($attendancewarnings) {
        $forcemoveoutput .= ' ' . implode(' ', array_map('s', $attendancewarnings));
    }
    if ($forcemoves) {
        $forcemoveoutput .= mrbsNotifyForceMoves($forcemoves, $name);
    }

    //    sql_mutex_unlock("$tbl_entry");

    $area = mrbsGetRoomArea($room_id);
    
    // echo "LINE : 595";
    // die("DIE ON LINE : 598"); 

    // Now its all done go back to the day view
    $dayurl = new moodle_url('/blocks/mrbs/web/day.php', array('year' => $year, 'month' => $month, 'day' => $day, 'area' => $area));
    $notificationtext = get_string('bookingsavednotification', 'block_mrbs', (object)[
        'name' => strip_tags($name), 'date' => userdate($starttime),
    ]);
    if ($forcemoveoutput !== '') {
        $notificationtext .= ' ' . html_to_text($forcemoveoutput);
    }
    if (!\block_mrbs\local\booking_notification::send($notificationtext, $dayurl)) {
        $forcemoveoutput .= ' ' . get_string('bookingnotificationfailed', 'block_mrbs');
    }
    redirect($dayurl, $forcemoveoutput, empty($forcemoveoutput) ? 0 : 5);
    exit;
}
// echo "LINE : 602";
// exit;
// The room was not free.

if (strlen($err)) {
    print_header_mrbs($day, $month, $year, $area);

    echo "<H2>".get_string('sched_conflict', 'block_mrbs')."</H2>";
    if (!isset($hide_title)) {
        echo get_string('conflict', 'block_mrbs');
        echo "<UL>";
    }

    echo $err;
    if (!isset($hide_title)) {
        echo "</UL>";
    }
    if (!isset($hide_title) && $id && mrbsHasAdminCapability('block/mrbs:forcebook') && !mrbsCanOnlyEditUnconfirmed()) {
        $keepurl = new moodle_url('/blocks/mrbs/web/edit_entry.php', [
            'id' => $id, 'edit_type' => $edit_type, 'force' => 1,
        ]);
        echo html_writer::div(html_writer::link($keepurl, get_string('forcebookkeepentry', 'block_mrbs')), 'my-3');
    }
    if (mrbsHasAdminCapability('block/mrbs:doublebook') && $errtype == MRBS_ERR_DOUBLEBOOK) {
        $thisurl = new moodle_url('/blocks/mrbs/web/edit_entry_handler.php');
        echo '<form method="post" action="'.$thisurl.'">';
        echo '<input type="hidden" name="name" value="'.s($name).'" />';
        echo '<input type="hidden" name="description" value="'.s($description).'" />';
        echo '<input type="hidden" name="day" value="'.(int)$day.'" />';
        echo '<input type="hidden" name="month" value="'.(int)$month.'" />';
        echo '<input type="hidden" name="year" value="'.(int)$year.'" />';
        echo '<input type="hidden" name="area" value="'.(int)$area.'" />';
        echo '<input type="hidden" name="create_by" value="'.s($create_by).'" />';
        echo '<input type="hidden" name="id" value="'.(int)$id.'" />';
        echo '<input type="hidden" name="programmeid" value="'.(int)$programmeid.'" />';
        echo '<input type="hidden" name="batchid" value="'.(int)$batchid.'" />';
        echo '<input type="hidden" name="semesterid" value="'.(int)$semesterid.'" />';
        echo '<input type="hidden" name="courseid" value="'.(int)$courseid.'" />';
        echo '<input type="hidden" name="facultyid" value="'.(int)$facultyid.'" />';
        echo '<input type="hidden" name="groupid" value="'.(int)$groupid.'" />';
        echo '<input type="hidden" name="rep_type" value="'.(int)$rep_type.'" />';
        echo '<input type="hidden" name="rep_end_month" value="'.(int)$rep_end_month.'" />';
        echo '<input type="hidden" name="rep_end_day" value="'.(int)$rep_end_day.'" />';
        echo '<input type="hidden" name="rep_end_year" value="'.(int)$rep_end_year.'" />';
        echo '<input type="hidden" name="rep_num_weeks" value="'.(int)$rep_num_weeks.'" />';
        foreach ($rep_day as $repeatday => $repeatdayenabled) {
            echo '<input type="hidden" name="rep_day['.(int)$repeatday.']" value="'.s($repeatdayenabled).'" />';
        }
        echo '<input type="hidden" name="rep_opt" value="'.s($rep_opt).'" />';
        echo '<input type="hidden" name="rep_enddate" value="'.(int)$rep_enddate.'" />';
        echo '<input type="hidden" name="hour" value="'.(int)$hour.'" />';
        echo '<input type="hidden" name="minute" value="'.(int)$minute.'" />';
        echo '<input type="hidden" name="period" value="'.(int)$period.'" />';
        echo '<input type="hidden" name="duration" value="'.s($duration).'" />';
        echo '<input type="hidden" name="dur_units" value="'.s($dur_units).'" />';
        echo '<input type="hidden" name="type" value="'.s($type).'" />';
        foreach ($rooms as $room) {
            echo '<input type="hidden" name="rooms[]" value="'.(int)$room.'" />';
        }
        echo '<input type="hidden" name="doublebook" value="1" />';
        echo '<input type="hidden" name="sesskey" value="'.sesskey().'" />';
        echo '<input type="submit" name="submit" value="'.get_string('idontcare', 'block_mrbs').'" />';
        echo '</form>';
    }
}

// echo "LINE : 658";
// exit;

$returl = new moodle_url('/blocks/mrbs/web/index.php');
echo "<a href=\"$returl\">".get_string('returncal', 'block_mrbs')."</a><p>";

include "trailer.php";
