<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Capacity reflects current active enrolments using Moodle APIs. */
final class room_capacity_test extends \advanced_testcase {
    public function test_active_course_and_group_counts(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $group = $generator->create_group(['courseid' => $course->id]);
        $active = $generator->create_user();
        $another = $generator->create_user();
        $expired = $generator->create_user();
        $future = $generator->create_user();
        $suspended = $generator->create_user(['suspended' => 1]);
        $generator->enrol_user($active->id, $course->id);
        $generator->enrol_user($another->id, $course->id);
        $generator->enrol_user($expired->id, $course->id, null, 'manual', time() - 7200, time() - 3600);
        $generator->enrol_user($future->id, $course->id, null, 'manual', time() + 3600);
        $generator->enrol_user($suspended->id, $course->id);
        groups_add_member($group, $active);
        $this->assertSame(2, local\booking_validator::count_participants($course->id));
        $this->assertSame(1, local\booking_validator::count_participants($course->id, $group->id));
        groups_add_member($group, $another);
        $this->assertSame(2, local\booking_validator::count_participants($course->id, $group->id));
    }
}
