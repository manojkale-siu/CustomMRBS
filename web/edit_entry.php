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

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php'); //for Moodle integration
global $PAGE, $DB;
include "config.inc.php";
include "functions.php";
require_once('mrbs_auth.php');

global $twentyfourhour_format, $morningstarts;

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);
$edit_type = optional_param('edit_type', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$room = optional_param('room', 0, PARAM_INT);
$hour = optional_param('hour', '', PARAM_INT);
$minute = optional_param('minute', '', PARAM_INT);
$force = optional_param('force', false, PARAM_BOOL);
$period = optional_param('period', 0, PARAM_INT);
$all_day = optional_param('all_day', false, PARAM_BOOL);
$searchduration = optional_param('searchduration', 0, PARAM_INT);
$searchunits = optional_param('searchunits', '', PARAM_ALPHA);

//If we dont know the right date then make it up
if (($day == 0) or ($month == 0) or ($year == 0)) {
    $day = \block_mrbs\local\booking_time::date("d");
    $month = \block_mrbs\local\booking_time::date("m");
    $year = \block_mrbs\local\booking_time::date("Y");
}

$thisurl = new moodle_url('/blocks/mrbs/web/edit_entry.php', array('day' => $day, 'month' => $month, 'year' => $year));

if ($area) {
    $thisurl->param('area', $area);
} else {
    $area = get_default_area();
}
if ($id) {
    $thisurl->param('id', $id);
}
if ($force) {
    $thisurl->param('force', $force);
}
if ($room) {
    $thisurl->param('room', $room);
}
if (!empty($edit_type)) {
    $thisurl->param('edit_type', $edit_type);
}
if (!empty($hour)) {
    $thisurl->param('hour', $hour);
}
if (!empty($minute)) {
    $thisurl->param('minute', $minute);;
}

$PAGE->set_url($thisurl);
require_login();

if (!getAuthorised(1)) {
    showAccessDenied($day, $month, $year, $area);
    exit;
}

// This page will either add or modify a booking

// We need to know:
//  Name of booker
//  Description of meeting
//  Date (option select box for day, month, year)
//  Time
//  Duration
//  Internal/External

// Firstly we need to know if this is a new booking or modifying an old one
// and if it's a modification we need to get all the old data from the db.
// If we had $id passed in then it's a modification.
if ($id > 0) {
    $entry = $DB->get_record('block_mrbs_entry', array('id' => $id), '*', MUST_EXIST);
    // Note: Removed stripslashes() calls from name and description. Previous
    // versions of MRBS mistakenly had the backslash-escapes in the actual database
    // records because of an extra addslashes going on. Fix your database and
    // leave this code alone, please.
    $name = $entry->name;
    $create_by = $entry->create_by;
    $description = $entry->description;
    $start_time = $entry->start_time;
    $start_day = \block_mrbs\local\booking_time::userdate($entry->start_time, '%d');
    $start_month = \block_mrbs\local\booking_time::userdate($entry->start_time, '%m');
    $start_year = \block_mrbs\local\booking_time::userdate($entry->start_time, '%Y');
    $start_hour = \block_mrbs\local\booking_time::userdate($entry->start_time, '%H');
    $start_min = \block_mrbs\local\booking_time::userdate($entry->start_time, '%M');
    $end_time = $entry->end_time;
    $duration = $entry->end_time - $entry->start_time - cross_dst($entry->start_time, $entry->end_time);
    $type = $entry->type;
    $room_id = $entry->room_id;
    $courseid = empty($entry->courseid) ? 0 : (int)$entry->courseid;
    $facultyid = empty($entry->facultyid) ? 0 : (int)$entry->facultyid;
    $groupid = empty($entry->groupid) ? 0 : (int)$entry->groupid;
    //put this here so that a move can be coded into the get data
    if (!empty($room)) {
        $room_id = $room;
    }
    $entry_type = $entry->entry_type;
    $rep_id = $entry->repeat_id;

    if ($entry_type >= 1) {
        $repeat = $DB->get_record('block_mrbs_repeat', array('id' => $rep_id), '*', MUST_EXIST);
        $rep_type = $repeat->rep_type;

        if ($edit_type == "series") {
            if (!empty($repeat->courseid)) {
                $courseid = (int)$repeat->courseid;
            }
            if (!empty($repeat->facultyid)) {
                $facultyid = (int)$repeat->facultyid;
            }
            $groupid = empty($repeat->groupid) ? 0 : (int)$repeat->groupid;

            $start_day = (int)\block_mrbs\local\booking_time::userdate($repeat->start_time, '%d');
            $start_month = (int)\block_mrbs\local\booking_time::userdate($repeat->start_time, '%m');
            $start_year = (int)\block_mrbs\local\booking_time::userdate($repeat->start_time, '%Y');

            $rep_end_day = (int)\block_mrbs\local\booking_time::userdate($repeat->end_date, '%d');
            $rep_end_month = (int)\block_mrbs\local\booking_time::userdate($repeat->end_date, '%m');
            $rep_end_year = (int)\block_mrbs\local\booking_time::userdate($repeat->end_date, '%Y');

            switch ($rep_type) {
                case 2:
                case 6:
                    $rep_day[0] = $repeat->rep_opt[0] != "0";
                    $rep_day[1] = $repeat->rep_opt[1] != "0";
                    $rep_day[2] = $repeat->rep_opt[2] != "0";
                    $rep_day[3] = $repeat->rep_opt[3] != "0";
                    $rep_day[4] = $repeat->rep_opt[4] != "0";
                    $rep_day[5] = $repeat->rep_opt[5] != "0";
                    $rep_day[6] = $repeat->rep_opt[6] != "0";

                    if ($rep_type == 6) {
                        $rep_num_weeks = $repeat->rep_num_weeks;
                    }

                    break;

                default:
                    $rep_day = array(0, 0, 0, 0, 0, 0, 0);
            }
        } else {
            $rep_type = $repeat->rep_type;
            $rep_end_date = \block_mrbs\local\booking_time::userdate($repeat->end_date, '%A %d %B %Y');
            $rep_opt = $repeat->rep_opt;
        }
    }
} else { // It is a new booking. The data comes from whichever button the user clicked
    $edit_type = "series";
    $name = getUserName();
    $create_by = getUserName();
    $description = '';
    $start_day = $day;
    $start_month = $month;
    $start_year = $year;
    // Avoid notices for $hour and $minute if periods is enabled
    $start_hour = $hour;
    $start_min = $minute;
    $duration = ($enable_periods && \block_mrbs\local\settings_manager::has_valid_custom_time_range())
        ? \block_mrbs\local\settings_manager::get_period_interval_seconds()
        : 60 * 60;
    $type = "I";
    $room_id = $room;
    $courseid = 0;
    $facultyid = 0;
    $groupid = 0;
    $start_time = \block_mrbs\local\settings_manager::get_period_start_timestamp($start_year, $start_month, $start_day, $period);
    $end_time = $start_time;
    $rep_id = 0;
    $rep_type = 0;
    $rep_end_day = $day;
    $rep_end_month = $month;
    $rep_end_year = $year;
    $rep_day = array(0, 0, 0, 0, 0, 0, 0);
}

// These next 4 if statements handle the situation where
// this page has been accessed directly and no arguments have
// been passed to it.
// If we have not been provided with a room_id

if ($room_id == 0) {
    $dbroom = $DB->get_records('block_mrbs_room', null, 'room_name', 'id', 0, 1);
    if ($dbroom) {
        $dbroom = reset($dbroom);
        $room_id = $dbroom->id;
    }
}

$roomarea = $room_id ? $DB->get_field('block_mrbs_room', 'area_id', array('id' => $room_id), IGNORE_MISSING) : 0;
if ($roomarea) {
    $area = (int)$roomarea;
}
$prerequisites = block_mrbs_require_prerequisites($day, $month, $year, $area, $room_id, true, true);
$area = $prerequisites->areaid;
$room_id = $prerequisites->roomid;

// If we have not been provided with starting time
if (empty($start_hour) && $morningstarts < 10) {
    $start_hour = "0$morningstarts";
}

if (empty($start_hour)) {
    $start_hour = "$morningstarts";
}

if (empty($start_min)) {
    $start_min = "00";
}

// Remove "Undefined variable" notice
if (empty($rep_num_weeks)) {
    $rep_num_weeks = "";
}

if ($enable_periods && \block_mrbs\local\settings_manager::has_valid_custom_time_range()) {
    $start_min = \block_mrbs\local\settings_manager::get_period_index_from_timestamp($start_time);
}

$hierarchy = \block_mrbs\local\booking_options::get_course_hierarchy($courseid);
$programmeid = $hierarchy['programmeid'];
$batchid = $hierarchy['batchid'];
$semesterid = $hierarchy['semesterid'];
$reservationname = \block_mrbs\local\reservation::build_name($courseid, $facultyid, $groupid);
if ($reservationname === '') {
    $reservationname = $name;
}
$ajaxurl = new moodle_url('/blocks/mrbs/web/ajax.php');

$enable_periods ? toPeriodString($start_min, $duration, $dur_units) : toTimeString($duration, $dur_units);

$searchallowedunits = $enable_periods ? ['periods', 'days'] : ['minutes', 'hours', 'days', 'weeks'];
if (!$id && $searchduration > 0 && in_array($searchunits, $searchallowedunits, true)) {
    $duration = $searchduration;
    $dur_units = $searchunits;
    if (!$enable_periods) {
        $start_hour = $hour;
        $start_min = $minute;
    }
}

//now that we know all the data to fill the form with we start drawing it

$context = context_system::instance();
$PAGE->set_context($context);

$roomadmin = false;
if (!getWritable($create_by, getUserName())) {
    if (mrbsCanOnlyEditUnconfirmed()) {
        if ($room_id) {
            $dbroom = $DB->get_record('block_mrbs_room', array('id' => $room_id));
            if ($dbroom->room_admin_email == $USER->email) {
                $roomadmin = true;
            }
        }
    }

    if (!$roomadmin) {
        showAccessDenied($day, $month, $year, $area);
        exit;
    }
}

$PAGE->requires->js('/blocks/mrbs/web/updatefreerooms.js', true);
$PAGE->requires->js_call_amd('block_mrbs/booking_selectors', 'init', [[
    'formName' => 'main',
    'endpoint' => $ajaxurl->out(false),
    'selectedProgramme' => (int)$programmeid,
    'selectedBatch' => (int)$batchid,
    'selectedSemester' => (int)$semesterid,
    'selectedCourse' => (int)$courseid,
    'selectedFaculty' => (int)$facultyid,
    'selectedGroup' => (int)$groupid,
    'previewId' => 'mrbs-reservation-preview',
    'messageId' => 'mrbs-selector-message',
    'programmePlaceholder' => get_string('selectprogramme', 'block_mrbs'),
    'batchPlaceholder' => get_string('selectbatch', 'block_mrbs'),
    'semesterPlaceholder' => get_string('selectsemester', 'block_mrbs'),
    'coursePlaceholder' => get_string('selectcourse', 'block_mrbs'),
    'facultyPlaceholder' => get_string('selectfaculty', 'block_mrbs'),
    'groupPlaceholder' => get_string('selectgroup', 'block_mrbs'),
    'allGroupsLabel' => get_string('allgroups', 'block_mrbs'),
    'emptyReservationText' => get_string('reservationnotselected', 'block_mrbs'),
    'failureMessage' => get_string('searchfailed', 'block_mrbs'),
]]);
$PAGE->requires->js_call_amd('block_mrbs/capacity_warning', 'init', [[
    'formName' => 'main',
    'endpoint' => $ajaxurl->out(false),
    'title' => get_string('capacitywarningtitle', 'block_mrbs'),
    'message' => get_string('capacitywarningmessage', 'block_mrbs'),
    'yesLabel' => get_string('yes', 'moodle'),
    'noLabel' => get_string('no', 'moodle'),
    'redirectUrl' => (new moodle_url('/blocks/mrbs/web/day.php', [
        'day' => $day,
        'month' => $month,
        'year' => $year,
        'area' => $area,
    ]))->out(false),
]]); 

print_header_mrbs($day, $month, $year, $area);

?>
<SCRIPT LANGUAGE="JavaScript">

    <?php
    echo 'var currentroom='.$room_id.';';
    if (mrbsHasAdminCapability('block/mrbs:forcebook')) {
        echo 'var canforcebook=true;';
    } else {
        echo 'var canforcebook=false;';
    }
    echo 'window.mrbsUpdateRoomsFailure = '.json_encode(get_string('roomsearchfailed', 'block_mrbs')).';';
    ?>
    // do a little form verifying
    function validate_and_submit() {
        var form = document.forms["main"];
        var selectedRepeat;
        var repType = 0;
        var n = 0;
        var i;
        var h;
        var m;
        var repeatDaySelected = false;

        try {
        // null strings and spaces only strings not allowed
        if (/(^$)|(^\s+$)/.test(form.description.value)) {
            alert("<?php echo get_string('you_have_not_entered', 'block_mrbs').'\n'.get_string('fulldescription', 'block_mrbs') ?>");
            return false;
        }
        <?php if( !$enable_periods ) { ?>

        h = parseInt(form.hour.value, 10);
        m = parseInt(form.minute.value, 10);

        if (isNaN(h) || isNaN(m) || h>23 || m>59) {
            alert("<?php echo get_string('you_have_not_entered', 'block_mrbs').'\n'.get_string('valid_time_of_day', 'block_mrbs') ?>");
            return false;
        }
        <?php } ?>

        // check form element exist before trying to access it
        selectedRepeat = form.querySelector('input[name="rep_type"]:checked');
        if (selectedRepeat) {
            repType = parseInt(selectedRepeat.value, 10);
        }
        if (form.rep_num_weeks) {
            n = parseInt(form.rep_num_weeks.value, 10);
        }
        if (repType === 6 && (!n || n < 2)) {
            alert("<?php echo get_string('you_have_not_entered', 'block_mrbs').'\n'.get_string('useful_n-weekly_value', 'block_mrbs') ?>");
            return false;
        }
        if (repType === 6) {
            for (i = 0; i < 7; i++) {
                if (form.elements['rep_day[' + i + ']'] && form.elements['rep_day[' + i + ']'].checked) {
                    repeatDaySelected = true;
                    break;
                }
            }
            if (!repeatDaySelected) {
                alert("<?php echo get_string('rep_no_days_selected', 'block_mrbs') ?>");
                return false;
            }
        }

        if (!form.programmeid || !form.programmeid.value) {
            alert("<?php echo get_string('you_have_not_selected', 'block_mrbs').'\n'.get_string('programme', 'block_mrbs') ?>");
            return false;
        }
        if (!form.batchid || !form.batchid.value) {
            alert("<?php echo get_string('you_have_not_selected', 'block_mrbs').'\n'.get_string('batch', 'block_mrbs') ?>");
            return false;
        }
        if (!form.semesterid || !form.semesterid.value) {
            alert("<?php echo get_string('you_have_not_selected', 'block_mrbs').'\n'.get_string('semester', 'block_mrbs') ?>");
            return false;
        }
        if (!form.courseid || !form.courseid.value) {
            alert("<?php echo get_string('invalidcourse', 'block_mrbs') ?>");
            return false;
        }
        if (!form.facultyid || !form.facultyid.value) {
            alert("<?php echo get_string('invalidfaculty', 'block_mrbs') ?>");
            return false;
        }
        if (!form.groupid || form.groupid.value === '') {
            alert("<?php echo get_string('invalidgroup', 'block_mrbs') ?>");
            return false;
        }

        // check that a room(s) has been selected
        // this is needed as edit_entry_handler does not check that a room(s)
        // has been chosen
        if (form.elements['rooms[]'].selectedIndex == -1 || form.elements['rooms[]'].value === '') {
            alert("<?php echo get_string('you_have_not_selected', 'block_mrbs').'\n'.get_string('valid_room', 'block_mrbs') ?>");
            return false;
        }

        // Form submit can take some times, especially if mails are enabled and
        // there are more than one recipient. To avoid users doing weird things
        // like clicking more than one time on submit button, we hide it as soon
        // it is clicked.
        form.save_button.disabled = true;

        if (window.MRBSCapacityWarning && typeof window.MRBSCapacityWarning.check === 'function') {
            window.MRBSCapacityWarning.check().then(function(allow) {
                if (allow) {
                    form.submit();
                } else {
                    form.save_button.disabled = false;
                }
            }).catch(function(error) {
                if (window.console) {
                    window.console.error(error);
                }
                form.submit();
            });
            return false;
        }

        // would be nice to also check date to not allow Feb 31, etc...
        form.submit();

        return true;
        } catch (e) {
            if (window.console) {
                window.console.error(e);
            }
            alert("<?php echo get_string('bookingformerror', 'block_mrbs') ?>");
            return false;
        }
    }

    function OnAllDayClick() { // Executed when the user clicks on the all_day checkbox.
        allday = document.getElementById('all_day');
        form = document.forms["main"];
        if (allday.checked) { // If checking the box...
            <?php if( !$enable_periods ) { ?>
            form.hour.value = "00";
            form.minute.value = "00";
            <?php } ?>
            if (form.dur_units.value != "days") { // Don't change it if the user already did.
                form.duration.value = "1";
                form.dur_units.value = "days";
            }
        }
        updateFreeRooms()
    }
</SCRIPT>

<H2><?php echo $id ? ($edit_type == "series" ? get_string('editseries', 'block_mrbs') : get_string('editentry', 'block_mrbs')) : get_string('addentry', 'block_mrbs'); ?></H2>

<form name="main" action="edit_entry_handler.php" method="post" class="card my-3">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="area" value="<?php echo (int)$area; ?>">

    <div class="card-body">

        <?php if ($edit_type != 'series' && $rep_id) { ?>
            <div class="alert alert-info">
                <?php
                $editseriesurl = new moodle_url('/blocks/mrbs/web/edit_entry.php', [
                    'id' => $id, 'edit_type' => 'series',
                ]);
                echo get_string('editingserieswarning', 'block_mrbs');
                echo html_writer::link($editseriesurl, get_string('editseries', 'block_mrbs'));
                ?>
            </div>
        <?php } ?>

        <div class="form-row">
            <div class="form-group col-12 col-md-6 col-lg-4">
                <label class="font-weight-bold" for="mrbs-programmeid"><?php echo get_string('programme', 'block_mrbs'); ?></label>
                <select class="custom-select w-100" id="mrbs-programmeid" name="programmeid" required></select>
            </div>
            <div class="form-group col-12 col-md-6 col-lg-4">
                <label class="font-weight-bold" for="mrbs-batchid"><?php echo get_string('batch', 'block_mrbs'); ?></label>
                <select class="custom-select w-100" id="mrbs-batchid" name="batchid" required disabled></select>
            </div>
            <div class="form-group col-12 col-md-6 col-lg-4">
                <label class="font-weight-bold" for="mrbs-semesterid"><?php echo get_string('semester', 'block_mrbs'); ?></label>
                <select class="custom-select w-100" id="mrbs-semesterid" name="semesterid" required disabled></select>
            </div>
            <div class="form-group col-12 col-md-6 col-lg-4">
                <label class="font-weight-bold" for="mrbs-courseid"><?php echo get_string('course', 'block_mrbs'); ?></label>
                <select class="custom-select w-100" id="mrbs-courseid" name="courseid" required disabled></select>
            </div>
            <div class="form-group col-12 col-md-6 col-lg-4">
                <label class="font-weight-bold" for="mrbs-facultyid"><?php echo get_string('faculty', 'block_mrbs'); ?></label>
                <select class="custom-select w-100" id="mrbs-facultyid" name="facultyid" required disabled></select>
            </div>
            <div class="form-group col-12 col-md-6 col-lg-4">
                <label class="font-weight-bold" for="mrbs-groupid"><?php echo get_string('group', 'block_mrbs'); ?></label>
                <select class="custom-select w-100" id="mrbs-groupid" name="groupid" required disabled></select>
            </div>
        </div>
        <div class="form-group">
            <div class="font-weight-bold mb-1"><B><?php echo get_string('namebooker', 'block_mrbs') ?></B></div>
            <div>
                <input type="hidden" name="name" value="<?php echo s($reservationname); ?>">
                <span id="mrbs-reservation-preview"><?php echo s($reservationname ?: get_string('reservationnotselected', 'block_mrbs')); ?></span>
                <div id="mrbs-selector-message" class="mrbs-autocomplete-message"></div>
            </div>
        </div>

        <div class="form-group">
            <label class="font-weight-bold" for="mrbs-description"><?php echo get_string('fulldescription', 'block_mrbs'); ?></label>
            <div><textarea class="form-control" id="mrbs-description" name="description" rows="4" required><?php echo
                    htmlspecialchars($description); ?></textarea></div>
        </div>

        <hr>
        <div class="form-group">
            <div class="font-weight-bold mb-1"><B><?php echo get_string('date') ?></B></div>
            <div>
                <?php genDateSelector("", $start_day, $start_month, $start_year, true) ?>
                <SCRIPT LANGUAGE="JavaScript">ChangeOptionDays(document.main, '');</SCRIPT>
            </div>
        </div>

        <?php if (!$enable_periods) { ?>
            <div class="form-group">
                <div class="font-weight-bold mb-1"><B><?php echo get_string('time') ?></B></div>
                <div><INPUT NAME="hour" class="form-control d-inline-block w-auto mw-100" SIZE=2 VALUE="<?php if (!$twentyfourhour_format && ($start_hour > 12)) {
                        echo($start_hour - 12);
                    } else {
                        echo $start_hour;
                    } ?>" MAXLENGTH=2 onChange="updateFreeRooms()">:<INPUT NAME="minute" class="form-control d-inline-block w-auto mw-100" SIZE=2 VALUE="<?php echo $start_min; ?>"
                                                                           MAXLENGTH=2 onChange="updateFreeRooms()">
                    <?php
                    if (!$twentyfourhour_format) {
                        $checked = ($start_hour < 12) ? "checked" : "";
                        echo "<INPUT NAME=\"ampm\" type=\"radio\" value=\"am\" $checked>".\block_mrbs\local\booking_time::userdate(\block_mrbs\local\booking_time::mktime(1, 0, 0, 1, 1, 2000), "%p");
                        $checked = ($start_hour >= 12) ? "checked" : "";
                        echo "<INPUT NAME=\"ampm\" type=\"radio\" value=\"pm\" $checked>".\block_mrbs\local\booking_time::userdate(\block_mrbs\local\booking_time::mktime(13, 0, 0, 1, 1, 2000), "%p");
                    }
                    ?>
                </div>
            </div>
        <?php } else { ?>
            <div class="form-group">
                <div class="font-weight-bold mb-1"><B><?php echo get_string('period', 'block_mrbs') ?></B></div>
                <div>
                    <select class="custom-select w-auto mw-100" name="period" onChange="updateFreeRooms()">
                        <?php
                        foreach ($periods as $p_num => $p_val) {
                            echo "<OPTION VALUE=$p_num";
                            if ((isset($period) && $period == $p_num) || $p_num == $start_min) {
                                echo " SELECTED";
                            }
                            echo ">$p_val";
                        }
                        ?>
                    </select>

                </div>
            </div>

        <?php } ?>
        <div class="form-group">
            <div class="font-weight-bold mb-1"><B><?php echo get_string('duration', 'block_mrbs'); ?></B></div>
            <div><INPUT NAME="duration" class="form-control d-inline-block w-auto mw-100" SIZE=7 VALUE="<?php echo $duration; ?>" onChange="updateFreeRooms()">
                <select class="custom-select w-auto mw-100" name="dur_units" onChange="updateFreeRooms()">
                    <?php

                    if ($enable_periods) {
                        $units = array("periods", "days");
                    } else {
                        $units = array("minutes", "hours", "days", "weeks");
                    }

                    foreach ($units as $unit) {
                        echo '<option value="' . $unit . '"';
                        if ($dur_units == $unit) {
                            echo ' selected';
                        }
                        echo '>' . get_string($unit, 'block_mrbs') . '</option>';
                    }

                    ?>

                </select>

                <div class="form-check mt-2">
                    <input class="form-check-input" name="all_day" type="checkbox" value="yes" id="all_day"
                        <?php echo $all_day ? 'checked' : ''; ?> onclick="OnAllDayClick()">
                    <label class="form-check-label" for="all_day"><?php echo get_string('all_day', 'block_mrbs'); ?></label>
                </div>
            </div>
        </div>


        <hr>
        <?php
        $area_id = $DB->get_field('block_mrbs_room', 'area_id', ['id' => $room_id], MUST_EXIST);
        $areas = $DB->get_records('block_mrbs_area', null, 'area_name');
        if (count($areas) > 1) {
            $areaoptions = [];
            foreach ($areas as $dbarea) {
                $areaoptions[$dbarea->id] = s($dbarea->area_name);
            }
            $areaoptions['IT'] = get_string('computerrooms', 'block_mrbs');
            ?>
            <div class="form-group">
                <label class="font-weight-bold" for="mrbs-areas"><?php echo get_string('areas', 'block_mrbs'); ?></label>
                <?php echo html_writer::select($areaoptions, 'areas', $area_id, false, [
                    'class' => 'custom-select w-auto mw-100',
                    'id' => 'mrbs-areas',
                    'onchange' => 'updateFreeRooms()',
                ]); ?>
            </div>
        <?php } ?>

        <div class="form-group">
            <label class="font-weight-bold" for="mrbs-rooms"><?php echo get_string('rooms', 'block_mrbs'); ?></label>
            <select class="custom-select w-100" id="mrbs-rooms" name="rooms[]" multiple size="4" aria-describedby="mrbs-rooms-help">
                <?php
                $rooms = $DB->get_records('block_mrbs_room', ['area_id' => $area_id], 'room_name');
                foreach ($rooms as $dbroom) {
                    if (!allowed_to_book($USER, $dbroom)) {
                        continue;
                    }
                    $selected = $dbroom->id == $room_id ? ' selected' : '';
                    $label = s($dbroom->room_name) . ' (' . s($dbroom->description)
                        . ' ' . get_string('capacity', 'block_mrbs') . ': ' . (int)$dbroom->capacity . ')';
                    $attributes = ['value' => $dbroom->id];
                    if ($selected !== '') {
                        $attributes['selected'] = 'selected';
                    }
                    echo html_writer::tag('option', $label, $attributes);
                }
                ?>
            </select>
            <div class="form-text text-muted" id="mrbs-rooms-help"><?php echo get_string('ctrl_click', 'block_mrbs'); ?></div>
            <div class="form-check mt-2">
                <input class="form-check-input" name="nooccupied" id="nooccupied" type="checkbox" checked onclick="updateFreeRooms()">
                <label class="form-check-label" for="nooccupied"><?php echo get_string('dontshowoccupied', 'block_mrbs'); ?></label>
            </div>
        </div>

        <div class="form-group">
            <div class="font-weight-bold mb-1"><B><?php echo get_string('type', 'block_mrbs') ?></B></div>
            <div><select class="custom-select w-auto mw-100" name="type">
                    <?php
                    //If this is an imported booking, forcably mark it as edited so that changes are not overridden on next import
                    if (($type == 'K') or ($type == 'L')) {
                        echo '<OPTION VALUE=L SELECTED >'.$typel['L'].'</option>\n';
                    } else {
                        $unconfirmed = false;
                        $unconfirmedonly = false;
                        if (mrbsCanOnlyEditUnconfirmed()) {
                            $unconfirmed = true;
                        }
                        if (authGetUserLevel(getUserName()) < 2 && $unconfirmed) {
                            if ($USER->email != $rooms[$room_id]->room_admin_email) {
                                $type = 'U';
                                $unconfirmedonly = true;
                            }
                        }
                        if (!$unconfirmedonly) {
                            for ($c = "A"; $c <= "J"; $c++) {
                                if (!empty($typel[$c])) {
                                    echo "<OPTION VALUE=$c".($type == $c ? " SELECTED" : "").">$typel[$c]\n";
                                }
                            }
                        }
                        if ($unconfirmed) {
                            echo '<OPTION VALUE="U" '.($type == 'U' ? 'SELECTED="SELECTED"' : '').' >'.$typel['U'].'</OPTION>\n';
                        }
                    }
                    ?></select></div>
        </div>
        <?php if (mrbsHasAdminCapability('block/mrbs:forcebook')) { ?>
            <div class="form-check mb-3">
                <input class="form-check-input" id="mrbsforcebook" type="checkbox" name="forcebook" value="TRUE"
                    <?php echo $force ? 'checked' : ''; ?>
                    onclick="document.getElementById('nooccupied').checked = !this.checked; updateFreeRooms();">
                <label class="form-check-label" for="mrbsforcebook"><?php echo get_string($id ? 'forcebookkeepentry' : 'forciblybook2', 'block_mrbs'); ?></label>
            </div>
        <?php } ?>
        <hr>
        <?php if ($edit_type == "series") { ?>

            <div class="form-group">
                <div class="font-weight-bold mb-1"><B><?php echo get_string('rep_type', 'block_mrbs') ?></B></div>
                <div>
                    <?php

                    for ($i = 0; $i < 7; $i++) //manually setting this to 7 since that is how many repetition types there are -arb quick and dirty hack
                    {
                        echo '<span class="form-check form-check-inline">';
                        echo "<INPUT class=\"form-check-input\" ID=\"radiorepeat".$i."\" NAME=\"rep_type\" TYPE=\"RADIO\" VALUE=\"".$i."\"";

                        if ($i == $rep_type) {
                            echo " CHECKED";
                        }

                        echo '><label class="form-check-label" for="radiorepeat'.$i.'">'.get_string('rep_type_'.$i, 'block_mrbs')."</label></span>\n";
                    }

                    ?>
                </div>
            </div>

            <div class="form-group" id="mrbs-repeat-end">
                <div class="font-weight-bold mb-1"><B><?php echo get_string('rep_end_date', 'block_mrbs') ?></B></div>
                <div><?php genDateSelector("rep_end_", $rep_end_day, $rep_end_month, $rep_end_year) ?></div>
            </div>

            <div class="form-group" id="mrbs-repeat-days">
                <div class="font-weight-bold mb-1">
                    <B><?php echo get_string('rep_rep_day', 'block_mrbs') ?></B> <?php echo get_string('rep_for_weekly', 'block_mrbs') ?>
                </div>
                <div>
                    <?php
                    // Display day name checkboxes according to language and preferred weekday start.
                    for ($i = 0; $i < 7; $i++) {
                        $wday = ($i + $weekstarts) % 7;
                        echo '<span class="form-check form-check-inline">';
                        echo "<INPUT class=\"form-check-input\" ID=\"chkrepeatday".$i."\" NAME=\"rep_day[$wday]\" TYPE=CHECKBOX";
                        if ($rep_day[$wday]) {
                            echo " CHECKED";
                        }
                        echo '><label class="form-check-label" for="chkrepeatday'.$i.'">'.day_name($wday)."</label></span>\n";
                    }
                    ?>
                </div>
            </div>

            <?php
        } else {
            $key = "rep_type_".(isset($rep_type) ? $rep_type : "0");

            echo "<div class=\"form-group\"><div class=\"font-weight-bold mb-1\"><b>".get_string('rep_type', 'block_mrbs')."</b></div><div>".get_string($key, 'block_mrbs')."</div></div>\n";

            if (isset($rep_type) && ($rep_type != 0)) {
                $opt = "";
                if ($rep_type == 2) {
                    // Display day names according to language and preferred weekday start.
                    for ($i = 0; $i < 7; $i++) {
                        $wday = ($i + $weekstarts) % 7;
                        if ($rep_opt[$wday]) {
                            $opt .= day_name($wday)." ";
                        }
                    }
                }
                if ($opt) {
                    echo "<div class=\"form-group\"><div class=\"font-weight-bold mb-1\"><b>".get_string('rep_rep_day', 'block_mrbs')."</b></div><div>$opt</div></div>\n";
                }

                echo "<div class=\"form-group\"><div class=\"font-weight-bold mb-1\"><b>".get_string('rep_end_date', 'block_mrbs')."</b></div><div>$rep_end_date</div></div>\n";
            }
        }
        if ($edit_type === "series") {
            ?>

            <div class="form-group" id="mrbs-repeat-weeks">
                <div class="font-weight-bold mb-1">
                    <B><?php echo get_string('rep_num_weeks', 'block_mrbs') ?></B> <?php echo get_string('rep_for_nweekly', 'block_mrbs') ?>
                </div>
                <div><INPUT TYPE=TEXT NAME="rep_num_weeks" class="form-control d-inline-block w-auto mw-100" VALUE="<?php echo $rep_num_weeks ?>"></div>
            </div>
        <?php } ?>

        <?php if ($id != 0) { ?>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" checked name="roomchange" id="mrbsroomchange">
                <label class="form-check-label" for="mrbsroomchange"><?php print_string('roomchange', 'block_mrbs'); ?></label>
            </div>
        <?php } ?>

        <div class="border-top pt-3 d-flex flex-wrap align-items-center">
                <SCRIPT LANGUAGE="JavaScript">
                    document.writeln('<INPUT TYPE="button" class="btn btn-primary mr-3" NAME="save_button" VALUE="<?php echo get_string('savechanges')?>" ONCLICK="validate_and_submit()">');
                    window.setTimeout(function () {
                        if (document.main.all_day.checked) {
                            OnAllDayClick();
                        } else {
                            updateFreeRooms();
                        }
                    }, 0);
                </SCRIPT>
                <NOSCRIPT>
                    <INPUT TYPE="submit" class="btn btn-primary mr-3" VALUE="<?php echo get_string('savechanges') ?>">
                </NOSCRIPT>

                <?php
                if ($id) {
                    $deleteattributes = [
                        'class' => 'btn btn-outline-danger mr-2',
                        'onclick' => 'return confirm(' . json_encode(get_string('confirmdel', 'block_mrbs')) . ');',
                    ];
                    $delurl = new moodle_url('/blocks/mrbs/web/del_entry.php', [
                        'id' => $id, 'series' => 0, 'sesskey' => sesskey(),
                    ]);
                    echo html_writer::link($delurl, get_string('deleteentry', 'block_mrbs'), $deleteattributes);
                    if ($rep_id) {
                        $delurl->params(['series' => 1, 'day' => $day, 'month' => $month, 'year' => $year]);
                        echo html_writer::link($delurl, get_string('deleteseries', 'block_mrbs'), $deleteattributes);
                    }
                }
                ?>
        </div>
    </div>

    <!--<INPUT TYPE=HIDDEN NAME="returl"    VALUE="<?php echo $HTTP_REFERER ?>">-->
    <INPUT TYPE=HIDDEN NAME="create_by" VALUE="<?php echo $create_by ?>">
    <INPUT TYPE=HIDDEN NAME="rep_id" VALUE="<?php echo $rep_id ?>">
    <INPUT TYPE=HIDDEN NAME="edit_type" VALUE="<?php echo $edit_type ?>">
    <?php if (isset($id)) {
        echo "<INPUT TYPE=HIDDEN NAME=\"id\"        VALUE=\"$id\">\n";
    }
    ?>
</form>
<script>
(function() {
    var form = document.forms.main;
    var radios = form.querySelectorAll('input[name="rep_type"]');
    function updateRepeatFields() {
        var selected = form.querySelector('input[name="rep_type"]:checked');
        if (!selected) {
            return;
        }
        var type = Number(selected.value);
        ['end', 'days', 'weeks'].forEach(function(group) {
            var container = document.getElementById('mrbs-repeat-' + group);
            if (!container) {
                return;
            }
            var visible = group === 'end' ? type !== 0 : type === 6;
            container.hidden = !visible;
            container.querySelectorAll('input, select').forEach(function(control) {
                control.disabled = !visible;
            });
        });
    }
    radios.forEach(function(radio) {
        radio.addEventListener('change', updateRepeatFields);
    });
    updateRepeatFields();
})();
</script>

<?php include "trailer.php" ?>
