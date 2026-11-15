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

    /** @var array<int,int> Cached participant counts keyed by course and group. */
    private static $participantcountcache = [];

    /** @var array<int,array> Cached room details keyed by room id. */
    private static $roomcache = [];

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
                userdate($conflict->start_time, get_string('strftimedatetime', 'langconfig')) . ')';
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
        global $DB;

        $courseid = (int)$courseid;
        $groupid = (int)$groupid;
        if (!$courseid) {
            return 0;
        }

        $cachekey = $courseid . ':' . $groupid;
        if (isset(self::$participantcountcache[$cachekey])) {
            return self::$participantcountcache[$cachekey];
        }

        $from = '{user} u
                 JOIN {user_enrolments} ue ON ue.userid = u.id
                 JOIN {enrol} e ON e.id = ue.enrolid';
        $params = [
            'courseid' => $courseid,
            'enrolstatus' => ENROL_INSTANCE_ENABLED,
            'userstatus' => ENROL_USER_ACTIVE,
        ];

        if ($groupid > 0) {
            $from .= '
                 JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid';
            $params['groupid'] = $groupid;
        }

        $sql = "SELECT COUNT(DISTINCT u.id)
                  FROM $from
                 WHERE e.courseid = :courseid
                   AND e.status = :enrolstatus
                   AND ue.status = :userstatus
                   AND u.deleted = 0
                   AND u.suspended = 0";

        $count = (int)$DB->count_records_sql($sql, $params);
        self::$participantcountcache[$cachekey] = $count;

        return $count;
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

        return [
            'participantcount' => $participantcount,
            'blocked' => !empty($blockingrooms),
            'blockingrooms' => $blockingrooms,
            'rooms' => $rooms,
        ];
    }
}
