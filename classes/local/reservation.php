<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Helpers for MRBS reservation labels.
 *
 * @package   block_mrbs
 */
class reservation {

    /**
     * Return the user name fields needed by fullname().
     *
     * @param string $prefix SQL table alias.
     * @return string
     */
    public static function user_name_fields_sql($prefix = '') {
        global $CFG, $DB;

        if (!function_exists('get_all_user_name_fields') && !empty($CFG->dirroot)
                && file_exists($CFG->dirroot . '/user/lib.php')) {
            require_once($CFG->dirroot . '/user/lib.php');
        }

        if (function_exists('get_all_user_name_fields')) {
            return \get_all_user_name_fields(true, $prefix);
        }

        $columns = $DB->get_columns('user');
        $fields = array_filter(
            ['firstname', 'lastname', 'firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename'],
            function($field) use ($columns) {
                return isset($columns[$field]);
            }
        );

        $fieldsql = [];
        foreach ($fields as $field) {
            $fieldsql[] = ($prefix === '' ? '' : $prefix . '.') . $field;
        }

        return implode(', ', $fieldsql);
    }

    /**
     * Get a formatted course full name.
     *
     * @param int $courseid
     * @return string
     */
    public static function get_course_fullname($courseid) {
        global $DB;

        if (empty($courseid)) {
            return '';
        }

        $course = $DB->get_record('course', ['id' => (int)$courseid], 'id, fullname', IGNORE_MISSING);
        if (!$course) {
            return '';
        }

        return format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
    }

    /**
     * Get a formatted user full name.
     *
     * @param int $userid
     * @param bool $includeemail
     * @return string
     */
    public static function get_user_fullname($userid, $includeemail = false) {
        global $DB;

        if (empty($userid)) {
            return '';
        }

        $fields = 'id, email, ' . self::user_name_fields_sql();
        $user = $DB->get_record('user', ['id' => (int)$userid, 'deleted' => 0], $fields, IGNORE_MISSING);
        if (!$user) {
            return '';
        }

        $label = fullname($user);
        if ($includeemail && !empty($user->email)) {
            $label .= ' (' . $user->email . ')';
        }

        return $label;
    }

    /**
     * Get a formatted Moodle group name.
     *
     * @param int $groupid
     * @param int $courseid
     * @return string
     */
    public static function get_group_name($groupid, $courseid = 0) {
        global $DB;

        $groupid = (int)$groupid;
        if (empty($groupid)) {
            return '';
        }

        $group = $DB->get_record('groups', ['id' => $groupid], 'id, courseid, name', IGNORE_MISSING);
        if (!$group) {
            return '';
        }

        $context = null;
        $courseid = (int)$courseid;
        if (!$courseid && !empty($group->courseid)) {
            $courseid = (int)$group->courseid;
        }
        if ($courseid) {
            $context = \context_course::instance($courseid);
        }

        if ($context) {
            return format_string($group->name, true, ['context' => $context]);
        }

        return format_string($group->name);
    }

    /**
     * Build the canonical reservation name stored on MRBS entries.
     *
     * @param int $courseid
     * @param int $facultyid
     * @param int $groupid
     * @return string
     */
    public static function build_name($courseid, $facultyid, $groupid = 0) {
        $parts = array_filter([
            self::get_course_fullname($courseid),
            self::get_user_fullname($facultyid),
            self::get_group_name($groupid, $courseid),
        ]);

        return \core_text::substr(implode(' - ', $parts), 0, 255);
    }

    /**
     * Return the display label for a booking record.
     *
     * @param \stdClass $booking
     * @return string
     */
    public static function get_display_name(\stdClass $booking) {
        $courseid = empty($booking->courseid) ? 0 : (int)$booking->courseid;
        $facultyid = empty($booking->facultyid) ? 0 : (int)$booking->facultyid;
        $groupid = empty($booking->groupid) ? 0 : (int)$booking->groupid;

        if ($courseid && $facultyid) {
            $name = self::build_name($courseid, $facultyid, $groupid);
            if ($name !== '') {
                return $name;
            }
        }

        return isset($booking->name) ? (string)$booking->name : '';
    }
}
