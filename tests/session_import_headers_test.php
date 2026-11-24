<?php
namespace block_mrbs;

defined('MOODLE_INTERNAL') || die();

/** Renaming the template label must not break previously downloaded workbooks. */
final class session_import_headers_test extends \advanced_testcase {
    public function test_current_and_legacy_description_headers(): void {
        $match = new \ReflectionMethod(local\session_import::class, 'headers_match');
        $headers = ['Date', 'Start Time', 'End Time', 'Area', 'Room', 'Course Short Name',
            'Faculty Username', 'Group', 'Description/Topics'];
        $this->assertTrue($match->invoke(null, $headers));
        $headers[8] = 'Description';
        $this->assertTrue($match->invoke(null, $headers));
        $headers[8] = 'Unknown';
        $this->assertFalse($match->invoke(null, $headers));
        $headers[8] = 'Description/Topics';
        $headers[0] = 'Start Time';
        $this->assertFalse($match->invoke(null, $headers));
        $this->assertFalse($match->invoke(null, array_slice($headers, 0, 8)));
    }
}
