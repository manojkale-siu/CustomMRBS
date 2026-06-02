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
        return get_all_user_name_fields(true, $prefix);
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
     * Build the canonical reservation name stored on MRBS entries.
     *
     * @param int $courseid
     * @param int $facultyid
     * @return string
     */
    public static function build_name($courseid, $facultyid) {
        $parts = array_filter([
            self::get_course_fullname($courseid),
            self::get_user_fullname($facultyid),
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

        if ($courseid && $facultyid) {
            $name = self::build_name($courseid, $facultyid);
            if ($name !== '') {
                return $name;
            }
        }

        return isset($booking->name) ? (string)$booking->name : '';
    }
}
