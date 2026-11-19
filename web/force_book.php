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

function mrbsForceMove($room_id, $starttime, $endtime, $name, $id = null) {

    global $USER;
    global $DB;

    $cfg_mrbs = get_config('block/mrbs');

    $output = '';

    if (!\block_mrbs\local\settings_manager::has_valid_custom_time_range()) {
        return '<br>'.s(get_string('mrbscustomtimerangerequired', 'block_mrbs'));
    }

    if (!$DB->record_exists('block_mrbs_room', ['id' => (int)$room_id])) {
        return '<br>'.s(get_string('mrbsinvalidroom', 'block_mrbs'));
    }

    // Select any meetings which overlap ($starttime,$endtime) for this room:
    $sql = 'SELECT e.id AS entryid,
        e.name as entryname,
        e.description as description,
        e.type as type,
        e.create_by,
        e.room_id,
        e.courseid,
        e.name,
        e.start_time,
        e.end_time,
        r.room_name,
        r.description,
        r.area_id
              FROM {block_mrbs_entry} e
              JOIN {block_mrbs_room} r
              ON e.room_id = r.id
             WHERE ((e.start_time >= ? AND e.end_time < ?)
             OR (e.start_time < ? AND e.end_time > ?)
             OR (e.start_time < ? AND e.end_time >= ?))';

    //this is so that if a booking is being edited (normally extended) and forcing the booking a confusing email doesn't get sent to the force booker
    if (!empty($id)) {
        $sql .= ' AND e.id!=?';
    }
    $sql .= ' AND e.room_id = ? ORDER BY e.start_time';

    if (!empty($id)) {
        $oldbookings = $DB->get_records_sql($sql, array(
            $starttime, $endtime, $starttime, $starttime, $endtime, $endtime, $id, $room_id
        ));
    } else {
        $oldbookings = $DB->get_records_sql($sql, array(
            $starttime, $endtime, $starttime, $starttime, $endtime, $endtime, $room_id
        ));
    }

    foreach ($oldbookings as $oldbooking) {

        $today = mktime(0, 0, 0, date('n'), date('j'), date('Y'));
        $hrstarttime = to_hr_time($oldbooking->start_time - ($today));

        //Work out how many students so they don't get put in a tiny classroom
        $sizequery = 'SELECT count(*) as count
                        FROM {context} c
                            JOIN {role_assignments} ra
                                ON ra.contextid = c.id AND ra.roleid = ?
                            JOIN {course} co
                                ON c.contextlevel = ? and c.instanceid = co.id
                        WHERE co.id = ?';
        $courseid = empty($oldbooking->courseid) ? 0 : (int)$oldbooking->courseid;

        if ($courseid && $result = $DB->get_record_sql($sizequery, array('5', '50', $courseid))) {
            $class_size = $result->count;
        } else {
            $class_size = 0;
        }

        $findroomquery = 'SELECT DISTINCT
                                r.id,
                                r.room_name,
                                a.area_name,
                                IF (r.description = ?, 1, 0) AS sort1,
                                IF (a.id = ?, 1, 0) AS sort2
                             FROM {block_mrbs_room} r
                             JOIN {block_mrbs_area} a
                                ON r.area_id = a.id
                             JOIN {block_mrbs_entry} e
                                ON r.id= e.room_id
                             WHERE ( SELECT COUNT(*) FROM {block_mrbs_entry} e2
                                 WHERE ((e2.start_time >= ? AND e2.end_time < ?)
                                 OR (e2.start_time < ? AND e2.end_time > ?)
                                 OR (e2.start_time < ? AND e2.end_time >= ?))
                                 AND e2.room_id = r.id ) < 1
                             AND r.description like ?
                             AND r.capacity >= ?
                             AND (r.description not like ?
                             OR r.id= ?)
                             ORDER BY sort1 DESC, sort2 DESC';

        //dump them in first room on the list
        //            $findroomresult=get_record_sql($findroomquery,true);
        $params = array(
            $oldbooking->description,
            $oldbooking->area_id,
            $oldbooking->start_time,
            $oldbooking->end_time,
            $oldbooking->start_time,
            $oldbooking->start_time,
            $oldbooking->end_time,
            $oldbooking->end_time,
            '%teaching%',
            $class_size,
            '%special%',
            $oldbooking->room_id
        );

        $findroomresult = $DB->get_record_sql($findroomquery, $params);

        if (!$findroomresult) {
            $langvars = new stdClass;
            $langvars->name = $oldbooking->entryname;
            $langvars->id = $oldbooking->entryid;
            $output .= '<br>'.get_string('bookingmoveerrorshort', 'block_mrbs', $langvars);
            mail($cfg_mrbs->admin_email, get_string('bookingmoveerror', 'block_mrbs'),
                get_string('bookingmoveerrormessage', 'block_mrbs', $langvars));
            continue;
        }

        $subject = get_string('bookingmoved', 'block_mrbs');
        $langvars = new stdClass;
        $langvars->name = $oldbooking->entryname;
        $langvars->id = $oldbooking->entryid;
        $langvars->oldroom = $oldbooking->room_name;
        $langvars->newroom = $findroomresult->room_name;
        $langvars->area = $findroomresult->area_name;
        $langvars->date = date('d/m/Y', $oldbooking->start_time);
        $langvars->starttime = $hrstarttime;
        $langvars->newbookingname = $name;

        $booking = new stdClass;
        $booking->id = $oldbooking->entryid;
        $booking->room_id = $findroomresult->id;

        //If it is an imported booking, mark it as edited
        if ($oldbooking->type == 'K') {
            $booking->type = 'L';
        } else {
            $booking->type = $oldbooking->type;
        }
        if ($findroomresult and $DB->update_record('block_mrbs_entry', $booking) and $oldbookingowner = $DB->get_record('user', array('username' => $oldbooking->create_by))) {
            $message = get_string('bookingmovedmessage', 'block_mrbs', $langvars);
            $output .= '<br>'.get_string('bookingmovedshort', 'block_mrbs', $langvars);
            email_to_user($oldbookingowner, $USER, $subject, $message);
        } else {
            $output .= '<br>'.get_string('bookingmoveerrorshort', 'block_mrbs', $langvars);
            mail($cfg_mrbs->admin_email, get_string('bookingmoveerror', 'block_mrbs'), get_string('bookingmoveerrormessage', 'block_mrbs', $langvars));
        }
    }

    return $output;
}


