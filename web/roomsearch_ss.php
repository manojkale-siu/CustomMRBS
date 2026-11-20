<?php

// This file is part of the MRBS block for Moodle
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
global $DB;
include "config.inc.php";
include "functions.php";
require_once('mrbs_auth.php');
include "mrbs_sql.php";

require_login();
\block_mrbs\local\timetable_access::require_calendar();
$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$period = optional_param('period', 0, PARAM_INT);
$duration = max(1, optional_param('duration', 1, PARAM_INT));
$unitsallowed = $enable_periods ? ['periods', 'days'] : ['minutes', 'hours', 'days', 'weeks'];
$dur_units = optional_param('dur_units', $unitsallowed[0], PARAM_ALPHA);
$all_day = optional_param('all_day', false, PARAM_BOOL);
$mincap = optional_param('mincap', 0, PARAM_INT);
$hour = optional_param('hour', null, PARAM_INT);
$minute = optional_param('minute', null, PARAM_INT);
$ampm = optional_param('ampm', null, PARAM_ALPHA);

if ($year < 1970 || $year > 9999 || !checkdate($month, $day, $year) || !in_array($dur_units, $unitsallowed, true)) {
    http_response_code(400);
    exit;
}

if ($enable_periods) {
    $schedulevalid = \block_mrbs\local\settings_manager::has_valid_custom_time_range();
    $resolution = $schedulevalid ? \block_mrbs\local\settings_manager::get_period_interval_seconds() : 60;
    $max_periods = $schedulevalid ? \block_mrbs\local\settings_manager::get_period_count() : count($periods);
}

// Units start in seconds
$units = 1.0;

switch ($dur_units) {
    case "years":
        $units *= 52;
    case "weeks":
        $units *= 7;
    case "days":
        $units *= 24;
    case "hours":
        $units *= 60;
    case "periods":
    case "minutes":
        $units *= 60;
    case "seconds":
        break;
}

// Units are now in "$dur_units" numbers of seconds

if ($all_day) {
    if ($enable_periods) {
        if ($schedulevalid) {
            [$starttime, $endtime] = \block_mrbs\local\settings_manager::get_period_day_bounds($year, $month, $day);
        } else {
            $starttime = \block_mrbs\local\booking_time::mktime(12, 0, 0, $month, $day, $year);
            $endtime = \block_mrbs\local\booking_time::mktime(12, $max_periods, 0, $month, $day, $year);
        }
    } else {
        $starttime = \block_mrbs\local\booking_time::mktime($morningstarts, 0, 0, $month, $day, $year);
        $end_minutes = $eveningends_minutes + $morningstarts_minutes;
        ($eveningends_minutes > 59) ? $end_minutes += 60 : '';
        $endtime = \block_mrbs\local\booking_time::mktime($eveningends, $end_minutes, 0, $month, $day, $year);
    }
} else {
    if ($enable_periods && $schedulevalid) {
        $periodindex = max(0, (int)$period);
        if ($periodindex >= $max_periods) {
            $periodindex = $max_periods - 1;
        }

        $starttime = \block_mrbs\local\settings_manager::get_period_start_timestamp($year, $month, $day, $periodindex);
        if ($dur_units == "periods") {
            $periodduration = max(1, (int)round($duration));
            $endtime = $starttime + ($periodduration * $resolution);
        } else if ($dur_units == "days") {
            $endtime = $starttime + (max(1, (int)round($duration)) * 86400);
        } else {
            $endtime = $starttime + ($units * $duration);
            $diff = $endtime - $starttime;
            if (($tmp = $diff % $resolution) != 0 || $diff == 0) {
                $endtime += $resolution - $tmp;
            }
        }

        $endtime += cross_dst($starttime, $endtime);
    } else {
    if (!$twentyfourhour_format) {
        if (isset($ampm) && ($ampm == "pm") && ($hour < 12)) {
            $hour += 12;
        }
        if (isset($ampm) && ($ampm == "am") && ($hour > 11)) {
            $hour -= 12;
        }
    }

    $starttime = \block_mrbs\local\booking_time::mktime($hour, $minute, 0, $month, $day, $year);
    $endtime = \block_mrbs\local\booking_time::mktime($hour, $minute, 0, $month, $day, $year) + ($units * $duration);
    // Round up the duration to the next whole resolution unit.
    // If they asked for 0 minutes, push that up to 1 resolution unit.
    $diff = $endtime - $starttime;
    if (($tmp = $diff % $resolution) != 0 || $diff == 0) {
        $endtime += $resolution - $tmp;
    }

    $endtime += cross_dst($starttime, $endtime);
    }
}

$sql = "SELECT r.id, r.room_name, r.description, r.capacity, a.area_name,
               CASE WHEN EXISTS (
                   SELECT 1 FROM {block_mrbs_entry} e
                    WHERE e.room_id = r.id AND e.start_time < ? AND e.end_time > ?
               ) THEN 1 ELSE 0 END AS booked
          FROM {block_mrbs_room} r
          JOIN {block_mrbs_area} a ON a.id = r.area_id
         WHERE r.capacity >= ?";
$params = [$endtime, $starttime, max(0, $mincap)];

$sql .= " ORDER BY a.area_name, r.room_name";

$results = [];
foreach ($DB->get_records_sql($sql, $params) as $room) {
    $url = new moodle_url('/blocks/mrbs/web/edit_entry.php', [
        'room' => $room->id,
        'period' => $enable_periods && !$all_day && isset($periodindex) ? $periodindex : $period,
        'year' => $year,
        'month' => $month,
        'day' => $day,
        'hour' => $hour,
        'minute' => $minute,
        'all_day' => (int)$all_day,
        'searchduration' => $duration,
        'searchunits' => $dur_units,
    ]);
    $results[] = [
        'area' => $room->area_name,
        'name' => $room->room_name,
        'description' => $room->description,
        'capacity' => $room->capacity,
        'booked' => (bool)$room->booked,
        'url' => $room->booked ? null : $url->out(false),
    ];
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'start' => \block_mrbs\local\booking_time::userdate($starttime),
    'end' => \block_mrbs\local\booking_time::userdate($endtime),
    'rooms' => $results,
]);
