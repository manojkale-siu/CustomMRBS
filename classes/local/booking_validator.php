<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Server-side validation for MRBS bookings.
 *
 * @package   block_mrbs
 */
class booking_validator {

    /** @var array<int,array> Cached room details keyed by room id. */
    private static $roomcache = [];

    /** Recheck availability under the shared lock immediately before each booking write. */
    public static function require_room_available(int $roomid, int $start, int $end,
            int $ignoreid = 0, int $ignorerepeatid = 0): void {
        global $DB;
        booking_lock::require_held();
        // A room may have been deleted while an older form was open.
        $DB->get_record('block_mrbs_room', ['id' => $roomid], 'id', MUST_EXIST);
        $where = 'room_id = ? AND start_time < ? AND end_time > ? AND id <> ?';
        $params = [$roomid, $end, $start, $ignoreid];
        if ($ignorerepeatid) {
            $where .= ' AND repeat_id <> ?';
            $params[] = $ignorerepeatid;
        }
        if ($DB->record_exists_select('block_mrbs_entry', $where, $params)) {
            throw new \moodle_exception('roomalreadyclaimed', 'block_mrbs');
        }
    }

    /** Enforce configured hours for every actual occurrence, regardless of input path. */
    public static function validate_time_range(int $start, int $end, bool $periodmode): void {
        if ($end <= $start) {
            throw new \moodle_exception('sessionimportdatetime', 'block_mrbs');
        }
        if (!settings_manager::has_valid_custom_time_range()) {
            return;
        }
        [$open, $close] = settings_manager::get_period_day_bounds(\block_mrbs\local\booking_time::date('Y', $start), \block_mrbs\local\booking_time::date('n', $start), \block_mrbs\local\booking_time::date('j', $start));
        if ($start < $open || $end > $close) {
            throw new \moodle_exception('bookingoutsidehours', 'block_mrbs', '', (object)[
                'start' => \block_mrbs\local\booking_time::userdate($start), 'end' => \block_mrbs\local\booking_time::userdate($end),
                'open' => \block_mrbs\local\booking_time::userdate($open), 'close' => \block_mrbs\local\booking_time::userdate($close),
            ]);
        }
        $interval = settings_manager::get_period_interval_seconds();
        if ($periodmode && (($start - $open) % $interval || ($end !== $close && ($end - $open) % $interval))) {
            throw new \moodle_exception('sessionimportperiod', 'block_mrbs');
        }
    }

    /**
     * Student IDs eligible for a course booking, including whole-course sessions.
     */
    private static function students($courseid, $groupid): array {
        global $DB;
        if (!$courseid) {
            return [];
        }
        $context = \context_course::instance($courseid);
        [$enrolledsql, $params] = get_enrolled_sql($context, '', $groupid, true);
        // Include course, category ancestors and system roles, never unrelated categories.
        [$contextsql, $contextparams] = $DB->get_in_or_equal(
            $context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'studentcontext'
        );
        $params += $contextparams;
        $params['studentarchetype'] = 'student';
        $params['studentshortname'] = 'student';
        $sql = "SELECT u.id
                  FROM {user} u JOIN ($enrolledsql) enrolled ON enrolled.id = u.id
                 WHERE u.deleted = 0 AND u.suspended = 0
                   AND EXISTS (
                       SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                        WHERE ra.userid = u.id AND ra.contextid $contextsql
                          AND (r.archetype = :studentarchetype OR r.shortname = :studentshortname)
                   )";
        return array_fill_keys($DB->get_fieldset_sql($sql, $params), true);
    }

    /** Compare actual active student membership, including overlapping groups. */
    public static function share_students($courseid, $groupid, $othercourse, $othergroup): bool {
        return (bool)array_intersect_key(self::students($courseid, $groupid), self::students($othercourse, $othergroup));
    }