/**
 * Find bookings displaced by the requested rooms and slots.
 *
 * @return array Conflicting booking records keyed by entry ID.
 */
function mrbsFindForceConflicts(array $roomids, array $slots, $ignoreid, $ignorerepeatid) {
    global $DB;

    [$roomsql, $roomparams] = $DB->get_in_or_equal($roomids);
    $conflicts = [];
    foreach ($slots as [$start, $end]) {
        $select = "room_id $roomsql AND start_time < ? AND end_time > ? AND id <> ?";
        $params = array_merge($roomparams, [$end, $start, $ignoreid]);
        if ($ignorerepeatid > 0) {
            $select .= ' AND repeat_id <> ?';
            $params[] = $ignorerepeatid;
        }
        $conflicts += $DB->get_records_select('block_mrbs_entry', $select, $params, 'start_time, id');
    }

    return $conflicts;
}

/**
 * Plan room changes for the bookings displaced by the requested slots.
 *
 * @return array|null Planned moves, or null if a conflict cannot be relocated.
 */
function mrbsPlanForceMoves(array $roomids, array $slots, array $conflicts) {
    global $DB, $USER;

    if (!$conflicts) {
        return [];
    }

    $rooms = $DB->get_records_sql("SELECT r.*, a.area_name
                                   FROM {block_mrbs_room} r
                                   JOIN {block_mrbs_area} a ON a.id = r.area_id
                               ORDER BY r.room_name, r.id");
    $planned = [];
    foreach ($conflicts as $entry) {
        if (!isset($rooms[$entry->room_id])) {
            return null;
        }
        $original = $rooms[$entry->room_id];
        $originaldescription = strtolower((string)$original->description);
        $originalispecial = strpos($originaldescription, 'special') !== false;
        $originalisit = strpos($originaldescription, 'teaching it') === 0;
        $owner = $DB->get_record('user', ['username' => $entry->create_by, 'deleted' => 0], '*', IGNORE_MISSING);
        $size = \block_mrbs\local\booking_validator::count_participants($entry->courseid, $entry->groupid);
        $candidates = $rooms;
        uasort($candidates, static function ($a, $b) use ($original) {
            return [
                $a->description !== $original->description, $a->area_id != $original->area_id,
            ] <=> [
                $b->description !== $original->description, $b->area_id != $original->area_id,
            ];
        });

        $destination = null;
        foreach ($candidates as $candidate) {
            if (in_array((int)$candidate->id, $roomids, true) || (int)$candidate->capacity < $size) {
                continue;
            }
            if (!allowed_to_book($USER, $candidate)
                    || (!empty($candidate->booking_users) && (!$owner || !allowed_to_book($owner, $candidate)))) {
                continue;
            }
            // Preserve specialist or IT requirements when specified by the original room.
            $description = strtolower((string)$candidate->description);
            if (($originalisit && strpos($description, 'teaching it') !== 0)
                    || ($description !== $originaldescription && ($originalispecial || strpos($description, 'special') !== false))) {
                continue;
            }
            if ($DB->record_exists_select('block_mrbs_entry', 'room_id = ? AND start_time < ? AND end_time > ?', [
                $candidate->id, $entry->end_time, $entry->start_time,
            ])) {
                continue;
            }
            foreach ($planned as $move) {
                if ($move['room']->id == $candidate->id && $move['start'] < $entry->end_time
                        && $move['end'] > $entry->start_time) {
                    continue 2;
                }
            }
            $destination = $candidate;
            break;
        }
        $start = (int)$entry->start_time;
        $end = (int)$entry->end_time;
        if (!$destination) {
            $slot = mrbsFindForceTimeSlot($entry, $original, $slots, $planned);
            if ($slot === null) {
                return null;
            }
            [$start, $end] = $slot;
            $destination = $original;
        }
        $planned[] = [
            'entry' => $entry, 'room' => $destination, 'original' => $original,
            'owner' => $owner, 'start' => $start, 'end' => $end,
        ];
    }
    return $planned;
}

/**
 * Find another slot on the same day without changing the room or session length.
 */
function mrbsFindForceTimeSlot($entry, $room, array $reserved, array $planned) {
    global $DB, $USER, $enable_periods, $resolution, $morningstarts, $morningstarts_minutes, $eveningends, $eveningends_minutes;

    if (!allowed_to_book($USER, $room)) {
        return null;
    }
    if (!empty($entry->attendancesessionid)
            && ($DB->get_field('attendance_sessions', 'lasttaken', ['id' => $entry->attendancesessionid])
                || $DB->record_exists('attendance_log', ['sessionid' => $entry->attendancesessionid]))) {
        return null;
    }

    $year = (int)date('Y', $entry->start_time);
    $month = (int)date('n', $entry->start_time);
    $day = (int)date('j', $entry->start_time);
    if ($enable_periods) {
        [$opening, $closing] = \block_mrbs\local\settings_manager::get_period_day_bounds($year, $month, $day);
        $interval = \block_mrbs\local\settings_manager::get_period_interval_seconds();
    } else {
        $interval = (int)$resolution;
        $opening = mktime($morningstarts, $morningstarts_minutes, 0, $month, $day, $year);
        $closing = mktime($eveningends, $eveningends_minutes, 0, $month, $day, $year) + $interval;
    }
    $duration = (int)$entry->end_time - (int)$entry->start_time;
    if ($interval <= 0 || $duration <= 0) {
        return null;
    }

    // The retained booking also occupies this room in every requested interval.
    $unavailable = $reserved;
    foreach ($planned as $move) {
        if ($move['room']->id == $room->id
                || ($entry->facultyid && $move['entry']->facultyid == $entry->facultyid)) {
            $unavailable[] = [$move['start'], $move['end']];
        }
    }
    $earlier = [];
    $later = [];
    for ($start = $opening; $start + $duration <= $closing; $start += $interval) {
        if ($start < $entry->start_time) {
            $earlier[] = $start;
        } else if ($start > $entry->start_time) {
            $later[] = $start;
        }
    }
    foreach (array_merge($later, array_reverse($earlier)) as $start) {
        $end = $start + $duration;
        foreach ($unavailable as [$from, $to]) {
            if ($start < $to && $end > $from) {
                continue 2;
            }
        }
        if ($DB->record_exists_select('block_mrbs_entry', 'room_id = ? AND id <> ? AND start_time < ? AND end_time > ?', [
            $room->id, $entry->id, $end, $start,
        ])) {
            continue;
        }
        if ($entry->facultyid && \block_mrbs\local\booking_validator::find_faculty_conflicts(
                $entry->facultyid, $start, $end, $entry->id)) {
            continue;
        }
        if (\block_mrbs\local\booking_validator::find_student_conflicts(
                $entry->courseid, $entry->groupid, $start, $end, $entry->id)) {
            continue;
        }
        foreach ($planned as $move) {
            if ($move['start'] < $end && $move['end'] > $start
                    && \block_mrbs\local\booking_validator::share_students(
                        $entry->courseid, $entry->groupid, $move['entry']->courseid, $move['entry']->groupid)) {
                continue 2;
            }
        }
        return [$start, $end];
    }
    return null;
}

/**
 * Apply the planned relocations inside the booking transaction.
 */
function mrbsApplyForceMoves(array $moves) {
    global $DB;

    foreach ($moves as $move) {
        $entry = $move['entry'];
        $update = (object)[
            'id' => $entry->id,
            'room_id' => $move['room']->id,
            'roomchange' => $entry->room_id == $move['room']->id ? $entry->roomchange : 1,
            'start_time' => $move['start'],
            'end_time' => $move['end'],
            'timestamp' => time(),
        ];
        if ($entry->repeat_id > 0) {
            $update->entry_type = 2;
        }
        if ($entry->type === 'K') {
            $update->type = 'L';
        }
        $DB->update_record('block_mrbs_entry', $update);
        if ($move['start'] !== (int)$entry->start_time && !empty($entry->attendancesessionid)) {
            \block_mrbs\local\attendance_manager::sync_for_entry($entry->id, $entry->courseid, $entry->facultyid, $entry->groupid);
        }
    }
}

/**
 * Notify displaced booking owners after the database transaction has committed.
 */
function mrbsNotifyForceMoves(array $moves, $name) {
    global $USER;

    $output = '';
    foreach ($moves as $move) {
        $entry = $move['entry'];
        $room = $move['room'];
        $details = (object)[
            'name' => $entry->name,
            'oldroom' => $move['original']->room_name,
            'newroom' => $room->room_name,
            'area' => $room->area_name,
            'date' => userdate($entry->start_time, get_string('strftimedate', 'langconfig')),
            'starttime' => userdate($entry->start_time, get_string('strftimetime', 'langconfig')),
            'newbookingname' => $name,
        ];
        $status = 'bookingmovedshort';
        $message = 'bookingmovedmessage';
        if ($move['start'] !== (int)$entry->start_time) {
            $details->oldtime = userdate($entry->start_time);
            $details->newtime = userdate($move['start']);
            $status = 'bookingrescheduledshort';
            $message = 'bookingrescheduledmessage';
        }
        $output .= '<br>' . s(get_string($status, 'block_mrbs', $details));

    }
    return $output;
}
