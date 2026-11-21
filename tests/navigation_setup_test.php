<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Automatic installation navigation must preserve the administrator's menu. */
final class navigation_setup_test extends \advanced_testcase {
    public function test_empty_menu_and_repeated_registration(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('custommenuitems', '');
        $this->assertTrue(local\navigation_setup::ensure_menu_link());
        $expected = 'MRBS|' . rtrim($CFG->wwwroot, '/')
            . '/blocks/mrbs/web/index.php|Meeting Room Booking System';
        $this->assertSame($expected, get_config(null, 'custommenuitems'));
        $this->assertFalse(local\navigation_setup::ensure_menu_link());
        $this->assertSame($expected, get_config(null, 'custommenuitems'));
    }

    public function test_existing_entries_and_line_endings_are_preserved(): void {
        $this->resetAfterTest();
        $original = "Library|https://library.example.test\r\nSupport|https://support.example.test";
        set_config('custommenuitems', $original);
        local\navigation_setup::ensure_menu_link();
        $this->assertStringStartsWith($original . "\r\nMRBS|", get_config(null, 'custommenuitems'));
    }

    public function test_existing_relative_entry_is_not_duplicated(): void {
        global $CFG;
        $this->resetAfterTest();
        $base = rtrim(parse_url($CFG->wwwroot, PHP_URL_PATH) ?? '', '/');
        $original = 'My rooms|' . $base . '/blocks/mrbs/web/index.php?area=1|Existing link';
        set_config('custommenuitems', $original);
        $this->assertFalse(local\navigation_setup::ensure_menu_link());
        $this->assertSame($original, get_config(null, 'custommenuitems'));
    }

    public function test_subdirectory_installation_uses_correct_site_url(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->wwwroot = 'https://example.test/moodle';
        set_config('custommenuitems', '');
        local\navigation_setup::ensure_menu_link();
        $this->assertStringContainsString('MRBS|https://example.test/moodle/blocks/mrbs/web/index.php|',
            get_config(null, 'custommenuitems'));
        $this->assertFalse(local\navigation_setup::ensure_menu_link());
    }

    public function test_other_site_link_does_not_suppress_local_link(): void {
        $this->resetAfterTest();
        $original = 'Other site|https://other.example.test/blocks/mrbs/web/index.php';
        set_config('custommenuitems', $original);
        $this->assertTrue(local\navigation_setup::ensure_menu_link());
        $this->assertStringStartsWith($original . "\nMRBS|", get_config(null, 'custommenuitems'));
    }

    public function test_config_php_override_is_not_changed(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('custommenuitems', 'Existing|https://example.test');
        $CFG->config_php_settings['custommenuitems'] = 'Existing|https://example.test';
        $this->assertFalse(local\navigation_setup::ensure_menu_link());
        $this->assertSame('Existing|https://example.test', get_config(null, 'custommenuitems'));
    }
}
