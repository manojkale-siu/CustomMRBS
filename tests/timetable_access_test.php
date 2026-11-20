<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Late enrolments see existing whole-course and current-group sessions. */
final class timetable_access_test extends \advanced_testcase {
    public function test_late_enrolment_and_current_groups_control_existing_sessions(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $first = $generator->create_group(['courseid' => $course->id]);
        $other = $generator->create_group(['courseid' => $course->id]);
        $area = $DB->insert_record('block_mrbs_area', (object)['area_name' => 'Area']);
        $room = $DB->insert_record('block_mrbs_room', (object)['area_id' => $area, 'room_name' => 'Room']);
        $start = time() + 3600;
        $ids = [];
        foreach ([0, $first->id, $other->id] as $groupid) {
            $ids[$groupid] = $DB->insert_record('block_mrbs_entry', (object)[
                'room_id' => $room, 'courseid' => $course->id, 'groupid' => $groupid,
                'start_time' => $start, 'end_time' => $start + 1800,
                'create_by' => 'admin', 'name' => 'Existing session',
            ]);
        }
        $this->setUser($student);
        $this->assertSame([], local\timetable_access::entries($start, $start + 1800));
        $generator->enrol_user($student->id, $course->id);
        groups_add_member($first, $student);
        $visible = local\timetable_access::entries($start, $start + 1800);
        $this->assertArrayHasKey($ids[0], $visible);
        $this->assertArrayHasKey($ids[$first->id], $visible);
        $this->assertArrayNotHasKey($ids[$other->id], $visible);
        groups_remove_member($first, $student);
        groups_add_member($other, $student);
        $visible = local\timetable_access::entries($start, $start + 1800);
        $this->assertArrayHasKey($ids[0], $visible);
        $this->assertArrayNotHasKey($ids[$first->id], $visible);
        $this->assertArrayHasKey($ids[$other->id], $visible);
    }
}
