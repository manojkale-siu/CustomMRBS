<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Nested writers must share a single lock and reject unguarded writes. */
final class booking_lock_test extends \advanced_testcase {
    public function test_nested_holders_keep_lock_until_last_release(): void {
        $outer = local\booking_lock::acquire();
        $inner = local\booking_lock::acquire();
        $inner->release();
        local\booking_lock::require_held();
        $inner->release();
        local\booking_lock::require_held();
        $outer->release();
        $this->expectException(\moodle_exception::class);
        local\booking_lock::require_held();
    }
}
