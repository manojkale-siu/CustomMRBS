<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stores custom MRBS time settings.
 *
 * @package   block_mrbs
 */
class settings_manager {

    /**
     * Save default period mode.
     *
     * @return void
     */
    public static function save_default_periods() {
        global $DB;

        self::clear_time_settings();
        $DB->insert_record('block_mrbs_settings', (object)[
            'setting_group' => 'periods_settings',
            'setting_key' => 'enable_periods',
            'setting_value' => '1',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /**
     * Save custom time range.
     *
     * @param string $from
     * @param string $to
     * @param int $interval
     * @return void
     */
    public static function save_time_settings($from, $to, $interval) {
        global $DB;

        self::clear_time_settings();
        $now = time();
        foreach (['from' => $from, 'to' => $to, 'interval' => $interval] as $key => $value) {
            $DB->insert_record('block_mrbs_settings', (object)[
                'setting_group' => 'time_settings',
                'setting_key' => $key,
                'setting_value' => (string)$value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Convert 12-hour input to HH:MM.
     *
     * @param int $hour
     * @param int $minute
     * @param string $ampm
     * @return string
     */
    public static function to_24_hour_time($hour, $minute, $ampm) {
        $hour = (int)$hour;
        $minute = (int)$minute;
        $ampm = strtoupper($ampm) === 'PM' ? 'PM' : 'AM';

        if ($ampm === 'PM' && $hour !== 12) {
            $hour += 12;
        }
        if ($ampm === 'AM' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Clear custom period/time settings.
     *
     * @return void
     */
    private static function clear_time_settings() {
        global $DB;

        $DB->delete_records('block_mrbs_settings', ['setting_group' => 'time_settings']);
        $DB->delete_records('block_mrbs_settings', ['setting_group' => 'periods_settings']);
    }
}