    /** Compare actual people, including faculty attending another course as a student. */
    public static function share_participants($courseid, $groupid, $facultyid, $othercourse, $othergroup, $otherfaculty): bool {
        $first = self::students($courseid, $groupid);
        $second = self::students($othercourse, $othergroup);
        if ($facultyid) {
            $first[(int)$facultyid] = true;
        }
        if ($otherfaculty) {
            $second[(int)$otherfaculty] = true;
        }
        return (bool)array_intersect_key($first, $second);
    }

    /** No person may occupy two overlapping sessions, regardless of their role. */
    public static function find_participant_conflicts($courseid, $groupid, $facultyid, $start, $end,
            $ignoreid = 0, $ignorerepeatid = 0): array {
        global $DB;
        $where = 'start_time < ? AND end_time > ? AND id <> ?';
        $params = [$end, $start, $ignoreid];
        if ($ignorerepeatid) {
            $where .= ' AND repeat_id <> ?';
            $params[] = $ignorerepeatid;
        }
        $conflicts = [];
        foreach ($DB->get_records_select('block_mrbs_entry', $where, $params) as $entry) {
            if (self::share_participants($courseid, $groupid, $facultyid,
                    $entry->courseid, $entry->groupid, $entry->facultyid)) {
                $conflicts[$entry->id] = $entry;
            }
        }
        return $conflicts;
    }

    /** Find overlaps even when faculty and room are different. */
    public static function find_student_conflicts($courseid, $groupid, $start, $end, $ignoreid = 0, $ignorerepeatid = 0): array {
        global $DB;
        $where = 'start_time < ? AND end_time > ? AND id <> ?';
        $params = [$end, $start, $ignoreid];
        if ($ignorerepeatid) {
            $where .= ' AND repeat_id <> ?';
            $params[] = $ignorerepeatid;
        }
        $conflicts = [];
        foreach ($DB->get_records_select('block_mrbs_entry', $where, $params) as $entry) {
            if (self::share_students($courseid, $groupid, $entry->courseid, $entry->groupid)) {
                $conflicts[$entry->id] = $entry;
            }
        }
        return $conflicts;
    }

