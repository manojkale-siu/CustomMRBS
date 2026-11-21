<?php

// This file is part of the MRBS block for Moodle
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

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
global $USER;

// Use a normal text field: the send handler accepts PARAM_TEXT, not editor HTML.
if (\block_mrbs\local\timetable_access::can_edit() && !empty($booking->userid)
        && (int)$booking->userid !== (int)$USER->id) {
    $messagelang = (object)[
        'user' => fullname($USER),
        'description' => trim((string)$booking->description) !== ''
            ? $booking->description : strip_tags(\block_mrbs\local\reservation::get_display_name($booking)),
        'room' => $booking->room_name,
        'datetime' => html_to_text($start_date),
    ];
    $message = get_string('requestvacatemessage', 'block_mrbs', $messagelang);
    $sendurl = new moodle_url('/blocks/mrbs/web/request_vacate_send.php');
    echo html_writer::start_tag('details', ['class' => 'card card-body my-3']);
    echo html_writer::tag('summary', get_string('requestvacate', 'block_mrbs'), ['class' => 'font-weight-bold']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $sendurl->out(false), 'class' => 'mt-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$booking->userid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::label(get_string('requestvacate', 'block_mrbs'), 'mrbs-vacate-message');
    echo html_writer::tag('textarea', s($message), [
        'id' => 'mrbs-vacate-message', 'name' => 'message', 'rows' => 8,
        'class' => 'form-control mb-3', 'required' => 'required',
    ]);
    echo html_writer::tag('button', get_string('sendmessage', 'message'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');
}
