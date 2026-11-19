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

// include the authentification wrappers
include "auth_$auth[type].php";
if (isset($auth['session'])) {
    include "session_$auth[session].php";
}

/* getAuthorised($user, $pass, $level)
 *
 * Check to see if the user name/password is valid
 *
 * $user  - The user name
 * $pass  - The users password
 * $level - The access level required
 *
 * Returns:
 *   0        - The user does not have the required access
 *   non-zero - The user has the required access
 */
function getAuthorised($level) {
    $user = getUserName();
    if (isset($user) == false) {
        authGet();
        return 0;
    }

    return authGetUserLevel($user) >= $level;
}

/* getWritable($creator, $user)
 *
 * Determines if a user is able to modify an entry
 *
 * $creator - The creator of the entry
 * $user    - Who wants to modify it
 *
 * Returns:
 *   0        - The user does not have the required access
 *   non-zero - The user has the required access
 */
function getWritable($creator, $user) {
    $level = authGetUserLevel($user);
    return $level >= 2 || ($level >= 1 && strcasecmp($creator, $user) === 0);
}

/* showAccessDenied()
 *
 * Displays an appropate message when access has been denied
 *
 * Retusns: Nothing
 */
function showAccessDenied($day, $month, $year, $area) {
    global $OUTPUT;

    print_header_mrbs($day, $month, $year, $area);
    echo $OUTPUT->box(get_string('accessdenied', 'block_mrbs').'<br/>'.get_string('norights', 'block_mrbs'), 'generalbox boxaligncenter');
    echo '<br/>';
    echo $OUTPUT->footer();
}


/** Authorize against a stored booking, including the existing room administrator exception. */
function mrbsRequireEntryWrite($entry) {
    global $DB, $USER;
    if (getWritable($entry->create_by, getUserName())) {
        return;
    }
    $email = $DB->get_field('block_mrbs_room', 'room_admin_email', ['id' => $entry->room_id]);
    if (mrbsCanOnlyEditUnconfirmed() && $email === $USER->email) {
        return;
    }
    throw new moodle_exception('bookingeditdenied', 'block_mrbs');
}