    /**
     * Find overlapping bookings for a faculty member.
     *
     * @param int $facultyid
     * @param int $starttime
     * @param int $endtime
     * @param int $ignoreid
     * @param int $ignorerepeatid
     * @return array
     */
    public static function find_faculty_conflicts($facultyid, $starttime, $endtime, $ignoreid = 0, $ignorerepeatid = 0) {
        global $DB;

        $facultyid = (int)$facultyid;
        if (!$facultyid) {
            return [];
        }

        $params = [
            'facultyid' => $facultyid,
            'starttime' => (int)$starttime,
            'endtime' => (int)$endtime,
        ];
        $where = 'e.facultyid = :facultyid
                  AND e.start_time < :endtime
                  AND e.end_time > :starttime';

        if ($ignoreid) {
            $where .= ' AND e.id <> :ignoreid';
            $params['ignoreid'] = (int)$ignoreid;
        }
        if ($ignorerepeatid) {
            $where .= ' AND e.repeat_id <> :ignorerepeatid';
            $params['ignorerepeatid'] = (int)$ignorerepeatid;
        }

        $sql = "SELECT e.id, e.name, e.start_time, e.end_time, e.room_id, e.courseid, e.facultyid,
                       r.room_name, a.area_name
                  FROM {block_mrbs_entry} e
                  JOIN {block_mrbs_room} r ON r.id = e.room_id
                  JOIN {block_mrbs_area} a ON a.id = r.area_id
                 WHERE $where
              ORDER BY e.start_time ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Build a localized faculty conflict message.
     *
     * @param array $conflicts
     * @return string
     */
    public static function faculty_conflict_message(array $conflicts) {
        if (empty($conflicts)) {
            return '';
        }

        $items = [];
        foreach ($conflicts as $conflict) {
            $label = reservation::get_display_name($conflict);
            $items[] = s($label) . ' (' . s($conflict->area_name) . ' - ' . s($conflict->room_name) . ', ' .
                \block_mrbs\local\booking_time::userdate($conflict->start_time, get_string('strftimedatetime', 'langconfig')) . ')';
        }

        return get_string('facultybookingconflict', 'block_mrbs') . \html_writer::alist($items);
    }

    /**
     * Count active participants for a course or course group.
     *
     * @param int $courseid
     * @param int $groupid
     * @return int
     */
    public static function count_participants($courseid, $groupid = 0) {
        $courseid = (int)$courseid;
        if (!$courseid) {
            return 0;
        }
        // Count active enrolled users of all roles, excluding suspended accounts.
        // Keep this fresh: later enrolments and group changes affect the next view/check.
        $users = get_enrolled_users(\context_course::instance($courseid), '', (int)$groupid,
            'u.id, u.suspended', 'u.id ASC', 0, 0, true);
        return count(array_filter($users, static function ($user) { return empty($user->suspended); }));
    }

    /**
     * Return room capacity information for the supplied room ids.
     *
     * @param array $roomids
     * @return array<int,array{id:int,name:string,capacity:int}>
     */
    public static function get_room_capacity_details(array $roomids) {
        global $DB;

        $roomids = array_values(array_unique(array_filter(array_map('intval', $roomids))));
        if (empty($roomids)) {
            return [];
        }

        $results = [];
        $missingids = [];
        foreach ($roomids as $roomid) {
            if (isset(self::$roomcache[$roomid])) {
                $results[$roomid] = self::$roomcache[$roomid];
            } else {
                $missingids[] = $roomid;
            }
        }

        if (!empty($missingids)) {
            $rooms = $DB->get_records_list('block_mrbs_room', 'id', $missingids, '', 'id, room_name, capacity');
            foreach ($rooms as $room) {
                $results[(int)$room->id] = self::$roomcache[(int)$room->id] = [
                    'id' => (int)$room->id,
                    'name' => format_string($room->room_name),
                    'capacity' => max(0, (int)$room->capacity),
                ];
            }
        }

        return array_values($results);
    }

    /**
     * Check whether the selected rooms can hold the selected participants.
     *
     * @param int $courseid
     * @param int $groupid
     * @param array $roomids
     * @return array
     */
    public static function check_room_capacity($courseid, $groupid, array $roomids) {
        $participantcount = self::count_participants($courseid, $groupid);
        $rooms = self::get_room_capacity_details($roomids);

        $blockingrooms = [];
        foreach ($rooms as $room) {
            if (!empty($room['capacity']) && $participantcount > $room['capacity']) {
                $blockingrooms[] = $room;
            }
        }

        $details = [
            'courseparticipantcount' => $groupid ? self::count_participants($courseid, 0) : $participantcount,
            'participantcount' => $participantcount,
            'blocked' => !empty($blockingrooms),
            'blockingrooms' => $blockingrooms,
            'rooms' => $rooms,
        ];
        $details['warningmessage'] = s(self::capacity_comparison($details)) . '<br>'
            . s(get_string('capacitywarningmessage', 'block_mrbs'));
        return $details;
    }
    /** Plain-text comparison, shared by the manual warning and Excel row errors. */
    public static function capacity_comparison(array $details): string {
        $rooms = [];
        foreach ($details['rooms'] as $room) {
            $rooms[] = get_string('roomcapacitycomparison', 'block_mrbs', (object)[
                'room' => strip_tags($room['name']), 'capacity' => $room['capacity'],
                'excess' => $room['capacity'] ? max(0, $details['participantcount'] - $room['capacity']) : 0,
            ]);
        }
        return get_string('capacitycomparison', 'block_mrbs', (object)[
            'coursecount' => $details['courseparticipantcount'], 'selectedcount' => $details['participantcount'],
            'rooms' => implode('; ', $rooms),
        ]);
    }
}
