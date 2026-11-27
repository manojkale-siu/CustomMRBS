<?php
namespace block_mrbs;
defined('MOODLE_INTERNAL') || die();

/** Attendance becomes immutable after its first successful marking. */
final class attendance_planning_test extends \basic_testcase {
    public static function recorded_states(): array {
        return [[123, false, 0], [0, true, 0], [0, false, 123]];
    }

    /** @dataProvider recorded_states */
    public function test_recorded_attendance_never_calls_writer(int $lasttaken, bool $haslogs, int $confirmedat): void {
        global $DB;
        $originaldb = $DB;
        $db = $this->createMock(\moodle_database::class);
        $db->method('get_record')->willReturn((object)['confirmedat' => $confirmedat]);
        $db->method('get_record_sql')->willReturn((object)['id' => 7, 'lasttaken' => $lasttaken]);
        $db->method('record_exists')->willReturn($haslogs);
        $DB = $db;
        $called = false;
        try {
            local\attendance_planning::record(7, static function () use (&$called) { $called = true; });
            $this->fail('Recorded attendance was allowed to change.');
        } catch (\moodle_exception $e) {
            $this->assertSame('planningattendancelocked', $e->errorcode);
            $this->assertFalse($called);
        } finally {
            $DB = $originaldb;
        }
    }
}
