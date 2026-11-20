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
global $PAGE, $DB, $OUTPUT;
include "config.inc.php";
include "functions.php";
require_once('mrbs_auth.php');

// Passed in when starting to edit
$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$room = optional_param('room', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);

// Editing general
$change_done = optional_param('change_done', 0, PARAM_BOOL);

// Editing room
$room_name = optional_param('room_name', '', PARAM_TEXT);
$description = optional_param('description', '', PARAM_TEXT);
$capacity = optional_param('capacity', 0, PARAM_INT);
$room_admin_email = optional_param('room_admin_email', '', PARAM_TEXT);
$booking_users = optional_param('booking_users', '', PARAM_TEXT);
$change_room = optional_param('change_room', false, PARAM_TEXT);

// Editing area
$area_name = optional_param('area_name', '', PARAM_TEXT);
$area_admin_email = optional_param('area_admin_email', '', PARAM_TEXT);
$change_area = optional_param('change_area', false, PARAM_TEXT);

// $room_admin_email = optional_param('room_admin_email', 0, PARAM_BOOL); //not sure if this is from config or passed from URL -ab.

//If we dont know the right date then make it up
if (($day == 0) or ($month == 0) or ($year == 0)) {
    $day = \block_mrbs\local\booking_time::date("d");
    $month = \block_mrbs\local\booking_time::date("m");
    $year = \block_mrbs\local\booking_time::date("Y");
}

$thisurl = new moodle_url('/blocks/mrbs/web/edit_area_room.php', array(
    'day' => $day, 'month' => $month, 'year' => $year, 'sesskey' => sesskey()
));
if ($room) {
    $thisurl->param('room', $room);
}
if ($area) {
    $thisurl->param('area', $area);
}

$PAGE->set_url($thisurl);
require_login();

if (!getAuthorised(2)) {
    showAccessDenied($day, $month, $year, $area);
    exit();
}
require_sesskey();

// Done changing area or room information?
if (($change_done)) {
    if (!empty($room)) // Get the area the room is in
    {
        $area = $DB->get_field('block_mrbs_room', 'area_id', array('id' => $room));
    }
    $adminurl = new moodle_url('/blocks/mrbs/web/admin.php', array(
        'day' => $day, 'month' => $month, 'year' => $year, 'area' => $area
    ));
    redirect($adminurl);
    exit();
}

print_header_mrbs($day, $month, $year, isset($area) ? $area : "");

echo $OUTPUT->heading(get_string('editroomarea', 'block_mrbs'), 2);

if ($room > 0) {
    $valid_email = true;
    if (!empty($room_admin_email)) {
        $emails = explode(',', $room_admin_email);
        foreach ($emails as $email) {
            // if no email address is entered, this is OK, even if isValidInetAddress
            // does not return TRUE
            if (!get_user_by_email(trim($email))) {
                $valid_email = false;
                echo $OUTPUT->notification(get_string('no_user_with_email', 'block_mrbs', s($email)), 'notifyproblem');
            }
        }
    }
    $valid_email2 = true;
    if (!empty($booking_users)) {
        $booking_emails = explode(',', $booking_users);
        foreach ($booking_emails as $email) {
            if (!get_user_by_email(trim($email))) {
                $valid_email2 = false;
                echo $OUTPUT->notification(get_string('no_user_with_email', 'block_mrbs', s($email)), 'notifyproblem');
            }
        }
    }

    $roomnameerror = '';
    if ($change_room) {
        $room_name = \block_mrbs\local\area_room_validator::clean_name($room_name, 25);
        $roomarea = $DB->get_field('block_mrbs_room', 'area_id', array('id' => $room), MUST_EXIST);
        $roomnameerror = \block_mrbs\local\area_room_validator::validate_room_name($room_name, $roomarea, $room);
        if ($roomnameerror) {
            echo $OUTPUT->notification($roomnameerror, 'notifyproblem');
        }
    }

    if ($change_room && $valid_email && $valid_email2 && !$roomnameerror) {
        $updroom = new stdClass;
        $updroom->id = $room;
        $updroom->room_name = $room_name;
        $updroom->description = $description;
        $updroom->capacity = $capacity;
        $updroom->room_admin_email = $room_admin_email;
        $updroom->booking_users = $booking_users;

        $DB->update_record('block_mrbs_room', $updroom);
    }

    $dbroom = $DB->get_record('block_mrbs_room', array('id' => $room), '*', MUST_EXIST);
    ?>
    <form class="card my-3" action="<?php echo $thisurl->out_omit_querystring(); ?>" method="post">
        <div class="card-body">
            <h3 class="h5"><?php echo get_string('editroom', 'block_mrbs'); ?></h3>
            <input type="hidden" name="room" value="<?php echo (int)$dbroom->id; ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

            <div class="form-row">
                <div class="form-group col-12 col-md-8">
                    <label for="mrbs-room-name"><?php echo get_string('name'); ?></label>
                    <input class="form-control" id="mrbs-room-name" name="room_name" maxlength="25" required
                        pattern=".*\S.*" value="<?php echo s($dbroom->room_name); ?>">
                </div>
                <div class="form-group col-12 col-md-4">
                    <label for="mrbs-room-capacity"><?php echo get_string('capacity', 'block_mrbs'); ?></label>
                    <input class="form-control" id="mrbs-room-capacity" name="capacity" type="number" min="0"
                        value="<?php echo (int)$dbroom->capacity; ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="mrbs-room-description"><?php echo get_string('description'); ?></label>
                <input class="form-control" id="mrbs-room-description" name="description"
                    value="<?php echo s($dbroom->description); ?>">
            </div>
            <div class="form-group">
                <label for="mrbs-room-email"><?php echo get_string('room_admin_email', 'block_mrbs'); ?></label>
                <input class="form-control<?php echo $valid_email ? '' : ' is-invalid'; ?>" id="mrbs-room-email"
                    name="room_admin_email" maxlength="75" value="<?php echo s($dbroom->room_admin_email); ?>">
                <?php if (!$valid_email) { ?>
                    <div class="invalid-feedback"><?php echo get_string('emailmustbereal'); ?></div>
                <?php } ?>
            </div>
            <div class="form-group">
                <label for="mrbs-booking-users"><?php echo get_string('booking_users', 'block_mrbs'); ?></label>
                <?php echo $OUTPUT->help_icon('booking_users', 'block_mrbs'); ?>
                <textarea class="form-control<?php echo $valid_email2 ? '' : ' is-invalid'; ?>" id="mrbs-booking-users"
                    name="booking_users" rows="3"><?php echo s($dbroom->booking_users); ?></textarea>
                <?php if (!$valid_email2) { ?>
                    <div class="invalid-feedback"><?php echo get_string('emailmustbereal'); ?></div>
                <?php } ?>
            </div>

            <button class="btn btn-primary mr-2" type="submit" name="change_room" value="1">
                <?php echo get_string('savechanges'); ?>
            </button>
            <button class="btn btn-outline-secondary" type="submit" name="change_done" value="1" formnovalidate>
                <?php echo get_string('backadmin', 'block_mrbs'); ?>
            </button>
        </div>
    </form>
    <?php
}

