<?php
namespace block_mrbs;
defined('MOODLE_INTERNAL') || die();

/** Report eligibility follows all three profile fields and Moodle account state. */
final class director_access_test extends \advanced_testcase {
    public function test_director_profile_and_account_must_both_be_active(): void {
        global $DB;
        $this->resetAfterTest();
        $categoryid = $DB->insert_record('user_info_category', (object)['name' => 'MRBS test', 'sortorder' => 1]);
        $user = $this->getDataGenerator()->create_user();
        foreach (['user_category' => 'Staff', 'designation' => 'Director Academics', 'user_status' => 'Active'] as $name => $value) {
            $fieldid = $DB->insert_record('user_info_field', (object)['shortname' => $name, 'name' => $name,
                'datatype' => 'text', 'categoryid' => $categoryid, 'sortorder' => 1]);
            $DB->insert_record('user_info_data', (object)['userid' => $user->id, 'fieldid' => $fieldid, 'data' => $value]);
        }
        $this->assertTrue(local\director_access::is_director($user->id));
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertFalse(local\director_access::is_director($user->id));
        $this->setAdminUser();
        $this->assertFalse(local\director_access::is_director());
    }

    public function test_topic_comparison_normalizes_spacing_only(): void {
        $this->assertFalse(local\deviation_report::topics_differ("Topic one\nTopic two", 'Topic one Topic two'));
        $this->assertTrue(local\deviation_report::topics_differ('Topic one', 'Topic two'));
    }
}
