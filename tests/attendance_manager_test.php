<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Attendance timing is editable strictly before the session starts. */
final class attendance_manager_test extends \advanced_testcase {
    public function test_future_live_finished_and_past_destination(): void {
        $this->resetAfterTest();
        // An unlinked session ID has no recorded attendance; use a stable explicit clock.
        $session = (object)['id' => -1, 'sessdate' => 1000, 'lasttaken' => 0];
        $this->assertTrue(local\attendance_manager::can_update_session($session, 1200, 999));
        $this->assertFalse(local\attendance_manager::can_update_session($session, 1200, 1000));
        $this->assertFalse(local\attendance_manager::can_update_session($session, 1200, 1050));
        $this->assertFalse(local\attendance_manager::can_update_session($session, 1200, 2000));
        $this->assertFalse(local\attendance_manager::can_update_session($session, 900, 999));
        $session->lasttaken = 1;
        $this->assertFalse(local\attendance_manager::can_update_session($session, 1200, 999));
    }
}
