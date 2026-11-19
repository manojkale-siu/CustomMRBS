<?php
// This file is part of Moodle - https://moodle.org/.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** Sends a notification to the user performing the booking operation. */
class booking_notification {
    public static function send(string $text, \moodle_url $url): bool {
        global $USER;

        try {
            $message = new \core\message\message();
            $message->component = 'block_mrbs';
            $message->name = 'booking_saved';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $USER;
            $message->subject = get_string('bookingnotificationsubject', 'block_mrbs');
            $message->fullmessage = $text;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $text;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('blockname', 'block_mrbs');
            return (bool)message_send($message);
        } catch (\Throwable $error) {
            debugging('MRBS notification failed: ' . $error->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }
}
