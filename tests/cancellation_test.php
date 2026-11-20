<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Future-only cancellation and rollback of the complete deletion set. */
final class cancellation_test extends \advanced_testcase {
    private function entry(int $start): \stdClass {
        global $DB;
        $entry = (object)['start_time' => $start, 'end_time' => $start + 1800,
            'room_id' => 1, 'create_by' => 'admin', 'name' => 'Test', 'repeat_id' => 0];
        $entry->id = $DB->insert_record('block_mrbs_entry', $entry);
        return $entry;
    }

    public function test_mixed_future_and_live_set_deletes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $future = $this->entry(time() + 3600);
        $live = $this->entry(time());
        try {
            local\booking_cancellation::run(static function () use ($future, $live) {
                return local\booking_cancellation::delete_entries([$future, $live]);
            });
            $this->fail('Live session must stop the complete cancellation.');
        } catch (\moodle_exception $error) {
            $this->assertSame('cancellationnotfuture', $error->errorcode);
        }
        $this->assertTrue($DB->record_exists('block_mrbs_entry', ['id' => $future->id]));
        $this->assertTrue($DB->record_exists('block_mrbs_entry', ['id' => $live->id]));
    }

    public function test_failure_after_deletion_rolls_back(): void {
        global $DB;
        $this->resetAfterTest();
        $future = $this->entry(time() + 3600);
        try {
            local\booking_cancellation::run(static function () use ($future) {
                local\booking_cancellation::delete_entries([$future]);
                throw new \moodle_exception('sessioncreatefailed', 'block_mrbs');
            });
            $this->fail('Injected failure must propagate.');
        } catch (\moodle_exception $error) {
            $this->assertSame('sessioncreatefailed', $error->errorcode);
        }
        $this->assertTrue($DB->record_exists('block_mrbs_entry', ['id' => $future->id]));
    }
}
