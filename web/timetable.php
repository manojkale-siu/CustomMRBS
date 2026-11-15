<?php
// This file is part of Moodle - https://moodle.org/

require_once(__DIR__ . '/../../../config.php');

use block_mrbs\local\booking_options;
use block_mrbs\local\reservation;
use block_mrbs\local\timetable_access;

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/timetable.php', compact('day', 'month', 'year')));
require_login();

if (!timetable_access::can_view_personal()) {
    throw new moodle_exception('accessdenied', 'block_mrbs');
}

$title = get_string('mytimetable', 'block_mrbs');
$PAGE->set_title($title);
$PAGE->set_heading($title);

$week = new DateTimeImmutable('today', core_date::get_user_timezone_object());
if (checkdate($month, $day, $year) && $year >= 1970 && $year <= 9999) {
    $week = $week->setDate($year, $month, $day);
}
$config = get_config('block/mrbs');
$firstday = (int)($config->weekstarts ?? 1);
$offset = ((int)$week->format('w') - $firstday + 7) % 7;
$week = $week->modify("-$offset days");
$end = $week->modify('+7 days');
$entries = timetable_access::entries($week->getTimestamp(), $end->getTimestamp());

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
foreach ([
    'weekbefore' => $week->modify('-7 days'),
    'gotothisweek' => new DateTimeImmutable('today', $week->getTimezone()),
    'weekafter' => $end,
] as $label => $date) {
    $url = new moodle_url('/blocks/mrbs/web/timetable.php', [
        'day' => $date->format('j'), 'month' => $date->format('n'), 'year' => $date->format('Y'),
    ]);
    echo html_writer::link($url, get_string($label, 'block_mrbs'), ['class' => 'btn btn-secondary mr-2']);
}
$dateformat = get_string('strftimedate', 'langconfig');
echo $OUTPUT->heading(userdate($week->getTimestamp(), $dateformat) . ' – '
    . userdate($end->modify('-1 day')->getTimestamp(), $dateformat), 3);

if (!$entries) {
    echo $OUTPUT->notification(get_string('notimetableentries', 'block_mrbs'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('timetablestart', 'block_mrbs'), get_string('timetableend', 'block_mrbs'),
        get_string('timetablecourse', 'block_mrbs'), get_string('group'), get_string('room', 'block_mrbs'),
    ];
    $courselabels = [];
    foreach ($entries as $entry) {
        if (!isset($courselabels[$entry->courseid])) {
            $labels = [];
            foreach (array_unique(booking_options::get_course_hierarchy($entry->courseid)) as $categoryid) {
                if ($categoryid && ($category = core_course_category::get($categoryid, IGNORE_MISSING, true))) {
                    $labels[] = $category->get_formatted_name();
                }
            }
            $labels[] = reservation::get_course_fullname($entry->courseid);
            $courselabels[$entry->courseid] = implode(' / ', $labels);
        }
        $table->data[] = [
            userdate($entry->start_time), userdate($entry->end_time),
            $courselabels[$entry->courseid],
            $entry->groupid ? reservation::get_group_name($entry->groupid, $entry->courseid)
                : get_string('timetableallgroups', 'block_mrbs'),
            format_string($entry->room_name),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
