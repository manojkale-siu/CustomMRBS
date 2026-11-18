<?php
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** Programme colours for staff calendars. */
class programme_colour {
    public static function for_course($courseid): string {
        global $DB;
        static $cache = [];
        if (!timetable_access::can_edit() || !$courseid) {
            return '';
        }
        if (!isset($cache[$courseid])) {
            $hierarchy = booking_options::get_course_hierarchy($courseid);
            $value = $DB->get_field('block_mrbs_settings', 'setting_value', [
                'setting_group' => 'programme_colours', 'setting_key' => (string)$hierarchy['programmeid'],
            ]);
            $cache[$courseid] = preg_match('/^#[0-9a-f]{6}$/iD', (string)$value) ? $value : '';
        }
        return $cache[$courseid];
    }

    public static function cell_type($entry): string {
        $colour = self::for_course($entry->courseid);
        return $colour && $entry->type !== 'U' ? 'programme-' . substr($colour, 1) : $entry->type;
    }

    public static function styles(): string {
        global $DB;
        if (!timetable_access::can_edit()) {
            return '';
        }
        $css = '';
        foreach ($DB->get_records('block_mrbs_settings', ['setting_group' => 'programme_colours']) as $record) {
            $colour = $record->setting_value;
            if (!preg_match('/^#[0-9a-f]{6}$/iD', (string)$colour)) {
                continue;
            }
            $brightness = hexdec(substr($colour, 1, 2)) * 0.299
                + hexdec(substr($colour, 3, 2)) * 0.587 + hexdec(substr($colour, 5, 2)) * 0.114;
            $text = $brightness > 150 ? '#111111' : '#ffffff';
            $class = 'programme-' . substr($colour, 1);
            $css .= "#mrbscontainer .$class{background-color:$colour!important;color:$text!important;}"
                . "#mrbscontainer .$class a{color:$text!important;}";
        }
        return $css ? '<style>' . $css . '</style>' : '';
    }
}
