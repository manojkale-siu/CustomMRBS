<?php
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** Permissions are resolved against each stored occurrence and its actual room. */
final class booking_permissions {
    public static function can_write(\stdClass $entry): bool {
        global $DB, $USER;
        if (timetable_access::is_admin()) {
            return true;
        }
        if (!timetable_access::can_edit()) {
            return false;
        }
        if (strcasecmp($entry->create_by, $USER->username) === 0) {
            return true;
        }
        $email = $DB->get_field('block_mrbs_room', 'room_admin_email', ['id' => $entry->room_id]);
        return !empty($email) && !empty($USER->email)
            && has_capability('block/mrbs:editunconfirmed', \context_system::instance(), null, false)
            && strcasecmp(trim($email), $USER->email) === 0;
    }

    /** Authorize the complete target set before any booking or Attendance deletion. */
    public static function require_delete(array $entries): void {
        foreach ($entries as $entry) {
            if (!self::can_write($entry)) {
                throw new \moodle_exception('bookingdeletedenied', 'block_mrbs');
            }
        }
    }
}
