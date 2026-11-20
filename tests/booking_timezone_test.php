<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Every MRBS input and display uses the site timezone without shifting stored instants. */
final class booking_timezone_test extends \advanced_testcase {
    public function test_site_timezone_overrides_server_user_and_forced_display_zones(): void {
        global $USER;
        $this->resetAfterTest();
        set_config('timezone', 'Asia/Kolkata');
        set_config('forcetimezone', 'America/New_York');
        $USER->timezone = 'Pacific/Honolulu';
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $first = local\booking_time::mktime(0, 15, 0, 11, 20, 2026);
            date_default_timezone_set('America/Los_Angeles');
            $this->assertSame($first, local\booking_time::mktime(0, 15, 0, 11, 20, 2026));
            $this->assertSame('2026-11-20 00:15', local\booking_time::date('Y-m-d H:i', $first));
            $this->assertSame('2026-11-20 00:15',
                local\booking_time::userdate($first, '%Y-%m-%d %H:%M', false, false));
            $this->assertSame('America/Los_Angeles', date_default_timezone_get());
            $this->assertSame('Pacific/Honolulu', $USER->timezone);
            $timestamp = new \ReflectionMethod(local\session_import::class, 'timestamp');
            $this->assertSame($first, $timestamp->invoke(null, '00:15', ['', 2026, 11, 20]));
            local\settings_manager::save_time_settings('08:00', '18:00', 30);
            [$open, $close] = local\settings_manager::get_period_day_bounds(2026, 11, 20);
            $this->assertSame('08:00', local\booking_time::date('H:i', $open));
            $this->assertSame('18:00', local\booking_time::date('H:i', $close));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_daily_recurrence_preserves_wall_time_across_dst(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('timezone', 'Europe/London');
        require_once($CFG->dirroot . '/blocks/mrbs/web/mrbs_sql.php');
        $first = local\booking_time::mktime(9, 0, 0, 3, 28, 2026);
        $last = local\booking_time::mktime(23, 59, 0, 3, 30, 2026);
        $reps = mrbsGetRepeatEntryList($first, $last, 1, '', 10, 0);
        $this->assertCount(3, $reps);
        $this->assertSame(23 * 3600, $reps[1] - $reps[0]);
        foreach ($reps as $start) {
            $this->assertSame('09:00', local\booking_time::date('H:i', $start));
            $end = local\booking_time::occurrence_end($first, $first + 3600, $start);
            $this->assertSame('10:00', local\booking_time::date('H:i', $end));
        }
    }

    public function test_half_hour_dst_change_preserves_session_length_in_wall_time(): void {
        $this->resetAfterTest();
        set_config('timezone', 'Australia/Lord_Howe');
        $base = local\booking_time::mktime(1, 30, 0, 10, 3, 2026);
        $next = local\booking_time::mktime(1, 30, 0, 10, 4, 2026);
        $end = local\booking_time::occurrence_end($base, $base + 3600, $next);
        $this->assertSame('02:30', local\booking_time::date('H:i', $end));
        $this->assertSame(1800, $end - $next);
        $this->assertSame(-1800, local\booking_time::offset_change($next, $end));
    }
}
