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

/*
 *
 * Assigns MRBS access levels based on the user status
 * within your Moodle installation.
 *
 *
 * MRBS Levels
 * 0 - View only
 * 1 - View and make bookings
 * 2 - Full administration - add rooms and bookings
 *
 * Moodle Integration
 * Defines one of the above levels to admins, teachers
 * students, creators, and guests seperately.
 *
 * Used in conjunction with session_moodle.inc
 *
 * To use this authentication scheme set the following
 * things in config.inc.php:
 *
 *      $auth["type"]    = "moodle";
 *      $auth["session"] = "moodle";
 *
 */

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php'); //for Moodle integration

function authValidateUser($user, $pass) {
    return 1;
}

function authGetUserLevel($user) {
    if (\block_mrbs\local\timetable_access::is_admin()) {
        return 2;
    }
    return \block_mrbs\local\timetable_access::can_edit() ? 1 : 0;
}

function mrbsRequireCalendar($day = 0, $month = 0, $year = 0) {
    if (\block_mrbs\local\timetable_access::personal_only()) {
        redirect(new moodle_url('/blocks/mrbs/web/timetable.php', compact('day', 'month', 'year')));
    }
    \block_mrbs\local\timetable_access::require_calendar();
}

function mrbsHasAdminCapability($capability) {
    return \block_mrbs\local\timetable_access::is_admin()
        || has_capability($capability, context_system::instance());
}

function mrbsCanOnlyEditUnconfirmed() {
    return !\block_mrbs\local\timetable_access::is_admin()
        && has_capability('block/mrbs:editunconfirmed', context_system::instance(), null, false);
}
