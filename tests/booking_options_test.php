<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Faculty choices and submitted values share active enrolment validation. */
final class booking_options_test extends \advanced_testcase {
    public function test_inherited_faculty_is_visible_but_disabled_until_actively_enrolled(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $faculty = $generator->create_user();
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        role_assign($role, $faculty->id, \context_system::instance());
        $options = local\booking_options::get_faculties($course->id);
        $this->assertTrue($options[0]['disabled']);
        $this->assertNotEmpty($options[0]['reason']);
        $this->assertFalse(local\booking_options::is_faculty_for_course($faculty->id, $course->id));
        $generator->enrol_user($faculty->id, $course->id, 0);
        $this->assertTrue(local\booking_options::is_faculty_for_course($faculty->id, $course->id));
        $this->assertFalse(local\booking_options::get_faculties($course->id)[0]['disabled']);
        $DB->set_field('user', 'suspended', 1, ['id' => $faculty->id]);
        $this->assertFalse(local\booking_options::is_faculty_for_course($faculty->id, $course->id));
        $this->assertTrue(local\booking_options::get_faculties($course->id)[0]['disabled']);
    }
}
