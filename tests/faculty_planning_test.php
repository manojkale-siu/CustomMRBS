<?php
namespace block_mrbs;
defined('MOODLE_INTERNAL') || die();

/** Faculty proposals must never grant reservation access or change another faculty's data. */
final class faculty_planning_test extends \advanced_testcase {
    private function setup_faculty(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    public function test_only_enrolled_teacher_can_plan(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->setup_faculty();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->assertTrue(local\faculty_planning::teaches_course($teacher->id, $course->id));
        $this->assertFalse(local\faculty_planning::teaches_course($student->id, $course->id));
        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        local\faculty_planning::save($course->id, [2 => ['2030-01-15', 'Topic', 'Lecture', 0, 'Quiz', '']]);
    }

    public function test_proposal_does_not_create_bookings_and_other_faculty_cannot_edit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->setup_faculty();
        local\faculty_planning::save($course->id, [2 => ['2030-01-15', "Topic one\nTopic two", 'Lecture', 0, 'Quiz', '']]);
        $plans = local\faculty_planning::plans($course->id);
        $this->assertCount(1, $plans);
        $this->assertEquals(0, $DB->count_records('block_mrbs_entry'));
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'editingteacher');
        $this->setUser($other);
        $this->expectException(\moodle_exception::class);
        local\faculty_planning::delete((int)array_key_first($plans), $course->id);
    }

    public function test_student_reads_only_enrolled_course_plans(): void {
        $this->resetAfterTest();
        [$course] = $this->setup_faculty();
        local\faculty_planning::save($course->id, [2 => ['2030-01-15', 'Topic', 'Lecture', 0, 'Quiz', '']]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $othercourse = $this->getDataGenerator()->create_course();
        $this->setUser($student);
        $this->assertCount(1, local\faculty_planning::visible_plans($course->id));
        $this->expectException(\moodle_exception::class);
        local\faculty_planning::visible_plans($othercourse->id);
    }

    public function test_confirmed_attendance_prevents_plan_edit_and_delete(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        [$course] = $this->setup_faculty();
        $row = ['2030-01-15', 'Topic', 'Lecture', 0, 'Quiz', ''];
        local\faculty_planning::save($course->id, [2 => $row]);
        $id = (int)array_key_first(local\faculty_planning::plans($course->id));
        $DB->insert_record('block_mrbs_plan_use', (object)[
            'sessionid' => 123, 'planid' => $id, 'courseid' => $course->id,
            'facultyid' => $USER->id, 'proposeddate' => 0, 'plannedtopics' => 'Topic',
            'taughttopics' => 'Topic', 'selectedby' => $USER->id, 'timecreated' => time(),
            'confirmedat' => time(), 'conducteddate' => time(),
        ]);
        $this->assertTrue(local\attendance_planning::is_locked($id));
        try {
            local\faculty_planning::save($course->id, [2 => $row], $id);
            $this->fail('A confirmed plan must not be editable.');
        } catch (\moodle_exception $e) {
            $this->assertSame('planningtakenlocked', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        local\faculty_planning::delete($id, $course->id);
    }

    public function test_invalid_proposed_date_is_rejected(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        local\faculty_planning::validate_row(['2030-02-30', 'Topic', 'Lecture', 0, 'Quiz', '']);
    }
}