if ($area) {
    $emails = explode(',', $area_admin_email);
    $valid_email = true;
    foreach ($emails as $email) {
        // if no email address is entered, this is OK, even if isValidInetAddress
        // does not return TRUE
        $email = trim($email);
        if (!get_user_by_email($email) && ('' != $area_admin_email)) {
            $valid_email = false;
            echo $OUTPUT->notification(get_string('no_user_with_email', 'block_mrbs', s($email)), 'notifyproblem');
        }
    }

    $areanameerror = '';
    if ($change_area) {
        $area_name = \block_mrbs\local\area_room_validator::clean_name($area_name, 30);
        $areanameerror = \block_mrbs\local\area_room_validator::validate_area_name($area_name, $area);
        if ($areanameerror) {
            echo $OUTPUT->notification($areanameerror, 'notifyproblem');
        }
    }

    if ($change_area && $valid_email && !$areanameerror) {
        $updarea = new stdClass;
        $updarea->id = $area;
        $updarea->area_name = $area_name;
        $updarea->area_admin_email = $area_admin_email;
        $DB->update_record('block_mrbs_area', $updarea);
    }

    $dbarea = $DB->get_record('block_mrbs_area', array('id' => $area), '*', MUST_EXIST);

    ?>
    <form class="card my-3" action="<?php echo $thisurl->out_omit_querystring(); ?>" method="post">
        <div class="card-body">
            <h3 class="h5"><?php echo get_string('editarea', 'block_mrbs'); ?></h3>
            <input type="hidden" name="area" value="<?php echo (int)$dbarea->id; ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

            <div class="form-group">
                <label for="mrbs-area-name"><?php echo get_string('name'); ?></label>
                <input class="form-control" id="mrbs-area-name" name="area_name" maxlength="30" required
                    pattern=".*\S.*" value="<?php echo s($dbarea->area_name); ?>">
            </div>
            <div class="form-group">
                <label for="mrbs-area-email"><?php echo get_string('area_admin_email', 'block_mrbs'); ?></label>
                <input class="form-control<?php echo $valid_email ? '' : ' is-invalid'; ?>" id="mrbs-area-email"
                    name="area_admin_email" maxlength="75" value="<?php echo s($dbarea->area_admin_email); ?>">
                <?php if (!$valid_email) { ?>
                    <div class="invalid-feedback"><?php echo get_string('emailmustbereal'); ?></div>
                <?php } ?>
            </div>

            <button class="btn btn-primary mr-2" type="submit" name="change_area" value="1">
                <?php echo get_string('savechanges'); ?>
            </button>
            <button class="btn btn-outline-secondary" type="submit" name="change_done" value="1" formnovalidate>
                <?php echo get_string('backadmin', 'block_mrbs'); ?>
            </button>
        </div>
    </form>
    <?php
}

include "trailer.php";
