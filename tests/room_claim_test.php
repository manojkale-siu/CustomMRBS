<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** A stale form must lose to an already committed room booking. */
final class room_claim_test extends \advanced_testcase {
    public function test_availability_is_rechecked_after_first_save(): void {
        global $DB;
        $this->resetAfterTest();
        $room = $DB->insert_record('block_mrbs_room', (object)['room_name' => 'Room']);
        $start = time() + 3600;
        $lock = local\booking_lock::acquire();
        try {
            local\booking_validator::require_room_available($room, $start, $start + 1800);
            $id = $DB->insert_record('block_mrbs_entry', (object)[
                'room_id' => $room, 'start_time' => $start, 'end_time' => $start + 1800,
                'create_by' => 'firstmanager', 'name' => 'First booking',
            ]);
            local\booking_validator::require_room_available($room, $start, $start + 1800, $id);
            local\booking_validator::require_room_available($room, $start + 1800, $start + 3600);
            try {
                local\booking_validator::require_room_available($room, $start, $start + 1800);
                $this->fail('The second request must not claim the occupied room.');
            } catch (\moodle_exception $error) {
                $this->assertSame('roomalreadyclaimed', $error->errorcode);
            }
            $this->assertSame(1, $DB->count_records('block_mrbs_entry', ['room_id' => $room]));
        } finally {
            $lock->release();
        }
    }
}
