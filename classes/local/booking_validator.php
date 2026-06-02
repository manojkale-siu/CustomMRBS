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
}
