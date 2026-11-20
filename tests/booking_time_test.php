<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** All input paths must respect the configured daily booking boundaries. */
final class booking_time_test extends \advanced_testcase {
    public function test_exact_close_is_valid_and_overflow_is_rejected(): void {
        $this->resetAfterTest();
        local\settings_manager::save_time_settings('08:00', '18:00', 30);
        $start = mktime(17, 30, 0, 11, 20, 2030);
        local\booking_validator::validate_time_range($start, $start + 1800, true);
        $this->expectException(\moodle_exception::class);
        local\booking_validator::validate_time_range($start, $start + 3600, true);
    }

    public function test_before_open_is_rejected_in_clock_mode_too(): void {
        $this->resetAfterTest();
        local\settings_manager::save_time_settings('08:00', '18:00', 30);
        $start = mktime(7, 30, 0, 11, 20, 2030);
        $this->expectException(\moodle_exception::class);
        local\booking_validator::validate_time_range($start, $start + 3600, false);
    }

    public function test_multiday_session_is_rejected(): void {
        $this->resetAfterTest();
        local\settings_manager::save_time_settings('08:00', '18:00', 30);
        $start = mktime(8, 0, 0, 11, 20, 2030);
        $this->expectException(\moodle_exception::class);
        local\booking_validator::validate_time_range($start, $start + 86400, true);
    }
}
