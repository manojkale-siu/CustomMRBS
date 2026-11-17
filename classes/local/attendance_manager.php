<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Synchronises MRBS bookings with Moodle Attendance sessions.
 *
 * @package   block_mrbs
 */
class attendance_manager {

    /**
     * Create or update the attendance session for one MRBS entry.
     *
     * @param int $entryid
     * @param int $courseid
     * @param int $facultyid
     * @param int $groupid
     * @return int Attendance session id, or 0 when no session is required.
     */
    public static function sync_for_entry($entryid, $courseid, $facultyid = 0, $groupid = 0) {
        global $DB;

        $entryid = (int)$entryid;
        $courseid = (int)$courseid;
        $facultyid = (int)$facultyid;
        $groupid = (int)$groupid;

        if (!$entryid) {
            return 0;
        }

        $entry = $DB->get_record('block_mrbs_entry', ['id' => $entryid], '*', MUST_EXIST);
        if (!$courseid) {
            self::delete_for_entry($entryid);
            return 0;
        }

        $attendance = self::get_attendance_for_course($courseid);
        if (!$attendance) {
            throw new \moodle_exception('courseattendancemissing', 'block_mrbs');
        }

        self::require_attendance_api();
        $att = self::get_attendance_structure($attendance);
        $existingid = empty($entry->attendancesessionid) ? 0 : (int)$entry->attendancesessionid;

        if ($existingid) {
            $existing = $DB->get_record('attendance_sessions', ['id' => $existingid], '*', IGNORE_MISSING);
            if (!$existing) {
                $existingid = 0;
            } else if ((int)$existing->attendanceid !== (int)$attendance->id) {
                self::delete_session($existingid);
                $existingid = 0;
            }
        }

        if ($existingid) {
            self::update_session($att, $existingid, $entry, $facultyid, $groupid);
            $sessionid = $existingid;
        } else {
            $session = self::build_session($entry, $facultyid, $groupid);
            $sessionid = $att->add_session($session);
            self::normalise_session_record($sessionid, $facultyid);
            $updated = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', IGNORE_MISSING);
            if ($updated && !empty($updated->caleventid) && function_exists('attendance_update_calendar_event')) {
                attendance_update_calendar_event($updated);
            }
        }

        $DB->set_field('block_mrbs_entry', 'attendancesessionid', $sessionid, ['id' => $entryid]);
        return $sessionid;
    }

    /**
     * Create or update attendance sessions for every entry in a repeat series.
     *
     * @param int $repeatid
     * @param int $courseid
     * @param int $facultyid
     * @param int $groupid
     * @return array
     */
    public static function sync_for_repeat($repeatid, $courseid, $facultyid = 0, $groupid = 0) {
        global $DB;

        $sessionids = [];
        $entries = $DB->get_records('block_mrbs_entry', ['repeat_id' => (int)$repeatid], 'start_time ASC', 'id');
        foreach ($entries as $entry) {
            $sessionid = self::sync_for_entry($entry->id, $courseid, $facultyid, $groupid);
            if ($sessionid) {
                $sessionids[] = $sessionid;
            }
        }

        return $sessionids;
    }

    /**
     * Delete the Attendance session linked to an MRBS entry.
     *
     * @param int $entryid
     * @return void
     */
    public static function delete_for_entry($entryid) {
        global $DB;

        $sessionid = $DB->get_field('block_mrbs_entry', 'attendancesessionid', ['id' => (int)$entryid]);
        if ($sessionid) {
            self::delete_session((int)$sessionid);
            $DB->set_field('block_mrbs_entry', 'attendancesessionid', null, ['id' => (int)$entryid]);
        }
    }

    /**
     * Resolve the Attendance activity for a course.
     *
     * @param int $courseid
     * @return \stdClass|false
     */
    public static function get_attendance_for_course($courseid) {
        global $DB;

        return $DB->get_record('attendance', ['course' => (int)$courseid], '*', IGNORE_MULTIPLE);
    }

    /**
     * Load Attendance plugin APIs.
     *
     * @return void
     */
    private static function require_attendance_api() {
        global $CFG;

        $locallib = $CFG->dirroot . '/mod/attendance/locallib.php';
        $structure = $CFG->dirroot . '/mod/attendance/classes/structure.php';
        if (!file_exists($locallib) || !file_exists($structure)) {
            throw new \moodle_exception('attendanceapinotavailable', 'block_mrbs');
        }

        require_once($locallib);
        require_once($structure);
    }

    /**
     * Build an Attendance structure object.
     *
     * @param \stdClass $attendance
     * @return \mod_attendance_structure
     */
    private static function get_attendance_structure(\stdClass $attendance) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $attendance->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('attendance', $attendance->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        return new \mod_attendance_structure($attendance, $cm, $course, $context);
    }

