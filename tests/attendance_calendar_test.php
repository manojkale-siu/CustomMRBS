<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** MRBS permission delegates only its linked activity calendar updates. */
final class attendance_calendar_test extends \advanced_testcase {
    public function test_mrbs_administrator_can_sync_without_course_calendar_permission(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        if (!file_exists($CFG->dirroot . '/mod/attendance/version.php')) {
            $this->markTestSkipped('Attendance is required for this integration test.');
        }
        $this->setAdminUser();
        set_config('enablecalendar', 1, 'attendance');
        set_config('calendarevent_default', 1, 'attendance');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $activity = $generator->create_module('attendance', ['course' => $course->id]);
        $actor = $generator->create_user();
        $role = $generator->create_role();
        $system = \context_system::instance();
        assign_capability('block/mrbs:administermrbs', CAP_ALLOW, $role, $system->id);
        role_assign($role, $actor->id, $system->id);
        $this->setUser($actor);
        $this->assertFalse(has_capability('moodle/calendar:manageentries', \context_course::instance($course->id)));
        $start = time() + 86400;
        $entryid = $DB->insert_record('block_mrbs_entry', (object)[
            'start_time' => $start, 'end_time' => $start + 3600, 'courseid' => $course->id,
            'name' => 'Session', 'create_by' => $actor->username, 'room_id' => 1,
        ]);
        $sessionid = local\booking_cancellation::run(static function () use ($entryid, $course, $actor) {
            return local\attendance_manager::sync_for_entry($entryid, $course->id, $actor->id);
        });
        $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $event = $DB->get_record('event', ['id' => $session->caleventid], '*', MUST_EXIST);
        $this->assertSame((int)$activity->id, (int)$event->instance);
        $this->assertSame($session->description, $event->description);
        local\booking_cancellation::run(static function () use ($DB, $entryid, $course, $actor, $start) {
            $DB->set_field('block_mrbs_entry', 'start_time', $start + 3600, ['id' => $entryid]);
            $DB->set_field('block_mrbs_entry', 'end_time', $start + 7200, ['id' => $entryid]);
            local\attendance_manager::sync_for_entry($entryid, $course->id, $actor->id);
        });
        $event = $DB->get_record('event', ['id' => $session->caleventid], '*', MUST_EXIST);
        $this->assertSame($start + 3600, (int)$event->timestart);
        $this->assertSame(1, $DB->count_records('attendance_sessions', ['attendanceid' => $activity->id]));
    }
}
