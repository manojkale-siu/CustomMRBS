<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validation helpers for MRBS areas and rooms.
 *
 * @package   block_mrbs
 */
class area_room_validator {

    /**
     * Clean and normalise a submitted area or room name.
     *
     * @param string $name
     * @param int $maxlength
     * @return string
     */
    public static function clean_name($name, $maxlength) {
        $name = clean_param((string)$name, PARAM_TEXT);
        $name = preg_replace('/\s+/', ' ', trim($name));

        return \core_text::substr($name, 0, $maxlength);
    }

    /**
     * Validate an area name.
     *
     * @param string $name
     * @param int $excludeid
     * @return string empty when valid, otherwise a localized error.
     */
    public static function validate_area_name($name, $excludeid = 0) {
        global $DB;

        $name = self::clean_name($name, 30);
        if ($name === '') {
            return get_string('areanamerequired', 'block_mrbs');
        }

        $params = ['name' => \core_text::strtolower($name)];
        $sql = 'LOWER(area_name) = :name';
        if ($excludeid) {
            $sql .= ' AND id <> :excludeid';
            $params['excludeid'] = (int)$excludeid;
        }

        if ($DB->record_exists_select('block_mrbs_area', $sql, $params)) {
            return get_string('duplicatearea', 'block_mrbs');
        }

        return '';
    }

    /**
     * Validate a room name within an area.
     *
     * @param string $name
     * @param int $areaid
     * @param int $excludeid
     * @return string empty when valid, otherwise a localized error.
     */
    public static function validate_room_name($name, $areaid, $excludeid = 0) {
        global $DB;

        $name = self::clean_name($name, 25);
        if ($name === '') {
            return get_string('roomnamerequired', 'block_mrbs');
        }

        if (!$DB->record_exists('block_mrbs_area', ['id' => (int)$areaid])) {
            return get_string('invalidarea', 'block_mrbs');
        }

        $params = [
            'name' => \core_text::strtolower($name),
            'areaid' => (int)$areaid,
        ];
        $sql = 'LOWER(room_name) = :name AND area_id = :areaid';
        if ($excludeid) {
            $sql .= ' AND id <> :excludeid';
            $params['excludeid'] = (int)$excludeid;
        }

        if ($DB->record_exists_select('block_mrbs_room', $sql, $params)) {
            return get_string('duplicateroom', 'block_mrbs');
        }

        return '';
    }
}
