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
include "config.inc.php";
include "functions.php";

global $USER, $CFG, $PAGE;

if ($CFG->forcelogin) {
    require_login();
}

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);

//If we dont know the right date then make it up
if (($day == 0) or ($month == 0) or ($year == 0)) {
    $day = \block_mrbs\local\booking_time::date("d");
    $month = \block_mrbs\local\booking_time::date("m");
    $year = \block_mrbs\local\booking_time::date("Y");
}

$thisurl = new moodle_url('/blocks/mrbs/web/help.php', array('day' => $day, 'month' => $month, 'year' => $year));
if ($area > 0) {
    $thisurl->param('area', $area);
} else {
    $area = get_default_area();
}

$PAGE->set_url($thisurl);
require_login();

print_header_mrbs($day, $month, $year, $area);

echo $OUTPUT->heading(get_string('help'), 2);
echo html_writer::start_div('card my-3');
echo html_writer::start_div('card-body');
$contact = html_writer::link('mailto:' . $mrbs_admin_email, s($mrbs_admin));
echo html_writer::tag('p', get_string('please_contact', 'block_mrbs') . $contact
    . ' ' . get_string('for_any_questions', 'block_mrbs'));

$lang = current_language();
if (file_exists($CFG->dirroot.'/blocks/mrbs/lang/'.$lang.'/help/site_faq.html')) {
    include $CFG->dirroot.'/blocks/mrbs/lang/'.$lang.'/help/site_faq.html';
} else {
    include $CFG->dirroot.'/blocks/mrbs/lang/en/help/site_faq.html';
}

echo html_writer::end_div();
echo html_writer::end_div();

include "trailer.php";

