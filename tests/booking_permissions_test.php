<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Room authority cannot be reused for another room in a recurring series. */
final class booking_permissions_test extends \advanced_testcase {
    public function test_mixed_room_deletion_requires_authority_for_every_occurrence(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $role = $generator->create_role();
        $context = \context_system::instance();
        assign_capability('block/mrbs:editunconfirmed', CAP_ALLOW, $role, $context->id);
        role_assign($role, $user->id, $context->id);
        $this->setUser($user);
        $first = $DB->insert_record('block_mrbs_room', (object)[
            'room_name' => 'First', 'room_admin_email' => $user->email]);
        $second = $DB->insert_record('block_mrbs_room', (object)[
            'room_name' => 'Second', 'room_admin_email' => 'another@example.test']);
        $a = (object)['room_id' => $first, 'create_by' => 'another'];
        $b = (object)['room_id' => $second, 'create_by' => 'another'];
        $this->assertTrue(local\booking_permissions::can_write($a));
        $this->assertFalse(local\booking_permissions::can_write($b));
        try {
            local\booking_permissions::require_delete([$a, $b]);
            $this->fail('Room1 authority must not grant Room2 deletion.');
        } catch (\moodle_exception $error) {
            $this->assertSame('bookingdeletedenied', $error->errorcode);
        }
        $b->create_by = $user->username;
        local\booking_permissions::require_delete([$a, $b]);
        $this->setAdminUser();
        $b->create_by = 'another';
        local\booking_permissions::require_delete([$a, $b]);
    }
}
