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

/** Retired unsafe legacy relocation path. Use the locked planner and transaction instead. */
function mrbsForceMove($room_id, $starttime, $endtime, $name, $id = null) {
    throw new moodle_exception('legacycsvdisabled', 'block_mrbs');
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
            if (in_array((int)$candidate->id, $roomids, true)
                    || (!empty($candidate->capacity) && (int)$candidate->capacity < $size)) {
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

    $year = (int)\block_mrbs\local\booking_time::date('Y', $entry->start_time);
    $month = (int)\block_mrbs\local\booking_time::date('n', $entry->start_time);
    $day = (int)\block_mrbs\local\booking_time::date('j', $entry->start_time);
    if ($enable_periods) {
        [$opening, $closing] = \block_mrbs\local\settings_manager::get_period_day_bounds($year, $month, $day);
        $interval = \block_mrbs\local\settings_manager::get_period_interval_seconds();
    } else {
        $interval = (int)$resolution;
        $opening = \block_mrbs\local\booking_time::mktime($morningstarts, $morningstarts_minutes, 0, $month, $day, $year);
        $closing = \block_mrbs\local\booking_time::mktime($eveningends, $eveningends_minutes, 0, $month, $day, $year) + $interval;
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
        if (\block_mrbs\local\booking_validator::find_participant_conflicts(
                $entry->courseid, $entry->groupid, $entry->facultyid, $start, $end, $entry->id)) {
            continue;
        }
        foreach ($planned as $move) {
            if ($move['start'] < $end && $move['end'] > $start
                    && \block_mrbs\local\booking_validator::share_participants(
                        $entry->courseid, $entry->groupid, $entry->facultyid,
                        $move['entry']->courseid, $move['entry']->groupid, $move['entry']->facultyid)) {
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
    \block_mrbs\local\booking_lock::require_held();

    $movingids = [];
    foreach ($moves as $move) {
        $movingids[$move['entry']->id] = true;
    }
    foreach ($moves as $index => $move) {
        $entry = $move['entry'];
        $conflicts = \block_mrbs\local\booking_validator::find_participant_conflicts(
            $entry->courseid, $entry->groupid, $entry->facultyid, $move['start'], $move['end'], $entry->id
        );
        if (array_diff_key($conflicts, $movingids)) {
            throw new moodle_exception('participantbookingconflict', 'block_mrbs');
        }
        foreach ($moves as $otherindex => $other) {
            if ($index !== $otherindex && $move['start'] < $other['end'] && $move['end'] > $other['start']
                    && \block_mrbs\local\booking_validator::share_participants(
                        $entry->courseid, $entry->groupid, $entry->facultyid,
                        $other['entry']->courseid, $other['entry']->groupid, $other['entry']->facultyid)) {
                throw new moodle_exception('participantbookingconflict', 'block_mrbs');
            }
        }
    }
    foreach ($moves as $move) {
        $entry = $move['entry'];
        \block_mrbs\local\booking_validator::validate_time_range($move['start'], $move['end'], !empty($GLOBALS['enable_periods']));
        \block_mrbs\local\booking_validator::require_room_available(
            (int)$move['room']->id, (int)$move['start'], (int)$move['end'], (int)$entry->id
        );
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
        if (($move['start'] !== (int)$entry->start_time || $move['end'] !== (int)$entry->end_time)
                && !empty($entry->attendancesessionid)) {
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
            'date' => \block_mrbs\local\booking_time::userdate($entry->start_time, get_string('strftimedate', 'langconfig')),
            'starttime' => \block_mrbs\local\booking_time::userdate($entry->start_time, get_string('strftimetime', 'langconfig')),
            'newbookingname' => $name,
        ];
        $status = 'bookingmovedshort';
        $message = 'bookingmovedmessage';
        if ($move['start'] !== (int)$entry->start_time) {
            $details->oldtime = \block_mrbs\local\booking_time::userdate($entry->start_time);
            $details->newtime = \block_mrbs\local\booking_time::userdate($move['start']);
            $status = 'bookingrescheduledshort';
            $message = 'bookingrescheduledmessage';
        }
        $output .= '<br>' . s(get_string($status, 'block_mrbs', $details));

    }
    return $output;
}
