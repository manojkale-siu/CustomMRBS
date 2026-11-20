<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Student conflicts must follow active course membership and inherited roles. */
final class booking_validator_test extends \advanced_testcase {
    public function test_inherited_students_and_group_overlap(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $first = $generator->create_course(['category' => $category->id]);
        $second = $generator->create_course(['category' => $category->id]);
        $student = $generator->create_user();
        $role = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $generator->enrol_user($student->id, $first->id, 0);
        $generator->enrol_user($student->id, $second->id, 0);
        role_assign($role, $student->id, \context_coursecat::instance($category->id)->id);
        $this->assertTrue(local\booking_validator::share_students($first->id, 0, $second->id, 0));
        $group = $generator->create_group(['courseid' => $first->id]);
        $empty = $generator->create_group(['courseid' => $second->id]);
        groups_add_member($group, $student);
        $this->assertTrue(local\booking_validator::share_students($first->id, $group->id, $second->id, 0));
        $this->assertFalse(local\booking_validator::share_students($first->id, $group->id, $second->id, $empty->id));
    }

    public function test_system_student_with_active_and_suspended_enrolments(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $first = $generator->create_course();
        $second = $generator->create_course();
        $inactive = $generator->create_course();
        $student = $generator->create_user();
        $role = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        role_assign($role, $student->id, \context_system::instance()->id);
        $generator->enrol_user($student->id, $first->id, 0);
        $generator->enrol_user($student->id, $second->id, 0);
        $generator->enrol_user($student->id, $inactive->id, 0, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->assertTrue(local\booking_validator::share_students($first->id, 0, $second->id, 0));
        $this->assertFalse(local\booking_validator::share_students($first->id, 0, $inactive->id, 0));
    }
}
