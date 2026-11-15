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
if (!getWritable($create_by, getUserName())) {
    if ($editunconfirmed) {
        foreach ($rooms as $key => $room) {
            $adminemail = $DB->get_field('block_mrbs_room', 'room_admin_email', array('id' => $room));
            if ($adminemail == $USER->email) {
                $roomadmin = true;
            } else {
                unset($rooms[$key]);
            }
        }
    }

    if (!$roomadmin) {
        // echo "AccessDenied because the room is not confirmed ... <br>";
        showAccessDenied($day, $month, $year, $area);
        exit;
    }
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

$capacitycheck = \block_mrbs\local\booking_validator::check_room_capacity($courseid, $groupid, $rooms);
if (!empty($capacitycheck['blocked'])) {
    block_mrbs_print_user_error(
        $day,
        $month,
        $year,
        $area,
        get_string('capacitywarningmessage', 'block_mrbs')
    );
    exit;
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
        $periodindex = max(0, (int)$period);
        if ($periodindex >= $max_periods) {
            $periodindex = $max_periods - 1;
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

// if (isset($rep_type) && isset($rep_end_month) && isset($rep_end_day) && isset($rep_end_year)) {
//     // Get the repeat entry settings
//     $rep_enddate = mktime($hour, $minute, 0, $rep_end_month, $rep_end_day, $rep_end_year);
// } else {
//     $rep_type = 0;
// }


if (isset($rep_type) && isset($rep_end_month) && isset($rep_end_day) && isset($rep_end_year)) {
    // Use end-of-day for rep_enddate so a series covering that day includes it.
    $rep_enddate = mktime(23, 59, 59,
                          (int)$rep_end_month,
                          (int)$rep_end_day,
                          (int)$rep_end_year);

    // If rep_enddate is earlier than starttime, try to extend it using rep_num_weeks.
    if (isset($starttime) && $rep_enddate < $starttime) {
        // If rep_num_weeks provided, extend rep_enddate by that many weeks.
        if (!empty($rep_num_weeks) && is_numeric($rep_num_weeks) && ((int)$rep_num_weeks > 0)) {
            $rep_enddate = $starttime + ((int)$rep_num_weeks * 7 * 24 * 3600);
        } else {
            // If we can't fix it, disable repeating to avoid invalid series creation.
            $rep_type = 0;
        }
    }
} else {
    $rep_type = 0;
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
                $repeat_id
            );
        }
    } else {
        $facultyconflicts = \block_mrbs\local\booking_validator::find_faculty_conflicts(
            $facultyid,
            $starttime,
            $endtime,
            $ignore_id,
            $repeat_id
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

foreach ($rooms as $room_id) {
    if ($errtype == MRBS_ERR_TOOMANY) {
        break;
    }

    if ($rep_type != 0 && !empty($reps)) {
        foreach ($reps as $reptime) {
            // calculate diff each time and correct where events
            // cross DST
            $diff = $endtime - $starttime;
            $diff += cross_dst($reptime, $reptime + $diff);
            $tmp = mrbsCheckFree($room_id, $reptime, $reptime + $diff, $ignore_id, $repeat_id);
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
        if (mrbsHasAdminCapability('block/mrbs:forcebook') and $forcebook) {
            // echo "I am inner else-if. <br>";
            
            require_once "force_book.php";
            $forcemoveoutput .= mrbsForceMove($room_id, $starttime, $endtime, $name, $id);
            //do this so that it thinks no clashes were found
            $tmp = '';
        } else if ($doublebook and mrbsHasAdminCapability('block/mrbs:doublebook')) {
            // echo "I am inner else else-if . <br>";
            
            $sql = 'SELECT entry.id AS entryid,
                entry.name as entryname,
                entry.create_by,
                room.room_name,
                entry.start_time
              FROM {block_mrbs_entry} as entry
                join {block_mrbs_room} as room on entry.room_id = room.id
             WHERE room.id = ?
             AND ((entry.start_time >= ? AND entry.end_time < ?)
             OR (entry.start_time < ? AND entry.end_time> ?)
             OR (entry.start_time < ? AND entry.end_time>= ?))';
            // echo $sql;
            // die();
            $clashingbookings = $DB->get_records_sql($sql, array(
                $room_id, $starttime, $endtime, $starttime, $starttime, $endtime, $endtime
            ));
            foreach ($clashingbookings as $clashingbooking) {
                $oldbookinguser = $DB->get_record('user', array('username' => $clashingbooking->create_by));
                $langvars->user = $USER->firstname.' '.$USER->lastname;
                $langvars->room = $clashingbooking->room_name;
                $langvars->time = to_hr_time($clashingbooking->start_time);
                $langvars->date = userdate($clashingbooking->start_time, '%A %d/%m/%Y');
                $langvars->oldbooking = $clashingbooking->entryname;
                $langvars->newbooking = $name;
                $langvars->admin = $mrbs_admin.' ('.$mrbs_admin_email.')';

                // Send emails to user with existing booking
                if (!email_to_user($oldbookinguser, $USER, get_string('doublebookesubject', 'block_mrbs'), get_string('doublebookebody', 'block_mrbs', $langvars))) {
                    email_to_user($DB->get_record('user', array('email' => $mrbs_admin_email)), $USER, get_string('doublebookefailsubject', 'block_mrbs'), get_string('doublebookefailbody', 'block_mrbs', $oldbookinguser->username).get_string('doublebookebody', 'block_mrbs', $langvars));
                }
            }
        } else {
            // echo "<br> I am inner else-else. <br> vardump : ";
            // If the user hasn't confirmed they want to double book, check the room is free.
            $err .= mrbsCheckFree($room_id, $starttime, $endtime - 1, $ignore_id, 0);
            
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
    
    
    
    foreach ($rooms as $room_id) {
        
        if ($edit_type == "series" && isset($rep_type) && $rep_type != 0) {
            // echo "I am in If part (series)<br> ";
            // exit;
            
            // echo "<pre>";
            // echo "================ DEBUG VARIABLES ================\n";
            // echo "starttime: " . var_export($starttime, true) . "\n";
            // echo "endtime: " . var_export($endtime, true) . "\n";
            // echo "rep_type: " . var_export($rep_type, true) . "\n";
            // echo "rep_enddate: " . var_export($rep_enddate, true) . "\n";
            // echo "rep_opt: " . var_export($rep_opt, true) . "\n";
            // echo "room_id: " . var_export($room_id, true) . "\n";
            // echo "create_by: " . var_export($create_by, true) . "\n";
            // echo "name: " . var_export($name, true) . "\n";
            // echo "type: " . var_export($type, true) . "\n";
            // echo "description: " . var_export($description, true) . "\n";
            // echo "rep_num_weeks: " . var_export(isset($rep_num_weeks) ? $rep_num_weeks : 0, true) . "\n";
            // echo "roomchange: " . var_export($roomchange, true) . "\n";
            // echo "id: " . var_export($id, true) . "\n";
            // echo "=================================================\n";
            // echo "</pre>";

            // exit;
            // $rep_details = mrbsCreateRepeatingEntrys($starttime, $endtime, $rep_type, $rep_enddate, $rep_opt,
            //                                          $room_id, $create_by, $name, $type, $description,
            //                                          isset($rep_num_weeks) ? $rep_num_weeks : 0, $roomchange, $id);
            // $new_id = $rep_details->id;
            
            
            
            // Validate inputs for repeating creation
            // if (!isset($rep_type) || $rep_type == 0) {
            //     // No repeat requested - skip repeating creation
            //     $rep_details = false;
            //     $new_id = 0;
            //     echo "<br> No repeate that is why the mrbsCreateRepeatingEntrys() method is not invoked<br>";
            // } 
            
            // else {
                $rep_num_weeks = isset($rep_num_weeks) ? (int)$rep_num_weeks : 0;

            try {
                $rep_details = mrbsCreateRepeatingEntrys($starttime, $endtime, $rep_type, $rep_enddate, $rep_opt,
                                                         $room_id, $create_by, $name, $type, $description,
                                                         $rep_num_weeks, $roomchange, $id, $courseid, $facultyid, $groupid);
            } catch (Exception $e) {
                debugging('MRBS repeating entry creation failed: '.$e->getMessage(), DEBUG_DEVELOPER);
                error_log("MRBS repeating entry creation failed - params: start=$starttime end=$endtime rep_type=$rep_type rep_end=$rep_enddate rep_opt=$rep_opt rep_num_weeks=$rep_num_weeks room=$room_id name=$name error=".$e->getMessage());
                block_mrbs_print_user_error($day, $month, $year, $area, get_string('repeatcreatefailed', 'block_mrbs'));
                exit;
            }
             
                // Defensive handling of failure modes from mrbsCreateRepeatingEntrys
                if (!$rep_details) {
                    debugging('MRBS repeating entry creation returned false', DEBUG_DEVELOPER);
                    error_log("mrbsCreateRepeatingEntrys failed - params: start=$starttime end=$endtime rep_type=$rep_type rep_end=$rep_enddate rep_opt=$rep_opt rep_num_weeks=$rep_num_weeks room=$room_id name=$name");
                    block_mrbs_print_user_error($day, $month, $year, $area, get_string('repeatcreatefailed', 'block_mrbs'));
                    exit;
                } else if (!isset($rep_details->id)) {
                    debugging('MRBS repeating entry creation returned no id', DEBUG_DEVELOPER);
                    error_log("mrbsCreateRepeatingEntrys returned: " . var_export($rep_details, true));
                    block_mrbs_print_user_error($day, $month, $year, $area, get_string('repeatcreatefailed', 'block_mrbs'));
                    exit;
                } else {
                    $new_id = $rep_details->id;
                }
                if (empty($new_id)) {
                    block_mrbs_print_user_error($day, $month, $year, $area, get_string('bookingcreatefailed', 'block_mrbs'));
                    exit;
                }
            // }

            
            
            
            // echo "Rep Details : ";
            // var_dump($rep_details);
            // echo "<br> Rep id : " . $new_id;
            // echo "<br> Error : " . $err . "<br>";
            // exit;

            $enddate = null;
            if ($rep_details->created && $rep_details->created < $rep_details->requested) {
                $forcemoveoutput .= get_string('notallcreated', 'block_mrbs', $rep_details);
                $enddate = $rep_details->lasttime;
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

            try {
                if (!empty($new_id)) {
                    \block_mrbs\local\attendance_manager::sync_for_repeat($new_id, $courseid, $facultyid, $groupid);
                }
            } catch (Exception $e) {
                debugging('MRBS attendance session sync failed: '.$e->getMessage(), DEBUG_DEVELOPER);
                error_log('MRBS attendance session sync failed for repeat '.$new_id.': '.$e->getMessage());
                $forcemoveoutput .= ' '.get_string('sessioncreatefailed', 'block_mrbs');
            }
             
            // echo "<br>The Event has been triggerd <br>";
            
            // Send a mail to the Administrator
            if (MAIL_ADMIN_ON_BOOKINGS or MAIL_AREA_ADMIN_ON_BOOKINGS or MAIL_ROOM_ADMIN_ON_BOOKINGS or MAIL_BOOKER) {
                // Send a mail only if this a new entry, or if this is an
                // edited entry but we have to send mail on every change,
                // and if mrbsCreateRepeatingEntrys is successful
                if (((($id > 0) && MAIL_ADMIN_ALL) or ($id == 0)) && (0 != $new_id)) {
                    // If this is a modified entry then call
                    // getPreviousEntryData to prepare entry comparison.
                    if ($id > 0) {
                        $mail_previous = getPreviousEntryData($id, $rep_details->repeating);
                    }
                    $result = notifyAdminOnBooking(($id == 0), $new_id, $enddate);
                }
            }
            
            // echo "<br> If executed perfectly. <br>";
            // exit;
        } else {
            // echo "I am in series-else part <br>";
            // exit; 
            // Mark changed entry in a series with entry_type 2:
            if ($repeat_id > 0) {
                $entry_type = 2;
            } else {
                $entry_type = 0;
            }

            // echo "<br>mrbsCreateSingleEntry is getting invoked.";
            try {
                // Create / update the entry:
                $new_id = mrbsCreateSingleEntry($starttime, $endtime, $entry_type, $repeat_id, $room_id,
                                                $create_by, $name, $type, $description, $id, $roomchange,
                                                $courseid, $facultyid, $groupid);
            } catch (Exception $e) {
                debugging('MRBS single entry creation failed: '.$e->getMessage(), DEBUG_DEVELOPER);
                error_log('MRBS single entry creation failed: '.$e->getMessage());
                block_mrbs_print_user_error($day, $month, $year, $area, get_string('bookingcreatefailed', 'block_mrbs'));
                exit;
            }
            if (empty($new_id)) {
                block_mrbs_print_user_error($day, $month, $year, $area, get_string('bookingcreatefailed', 'block_mrbs'));
                exit;
            }
            // echo "<br>mrbsCreateSingleEntry is getting invoked (DONE)<br>";
            // exit;
             
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

            try {
                if (!empty($new_id)) {
                    \block_mrbs\local\attendance_manager::sync_for_entry($new_id, $courseid, $facultyid, $groupid);
                }
            } catch (Exception $e) {
                debugging('MRBS attendance session sync failed: '.$e->getMessage(), DEBUG_DEVELOPER);
                error_log('MRBS attendance session sync failed for entry '.$new_id.': '.$e->getMessage());
                $forcemoveoutput .= ' '.get_string('sessioncreatefailed', 'block_mrbs');
            }

            // Send a mail to the Administrator
            if (MAIL_ADMIN_ON_BOOKINGS or MAIL_AREA_ADMIN_ON_BOOKINGS or MAIL_ROOM_ADMIN_ON_BOOKINGS or MAIL_BOOKER) {
                // Send a mail only if this a new entry, or if this is an
                // edited entry but we have to send mail on every change,
                // and if mrbsCreateRepeatingEntrys is successful
                if (((($id > 0) && MAIL_ADMIN_ALL) or ($id == 0)) && (0 != $new_id)) {
                    // If this is a modified entry then call
                    // getPreviousEntryData to prepare entry comparison.
                    if ($id > 0) {
                        $mail_previous = getPreviousEntryData($id, 0);
                    }
                    $result = notifyAdminOnBooking(($id == 0), $new_id);
                }
            }
        }
    } // end foreach $rooms

    //    sql_mutex_unlock("$tbl_entry");

    $area = mrbsGetRoomArea($room_id);
    
    // echo "LINE : 595";
    // die("DIE ON LINE : 598"); 

    // Now its all done go back to the day view
    $dayurl = new moodle_url('/blocks/mrbs/web/day.php', array('year' => $year, 'month' => $month, 'day' => $day, 'area' => $area));
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

    if (!isset($hide_title)) {
        echo "</UL>";
    }
}

// echo "LINE : 658";
// exit;

$returl = new moodle_url('/blocks/mrbs/web/index.php');
echo "<a href=\"$returl\">".get_string('returncal', 'block_mrbs')."</a><p>";

include "trailer.php";
