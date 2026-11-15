<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Centralised checks required before MRBS booking workflows can run.
 *
 * @package   block_mrbs
 */
class booking_prerequisites {

    /**
     * Resolve the selected area, falling back to the first configured area.
     *
     * @param int $areaid
     * @return int
     */
    public static function resolve_area($areaid = 0) {
        global $DB;

        $areaid = (int)$areaid;
        if ($areaid && $DB->record_exists('block_mrbs_area', ['id' => $areaid])) {
            return $areaid;
        }

        $areas = $DB->get_records('block_mrbs_area', null, 'area_name ASC', 'id', 0, 1);
        if (empty($areas)) {
            return 0;
        }

        $area = reset($areas);
        return (int)$area->id;
    }

    /**
     * Resolve the selected room, falling back to the first room in the area.
     *
     * @param int $areaid
     * @param int $roomid
     * @return int
     */
    public static function resolve_room($areaid, $roomid = 0) {
        global $DB;

        $areaid = (int)$areaid;
        $roomid = (int)$roomid;
        if ($roomid && $DB->record_exists('block_mrbs_room', ['id' => $roomid, 'area_id' => $areaid])) {
            return $roomid;
        }

        $rooms = $DB->get_records('block_mrbs_room', ['area_id' => $areaid], 'room_name ASC', 'id', 0, 1);
        if (empty($rooms)) {
            return 0;
        }

        $room = reset($rooms);
        return (int)$room->id;
    }

    /**
     * Validate prerequisites for viewing or creating bookings.
     *
     * @param int $areaid
     * @param int $roomid
     * @param bool $requireroom
     * @param bool $requiretime
     * @return \stdClass areaid, roomid and errors.
     */
    public static function validate($areaid = 0, $roomid = 0, $requireroom = false, $requiretime = false) {
        global $DB;

        $result = (object)[
            'areaid' => 0,
            'roomid' => 0,
            'errors' => [],
        ];

        if (!$DB->count_records('block_mrbs_area')) {
            $result->errors[] = get_string('mrbsnoareasconfigured', 'block_mrbs');
            return $result;
        }

        $areaid = (int)$areaid;
        if ($areaid && !$DB->record_exists('block_mrbs_area', ['id' => $areaid])) {
            $result->errors[] = get_string('mrbsinvalidarea', 'block_mrbs');
            return $result;
        }

        $result->areaid = self::resolve_area($areaid);

        if (!$DB->record_exists('block_mrbs_room', ['area_id' => $result->areaid])) {
            $result->errors[] = get_string('mrbsnoroomsinarea', 'block_mrbs');
            return $result;
        }

        $roomid = (int)$roomid;
        if ($roomid && !$DB->record_exists('block_mrbs_room', ['id' => $roomid, 'area_id' => $result->areaid])) {
            $result->errors[] = get_string('mrbsinvalidroom', 'block_mrbs');
            return $result;
        }

        if ($requireroom) {
            $result->roomid = self::resolve_room($result->areaid, $roomid);
            if (!$result->roomid) {
                $result->errors[] = get_string('mrbsnoroomsinarea', 'block_mrbs');
            }
        }

        if ($requiretime && !settings_manager::has_valid_custom_time_range()) {
            $result->errors[] = get_string('mrbscustomtimerangerequired', 'block_mrbs');
        }

        return $result;
    }

    /**
     * Check whether booking creation is currently allowed.
     *
     * @return bool
     */
    public static function can_create_bookings() {
        global $DB;

        return settings_manager::has_valid_custom_time_range()
            && $DB->count_records('block_mrbs_area') > 0
            && $DB->count_records('block_mrbs_room') > 0;
    }
}
