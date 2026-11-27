<?php
namespace block_mrbs\local;
defined('MOODLE_INTERNAL') || die();

/** Mandatory selection and immutable evidence for the separate Attendance patch. */
final class attendance_planning {
    public static function session(int $sessionid): \stdClass {
        global $DB;
        return $DB->get_record_sql('SELECT s.*, a.course AS courseid FROM {attendance_sessions} s
            JOIN {attendance} a ON a.id = s.attendanceid WHERE s.id = ?', [$sessionid], MUST_EXIST);
    }

    public static function taken(\stdClass $session): bool {
        global $DB;
        return !empty($session->lasttaken) || $DB->record_exists('attendance_log', ['sessionid' => $session->id]);
    }

    public static function is_locked(int $planid): bool {
        global $DB;
        foreach ($DB->get_records('block_mrbs_plan_use', ['planid' => $planid]) as $use) {
            if ($use->confirmedat) {
                return true;
            }
            $session = $DB->get_record('attendance_sessions', ['id' => $use->sessionid]);
            if ($session && self::taken($session)) {
                return true;
            }
        }
        return false;
    }

    /** Lock the session first, then the faculty plan, consistently for selection and marking. */
    private static function run(int $sessionid, int $facultyid, callable $callback) {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('block_mrbs');
        $sessionlock = $factory->get_lock('plan_session_' . $sessionid, 10);
        if (!$sessionlock) { throw new \moodle_exception('bookingbusy', 'block_mrbs'); }
        $planlock = null;
        try {
            $planlock = $factory->get_lock('faculty_plan_' . $facultyid, 10);
            if (!$planlock) { throw new \moodle_exception('bookingbusy', 'block_mrbs'); }
            $tx = $DB->start_delegated_transaction();
            try {
                $result = $callback();
                $tx->allow_commit();
                return $result;
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally {
            if ($planlock) { $planlock->release(); }
            $sessionlock->release();
        }
    }

    public static function selection(int $sessionid): ?\stdClass {
        global $DB;
        return $DB->get_record('block_mrbs_plan_use', ['sessionid' => $sessionid]) ?: null;
    }

    public static function eligible_plans(\stdClass $session): array {
        global $DB;
        $entry = $DB->get_record('block_mrbs_entry', ['attendancesessionid' => $session->id], 'id,facultyid');
        $params = ['courseid' => $session->courseid];
        if ($entry && $entry->facultyid) { $params['facultyid'] = $entry->facultyid; }
        return $DB->get_records('block_mrbs_faculty_plan', $params, 'proposeddate, facultyid, id');
    }

    public static function select(int $sessionid, int $planid, string $taught): void {
        global $DB, $USER;
        $session = self::session($sessionid);
        $cm = get_coursemodule_from_instance('attendance', $session->attendanceid, $session->courseid, false, MUST_EXIST);
        require_capability('mod/attendance:takeattendances', \context_module::instance($cm->id));
        $plan = $DB->get_record('block_mrbs_faculty_plan', ['id' => $planid], '*', MUST_EXIST);
        self::run($sessionid, (int)$plan->facultyid, static function () use ($sessionid, $planid, $taught, $DB, $USER) {
            $session = self::session($sessionid);
            if (self::taken($session)) { throw new \moodle_exception('planningtakenlocked', 'block_mrbs'); }
            $plans = self::eligible_plans($session);
            if (!isset($plans[$planid])) { throw new \moodle_exception('planningnotfound', 'block_mrbs'); }
            $plan = $plans[$planid];
            $taught = trim(clean_param($taught, PARAM_TEXT));
            if ($taught === '' || \core_text::strlen($taught) > 10000) {
                throw new \moodle_exception('planningtaughtrequired', 'block_mrbs');
            }
            $existing = self::selection($sessionid);
            if ($existing && $existing->confirmedat) { throw new \moodle_exception('planningtakenlocked', 'block_mrbs'); }
            $use = (object)['sessionid' => $sessionid, 'planid' => $planid, 'courseid' => $plan->courseid,
                'facultyid' => $plan->facultyid, 'proposeddate' => $plan->proposeddate, 'plannedtopics' => $plan->topics,
                'taughttopics' => $taught, 'selectedby' => $USER->id, 'timecreated' => time(), 'conducteddate' => 0, 'confirmedat' => 0];
            if ($existing) { $use->id = $existing->id; $DB->update_record('block_mrbs_plan_use', $use); }
            else { $DB->insert_record('block_mrbs_plan_use', $use); }
        });
    }

    public static function require_selection(int $sessionid): void {
        $session = self::session($sessionid);
        $use = self::selection($sessionid);
        if (!$use || (int)$use->courseid !== (int)$session->courseid) {
            throw new \moodle_exception('planningselectbeforeattendance', 'block_mrbs');
        }
    }

    /** Called around the actual Attendance writer, not just around its play-button UI. */
    public static function record(int $sessionid, callable $writer) {
        global $DB;
        self::require_selection($sessionid);
        $selected = self::selection($sessionid);
        return self::run($sessionid, (int)$selected->facultyid, static function () use ($sessionid, $writer, $DB, $selected) {
            self::require_selection($sessionid);
            $use = self::selection($sessionid);
            // A selection can change while waiting for the session lock; retry rather than lock the wrong faculty.
            if ((int)$use->facultyid !== (int)$selected->facultyid) {
                throw new \moodle_exception('bookingbusy', 'block_mrbs');
            }
            $session = self::session($sessionid);
            if (!$use->confirmedat && !self::taken($session)) {
                $plans = self::eligible_plans($session);
                if (!isset($plans[$use->planid])) { throw new \moodle_exception('planningnotfound', 'block_mrbs'); }
                $plan = $plans[$use->planid];
                $use->plannedtopics = $plan->topics;
                $use->proposeddate = $plan->proposeddate;
                $DB->update_record('block_mrbs_plan_use', $use);
            }
            $result = $writer();
            if (self::taken(self::session($sessionid)) && !$use->confirmedat) {
                $DB->update_record('block_mrbs_plan_use', (object)['id' => $use->id,
                    'confirmedat' => time(), 'conducteddate' => $session->sessdate]);
            }
            return $result;
        });
    }
}