    /**
     * Build a new Attendance session object from an MRBS entry.
     *
     * @param \stdClass $entry
     * @param int $facultyid
     * @param int $groupid
     * @return \stdClass
     */
    private static function build_session(\stdClass $entry, $facultyid, $groupid) {
        $config = get_config('attendance');
        $now = time();

        $session = new \stdClass();
        $session->groupid = (int)$groupid;
        $session->sessdate = (int)$entry->start_time;
        $session->duration = max(0, (int)$entry->end_time - (int)$entry->start_time);
        $session->descriptionitemid = file_get_unused_draft_itemid();
        $session->description = self::build_description($facultyid);
        $session->descriptionformat = FORMAT_MOODLE;
        $session->calendarevent = isset($config->calendarevent_default) ? (int)$config->calendarevent_default : 1;
        $session->timemodified = $now;
        $session->absenteereport = isset($config->absenteereport_default) ? (int)$config->absenteereport_default : 1;
        $session->studentscanmark = isset($config->studentscanmark_default) ? (int)$config->studentscanmark_default : 0;
        $session->allowupdatestatus = isset($config->allowupdatestatus_default) ? (int)$config->allowupdatestatus_default : 0;
        $session->studentsearlyopentime = isset($config->studentsearlyopentime) ? (int)$config->studentsearlyopentime : 0;
        $session->autoassignstatus = isset($config->autoassignstatus) ? (int)$config->autoassignstatus : 0;
        $session->studentpassword = '';
        $session->subnet = '';
        $session->automark = isset($config->automark_default) ? (int)$config->automark_default : 0;
        $session->automarkcompleted = 0;
        $session->preventsharedip = isset($config->preventsharedip) ? (int)$config->preventsharedip : 0;
        $session->preventsharediptime = isset($config->preventsharediptime) ? (int)$config->preventsharediptime : null;
        $session->includeqrcode = isset($config->includeqrcode_default) ? (int)$config->includeqrcode_default : 0;
        $session->rotateqrcode = isset($config->rotateqrcode_default) ? (int)$config->rotateqrcode_default : 0;
        $session->rotateqrcodesecret = '';
        $session->automarkcmid = 0;
        $session->statusset = 0;

        return $session;
    }

    /**
     * Update an existing Attendance session using Attendance plugin API.
     *
     * @param \mod_attendance_structure $att
     * @param int $sessionid
     * @param \stdClass $entry
     * @param int $facultyid
     * @param int $groupid
     * @return void
     */
    private static function update_session(\mod_attendance_structure $att, $sessionid, \stdClass $entry, $facultyid, $groupid) {
        global $DB;

        $session = $DB->get_record('attendance_sessions', ['id' => (int)$sessionid], '*', MUST_EXIST);
        $groupchanged = ((int)$session->groupid !== (int)$groupid);
        $session->sessdate = (int)$entry->start_time;
        $session->duration = max(0, (int)$entry->end_time - (int)$entry->start_time);
        $session->description = self::build_description($facultyid);
        $session->descriptionformat = FORMAT_MOODLE;
        $session->groupid = (int)$groupid;
        $session->timemodified = time();
        $DB->update_record('attendance_sessions', $session);

        if (empty($session->caleventid)) {
            if (function_exists('attendance_create_calendar_event')) {
                attendance_create_calendar_event($session);
            }
        } else {
            if ($groupchanged) {
                if (function_exists('attendance_delete_calendar_events')) {
                    attendance_delete_calendar_events([(int)$sessionid]);
                }
                $session->caleventid = 0;
                $DB->set_field('attendance_sessions', 'caleventid', 0, ['id' => $sessionid]);
                if (function_exists('attendance_create_calendar_event')) {
                    attendance_create_calendar_event($session);
                }
            } else {
                if (function_exists('attendance_update_calendar_event')) {
                    attendance_update_calendar_event($session);
                }
            }
        }

        $updated = $DB->get_record('attendance_sessions', ['id' => (int)$sessionid], '*', MUST_EXIST);
        $info = construct_session_full_date_time($updated->sessdate, $updated->duration);
        $event = \mod_attendance\event\session_updated::create([
            'objectid' => $att->id,
            'context' => $att->context,
            'other' => [
                'info' => $info,
                'sessionid' => (int)$sessionid,
                'action' => \mod_attendance_sessions_page_params::ACTION_UPDATE,
            ],
        ]);
        $event->add_record_snapshot('course_modules', $att->cm);
        $event->add_record_snapshot('attendance_sessions', $updated);
        $event->trigger();
    }

    /**
     * Delete an attendance session using Attendance plugin APIs when available.
     *
     * @param int $sessionid
     * @return void
     */
    private static function delete_session($sessionid) {
        global $DB;

        self::require_attendance_api();
        $session = $DB->get_record('attendance_sessions', ['id' => (int)$sessionid], '*', IGNORE_MISSING);
        if (!$session) {
            return;
        }

        $attendance = $DB->get_record('attendance', ['id' => $session->attendanceid], '*', IGNORE_MISSING);
        if ($attendance) {
            $att = self::get_attendance_structure($attendance);
            $att->delete_sessions([(int)$sessionid]);
        }
    }

    /**
     * Build the mandatory JSON description expected by the Attendance integration.
     *
     * @param int $facultyid
     * @return string
     */
    private static function build_description($facultyid) {
        return json_encode([
            'description' => '',
            'takenby' => (int)$facultyid,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Force the final persisted Attendance session payload to match native records.
     *
     * @param int $sessionid
     * @param int $facultyid
     * @return void
     */
    private static function normalise_session_record($sessionid, $facultyid) {
        global $DB;

        $sessionid = (int)$sessionid;
        if (!$sessionid) {
            return;
        }

        $DB->set_field('attendance_sessions', 'description', self::build_description($facultyid), ['id' => $sessionid]);
        $DB->set_field('attendance_sessions', 'descriptionformat', FORMAT_MOODLE, ['id' => $sessionid]);
    }
}
