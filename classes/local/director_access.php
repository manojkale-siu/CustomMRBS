<?php
namespace block_mrbs\local;
defined('MOODLE_INTERNAL') || die();

/** Profile-based Director eligibility, with no implicit administrator bypass. */
final class director_access {
    public static function is_director(?int $userid = null): bool {
        global $DB, $USER, $CFG;
        $userid = $userid ?? (int)$USER->id;
        if (!$userid || isguestuser($userid)) { return false; }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user) { return false; }
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $profile = profile_user_record($userid, false);
        $value = static function ($key) use ($profile) {
            return \core_text::strtolower(trim((string)($profile->$key ?? '')));
        };
        return $value('user_category') === 'staff' && $value('user_status') === 'active'
            && strpos($value('designation'), 'director') !== false;
    }

    public static function require_director(): void {
        if (!self::is_director()) {
            throw new \moodle_exception('deviationdenied', 'block_mrbs');
        }
    }
}
