<?php
// This file is part of the MRBS block for Moodle.
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

define('CLI_SCRIPT', true);

/**
 * Find the Moodle config.php by walking up the directory tree.
 *
 * @return string
 */
function block_mrbs_find_config_path() {
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir . DIRECTORY_SEPARATOR . 'config.php';
        if (file_exists($candidate)) {
            return $candidate;
        }
        $dir = dirname($dir);
    }

    throw new \runtime_exception('Unable to locate Moodle config.php.');
}

require_once(block_mrbs_find_config_path());
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../web/config.inc.php');
require_once(__DIR__ . '/../classes/local/settings_manager.php');
require_once(__DIR__ . '/../classes/local/attendance_manager.php');

global $DB, $CFG;

/**
 * Parse CLI options.
 *
 * @param array $argv
 * @return array
 */
function block_mrbs_parse_cli_options(array $argv) {
    $options = [
        'dryrun' => false,
        'entryid' => 0,
        'limit' => 0,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dryrun'] = true;
            continue;
        }

        if (strpos($arg, '--entryid=') === 0) {
            $options['entryid'] = max(0, (int)substr($arg, 10));
            continue;
        }

        if (strpos($arg, '--limit=') === 0) {
            $options['limit'] = max(0, (int)substr($arg, 8));
            continue;
        }
    }

    return $options;
}

/**
 * Return the configured period index from a legacy broken timestamp.
 *
 * The broken storage path used mktime(12, <period index>, ...), so the minute
 * component is the index we need to map back to the real schedule.
 *
 * @param int $timestamp
 * @return int|null
 */
function block_mrbs_get_legacy_period_index($timestamp) {
    $hour = (int)\block_mrbs\local\booking_time::date('H', $timestamp);
    $minute = (int)\block_mrbs\local\booking_time::date('i', $timestamp);

    if ($hour !== 12) {
        return null;
    }

    $schedule = \block_mrbs\local\settings_manager::get_period_schedule();
    if (empty($schedule)) {
        return null;
    }

    if ($minute < 0 || $minute >= count($schedule['periods'])) {
        return null;
    }

    return $minute;
}

$options = block_mrbs_parse_cli_options($argv);

if (!\block_mrbs\local\settings_manager::has_valid_custom_time_range()) {
    mtrace('No valid custom time range is configured. Nothing to repair.');
    exit(0);
}

$conditions = ['end_time = start_time + 60'];
$params = [];
if (!empty($options['entryid'])) {
    $conditions[] = 'id = ?';
    $params[] = (int)$options['entryid'];
}

// Acquire before reading candidates so no booking writer can race the repair.
$bookinglock = \block_mrbs\local\booking_lock::acquire();
register_shutdown_function([$bookinglock, 'release']);

$sql = 'SELECT * FROM {block_mrbs_entry} WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC';
$records = $DB->get_records_sql($sql, $params, 0, $options['limit'] > 0 ? $options['limit'] : 0);

if (empty($records)) {
    mtrace('No matching legacy one-minute entries were found.');
    exit(0);
}

$fixed = 0;
$skipped = 0;

foreach ($records as $entry) {
    $legacyperiod = block_mrbs_get_legacy_period_index((int)$entry->start_time);
    if ($legacyperiod === null) {
        $skipped++;
        mtrace('Skipping entry ' . $entry->id . ' because it does not match the legacy 12:<period> pattern.');
        continue;
    }

    $year = (int)\block_mrbs\local\booking_time::date('Y', (int)$entry->start_time);
    $month = (int)\block_mrbs\local\booking_time::date('m', (int)$entry->start_time);
    $day = (int)\block_mrbs\local\booking_time::date('d', (int)$entry->start_time);
    $newstart = \block_mrbs\local\settings_manager::get_period_start_timestamp($year, $month, $day, $legacyperiod);
    $newend = \block_mrbs\local\settings_manager::get_period_end_timestamp($year, $month, $day, $legacyperiod, 1);

    if ($newstart === (int)$entry->start_time && $newend === (int)$entry->end_time) {
        $skipped++;
        mtrace('Skipping entry ' . $entry->id . ' because it already matches the expected timestamps.');
        continue;
    }

    if ($options['dryrun']) {
        mtrace('Would update entry ' . $entry->id . ' from ' . \block_mrbs\local\booking_time::userdate((int)$entry->start_time, '%Y-%m-%d %H:%M:%S')
            . ' - ' . \block_mrbs\local\booking_time::userdate((int)$entry->end_time, '%Y-%m-%d %H:%M:%S')
            . ' to ' . \block_mrbs\local\booking_time::userdate($newstart, '%Y-%m-%d %H:%M:%S')
            . ' - ' . \block_mrbs\local\booking_time::userdate($newend, '%Y-%m-%d %H:%M:%S'));
        continue;
    }

    $transaction = $DB->start_delegated_transaction();
    try {
        \block_mrbs\local\booking_lock::require_held();
        \block_mrbs\local\booking_validator::validate_time_range($newstart, $newend, true);
        if (\block_mrbs\local\booking_validator::find_participant_conflicts(
                $entry->courseid, $entry->groupid, $entry->facultyid, $newstart, $newend, $entry->id)) {
            throw new moodle_exception('participantbookingconflict', 'block_mrbs');
        }
        if ($DB->record_exists_select('block_mrbs_entry',
                'room_id = ? AND id <> ? AND start_time < ? AND end_time > ?',
                [$entry->room_id, $entry->id, $newend, $newstart])) {
            throw new moodle_exception('sessionimportroomconflict', 'block_mrbs');
        }
        $DB->set_field('block_mrbs_entry', 'start_time', $newstart, ['id' => (int)$entry->id]);
        $DB->set_field('block_mrbs_entry', 'end_time', $newend, ['id' => (int)$entry->id]);
        \block_mrbs\local\attendance_manager::sync_for_entry(
            (int)$entry->id, (int)$entry->courseid, (int)$entry->facultyid, (int)$entry->groupid
        );
        $transaction->allow_commit();
    } catch (\Throwable $error) {
        try {
            $transaction->rollback($error);
        } catch (\Throwable $rolledback) {
            mtrace('Entry ' . $entry->id . ' was not repaired: ' . $rolledback->getMessage());
            $skipped++;
            continue;
        }
    }


    mtrace('Updated entry ' . $entry->id . ' to ' . \block_mrbs\local\booking_time::userdate($newstart, '%Y-%m-%d %H:%M:%S')
        . ' - ' . \block_mrbs\local\booking_time::userdate($newend, '%Y-%m-%d %H:%M:%S'));
    $fixed++;
}

mtrace('Repair complete. Fixed: ' . $fixed . ' Skipped: ' . $skipped . '.');
