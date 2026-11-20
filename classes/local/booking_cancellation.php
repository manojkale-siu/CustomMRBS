<?php
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** Atomic cancellation of future bookings and their linked Attendance sessions. */
class booking_cancellation {
    public static function run(callable $operation) {
        global $DB;
        $lock = booking_lock::acquire();
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally {
            $lock->release();
        }
    }

    /** Validate the complete target set before deleting anything. */
    public static function delete_entries(array $entries): int {
        global $DB;
        booking_lock::require_held();
        foreach ($entries as $entry) {
            self::require_future($entry);
        }
        $repeats = [];
        foreach ($entries as $entry) {
            attendance_manager::delete_for_entry($entry->id);
            $DB->delete_records('block_mrbs_entry', ['id' => $entry->id]);
            if ($entry->repeat_id > 0) {
                $repeats[$entry->repeat_id] = $entry->repeat_id;
            }
        }
        foreach ($repeats as $repeatid) {
            if (!$DB->record_exists('block_mrbs_entry', ['repeat_id' => $repeatid])) {
                $DB->delete_records('block_mrbs_repeat', ['id' => $repeatid]);
            }
        }
        return count($entries);
    }

    public static function require_future(\stdClass $entry): void {
        if ((int)$entry->start_time <= time()) {
            throw new \moodle_exception('cancellationnotfuture', 'block_mrbs');
        }
        if (!empty($entry->attendancesessionid)) {
            attendance_manager::require_deletable_session((int)$entry->attendancesessionid);
        }
    }

    public static function delete_room(int $roomid): int {
        global $DB;
        if (!timetable_access::is_admin()) {
            throw new \moodle_exception('accessdenied', 'block_mrbs');
        }
        return self::run(static function () use ($roomid, $DB) {
            $DB->get_record('block_mrbs_room', ['id' => $roomid], '*', MUST_EXIST);
            $count = self::delete_entries($DB->get_records('block_mrbs_entry', ['room_id' => $roomid]));
            // A surviving, individually moved occurrence still needs a valid repeat header room.
            foreach ($DB->get_records('block_mrbs_repeat', ['room_id' => $roomid]) as $repeat) {
                $survivors = $DB->get_records('block_mrbs_entry', ['repeat_id' => $repeat->id], 'start_time, id', 'id, room_id', 0, 1);
                if ($survivors) {
                    $survivor = reset($survivors);
                    $DB->set_field('block_mrbs_repeat', 'room_id', $survivor->room_id, ['id' => $repeat->id]);
                } else {
                    $DB->delete_records('block_mrbs_repeat', ['id' => $repeat->id]);
                }
            }
            $DB->delete_records('block_mrbs_room', ['id' => $roomid]);
            return $count;
        });
    }
}
