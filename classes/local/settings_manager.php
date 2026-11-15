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
     * Required keys for a valid custom academic time range.
     */
    private const TIME_SETTING_KEYS = ['from', 'to', 'interval'];

    /**
     * Save default period mode.
     *
     * @return void
     */
    public static function save_default_periods() {
        global $DB;

        self::require_custom_time_settings_editable();

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

        self::require_custom_time_settings_editable();

        if (!self::is_valid_time_value($from) || !self::is_valid_time_value($to) || (int)$interval <= 0 || $from >= $to) {
            throw new \coding_exception('Invalid MRBS custom time range supplied.');
        }

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
     * Return the configured custom academic time settings.
     *
     * @return array
     */
    public static function get_time_settings() {
        global $DB;

        $records = $DB->get_records('block_mrbs_settings', ['setting_group' => 'time_settings']);
        $settings = [];
        foreach ($records as $record) {
            $settings[$record->setting_key] = $record->setting_value;
        }

        return $settings;
    }

    /**
     * Check whether custom academic time ranges are configured and valid.
     *
     * @return bool
     */
    public static function has_valid_custom_time_range() {
        $settings = self::get_time_settings();

        foreach (self::TIME_SETTING_KEYS as $key) {
            if (!array_key_exists($key, $settings)) {
                return false;
            }
        }

        $from = (string)$settings['from'];
        $to = (string)$settings['to'];
        $interval = (int)$settings['interval'];

        return self::is_valid_time_value($from)
            && self::is_valid_time_value($to)
            && $from < $to
            && $interval > 0;
    }

    /**
     * Get the parsed custom period schedule.
     *
     * @return array|null
     */
    public static function get_period_schedule() {
        $settings = self::get_time_settings();
        if (empty($settings['from']) || empty($settings['to']) || empty($settings['interval'])) {
            return null;
        }

        $fromminutes = self::time_to_minutes($settings['from']);
        $tominutes = self::time_to_minutes($settings['to']);
        $interval = (int)$settings['interval'];
        if ($fromminutes === null || $tominutes === null || $interval <= 0 || $fromminutes >= $tominutes) {
            return null;
        }

        $periods = [];
        for ($start = $fromminutes, $index = 0; $start < $tominutes; $start += $interval, $index++) {
            $end = min($start + $interval, $tominutes);
            if ($end <= $start) {
                break;
            }

            $periods[] = [
                'index' => $index,
                'startminutes' => $start,
                'endminutes' => $end,
                'startlabel' => self::minutes_to_time($start),
                'endlabel' => self::minutes_to_time($end),
                'label' => self::minutes_to_time($start) . '&nbsp;-&nbsp;' . self::minutes_to_time($end),
            ];
        }

        if (empty($periods)) {
            return null;
        }

        return [
            'fromminutes' => $fromminutes,
            'tominutes' => $tominutes,
            'interval' => $interval,
            'periods' => $periods,
        ];
    }

    /**
     * Get the number of configured periods.
     *
     * @return int
     */
    public static function get_period_count() {
        $schedule = self::get_period_schedule();
        if (empty($schedule)) {
            return 0;
        }

        return count($schedule['periods']);
    }

    /**
     * Get the configured period interval in seconds.
     *
     * @return int
     */
    public static function get_period_interval_seconds() {
        $schedule = self::get_period_schedule();
        if (empty($schedule)) {
            return 0;
        }

        return (int)$schedule['interval'] * 60;
    }

    /**
     * Convert a configured period index to a timestamp.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $periodindex
     * @return int
     */
    public static function get_period_start_timestamp($year, $month, $day, $periodindex) {
        $schedule = self::get_period_schedule();
        if (empty($schedule)) {
            return mktime(12, (int)$periodindex, 0, (int)$month, (int)$day, (int)$year);
        }

        $periodindex = max(0, min((int)$periodindex, count($schedule['periods']) - 1));
        $minutes = $schedule['fromminutes'] + ($periodindex * $schedule['interval']);
        $hour = (int)floor($minutes / 60);
        $minute = (int)($minutes % 60);

        return mktime($hour, $minute, 0, (int)$month, (int)$day, (int)$year);
    }

    /**
     * Convert a configured period index and duration to an end timestamp.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $periodindex
     * @param int $durationperiods
     * @return int
     */
    public static function get_period_end_timestamp($year, $month, $day, $periodindex, $durationperiods = 1) {
        $schedule = self::get_period_schedule();
        if (empty($schedule)) {
            return mktime(12, (int)$periodindex, 0, (int)$month, (int)$day, (int)$year) + ((int)$durationperiods * 60);
        }

        $start = self::get_period_start_timestamp($year, $month, $day, $periodindex);
        return $start + ((int)$durationperiods * $schedule['interval'] * 60);
    }

    /**
     * Get the start and end timestamps for a full booking day in period mode.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @return array{0:int,1:int}
     */
    public static function get_period_day_bounds($year, $month, $day) {
        $schedule = self::get_period_schedule();
        if (empty($schedule)) {
            return [
                mktime(12, 0, 0, (int)$month, (int)$day, (int)$year),
                mktime(12, 1, 0, (int)$month, (int)$day, (int)$year),
            ];
        }

        $startminutes = $schedule['fromminutes'];
        $endminutes = $schedule['tominutes'];

        $starthour = (int)floor($startminutes / 60);
        $startminute = (int)($startminutes % 60);
        $endhour = (int)floor($endminutes / 60);
        $endminute = (int)($endminutes % 60);

        return [
            mktime($starthour, $startminute, 0, (int)$month, (int)$day, (int)$year),
            mktime($endhour, $endminute, 0, (int)$month, (int)$day, (int)$year),
        ];
    }

    /**
     * Convert a timestamp to the matching configured period index.
     *
     * @param int $timestamp
     * @return int
     */
    public static function get_period_index_from_timestamp($timestamp) {
        $schedule = self::get_period_schedule();
        if (empty($schedule)) {
            return (int)date('i', (int)$timestamp);
        }

        $minutes = ((int)date('G', (int)$timestamp) * 60) + (int)date('i', (int)$timestamp);
        $index = (int)floor(($minutes - $schedule['fromminutes']) / $schedule['interval']);
        if ($minutes <= $schedule['fromminutes']) {
            $index = 0;
        }

        $maxindex = count($schedule['periods']) - 1;
        if ($index < 0) {
            $index = 0;
        } else if ($index > $maxindex) {
            $index = $maxindex;
        }

        return $index;
    }

    /**
     * Get the configured period label for a timestamp.
     *
     * @param int $timestamp
     * @param int $offset
     * @return string
     */
    public static function get_period_label_from_timestamp($timestamp, $offset = 0) {
        $schedule = self::get_period_schedule();
        if (empty($schedule)) {
            return '';
        }

        $index = self::get_period_index_from_timestamp($timestamp) + (int)$offset;
        if ($index < 0) {
            $index = 0;
        }

        $maxindex = count($schedule['periods']) - 1;
        if ($index > $maxindex) {
            $index = $maxindex;
        }

        return $schedule['periods'][$index]['label'];
    }

    /**
     * Validate an HH:MM time value.
     *
     * @param string $value
     * @return bool
     */
    private static function is_valid_time_value($value) {
        return is_string($value) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value);
    }

    /**
     * Convert HH:MM to minutes after midnight.
     *
     * @param string $value
     * @return int|null
     */
    private static function time_to_minutes($value) {
        if (!self::is_valid_time_value($value)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $value, 2));
        return ($hour * 60) + $minute;
    }

    /**
     * Convert minutes after midnight to HH:MM.
     *
     * @param int $minutes
     * @return string
     */
    private static function minutes_to_time($minutes) {
        $minutes = (int)$minutes;
        $hour = (int)floor($minutes / 60);
        $minute = $minutes % 60;
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

    /**
     * Prevent changing timetable settings after a valid custom range has been saved.
     *
     * @return void
     */
    private static function require_custom_time_settings_editable() {
        if (self::has_valid_custom_time_range()) {
            throw new \moodle_exception('customtiminglocked', 'block_mrbs');
        }
    }
}
