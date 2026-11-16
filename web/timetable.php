<?php
// This file is part of Moodle - https://moodle.org/

require_once(__DIR__ . '/../../../config.php');

use block_mrbs\local\booking_options;
use block_mrbs\local\reservation;
use block_mrbs\local\timetable_access;

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$views = ['day', 'week', 'month'];
$view = optional_param('view', 'week', PARAM_ALPHA);
if (!in_array($view, $views, true)) {
    $view = 'week';
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/timetable.php', compact('day', 'month', 'year', 'view')));
require_login();

if (!timetable_access::can_view_personal()) {
    throw new moodle_exception('accessdenied', 'block_mrbs');
}

$title = get_string('mytimetable', 'block_mrbs');
$PAGE->set_title($title);
$PAGE->set_heading($title);

$timezone = core_date::get_user_timezone_object();
$today = new DateTimeImmutable('today', $timezone);
$selected = $today;
if (checkdate($month, $day, $year) && $year >= 1970 && $year <= 9999) {
    $selected = $today->setDate($year, $month, $day);
}

$dateformat = get_string('strftimedate', 'langconfig');
switch ($view) {
    case 'day':
        $start = $selected;
        $end = $start->modify('+1 day');
        $previous = $start->modify('-1 day');
        $next = $end;
        $periodlabel = userdate($start->getTimestamp(), $dateformat);
        break;
    case 'month':
        $start = $selected->modify('first day of this month');
        $end = $start->modify('first day of next month');
        $previous = $start->modify('first day of previous month');
        $next = $end;
        $periodlabel = userdate($start->getTimestamp(), get_string('strftimemonthyear', 'langconfig'));
        break;
    default:
        $config = get_config('block/mrbs');
        $firstday = (int)($config->weekstarts ?? 1);
        $offset = ((int)$selected->format('w') - $firstday + 7) % 7;
        $start = $selected->modify("-$offset days");
        $end = $start->modify('+7 days');
        $previous = $selected->modify('-7 days');
        $next = $selected->modify('+7 days');
        $periodlabel = userdate($start->getTimestamp(), $dateformat) . ' – '
            . userdate($end->modify('-1 day')->getTimestamp(), $dateformat);
        break;
}

$entries = timetable_access::entries($start->getTimestamp(), $end->getTimestamp());
$timetableurl = static function (DateTimeImmutable $date, string $view): moodle_url {
    return new moodle_url('/blocks/mrbs/web/timetable.php', [
        'day' => $date->format('j'),
        'month' => $date->format('n'),
        'year' => $date->format('Y'),
        'view' => $view,
    ]);
};

echo $OUTPUT->header();
echo html_writer::start_tag('nav', [
    'class' => 'border rounded p-3 mb-4 d-flex flex-wrap justify-content-between align-items-center',
    'aria-label' => get_string('timetablenavigation', 'block_mrbs'),
]);
echo html_writer::start_div('d-flex flex-wrap');
foreach ($views as $option) {
    $active = $option === $view;
    $attributes = ['class' => 'btn btn-sm mr-2 my-1 ' . ($active ? 'btn-primary' : 'btn-outline-secondary')];
    if ($active) {
        $attributes['aria-current'] = 'page';
    }
    echo html_writer::link($timetableurl($selected, $option), get_string('timetableview' . $option, 'block_mrbs'), $attributes);
}
echo html_writer::end_div();

echo html_writer::start_div('d-flex flex-wrap');
foreach (['previous' => $previous, 'timetabletoday' => $today, 'next' => $next] as $label => $date) {
    echo html_writer::link($timetableurl($date, $view), get_string($label, 'block_mrbs'), [
        'class' => 'btn btn-sm btn-outline-secondary mr-2 my-1',
    ]);
}
echo html_writer::end_div();
echo html_writer::end_tag('nav');

echo $OUTPUT->heading($periodlabel, 2);
if (!$entries) {
    echo html_writer::div(get_string('notimetableentries' . $view, 'block_mrbs'), 'alert alert-info mt-3');
    echo $OUTPUT->footer();
    return;
}

$courses = [];
$groups = [];
$currentday = '';
$timeformat = get_string('strftimetime', 'langconfig');
$dayformat = get_string('strftimedaydate', 'langconfig');
foreach ($entries as $entry) {
    $entryday = max((int)$entry->start_time, $start->getTimestamp());
    $daykey = (new DateTimeImmutable('@' . $entryday))->setTimezone($timezone)->format('Y-m-d');
    if ($daykey !== $currentday) {
        if ($currentday !== '') {
            echo html_writer::end_tag('ul');
        }
        $currentday = $daykey;
        if ($view !== 'day') {
            echo $OUTPUT->heading(userdate($entryday, $dayformat), 3, 'h5 mt-4 mb-2');
        }
        echo html_writer::start_tag('ul', ['class' => 'list-group mb-3']);
    }

    if (!isset($courses[$entry->courseid])) {
        $categories = [];
        foreach (array_unique(booking_options::get_course_hierarchy($entry->courseid)) as $categoryid) {
            if ($categoryid && ($category = core_course_category::get($categoryid, IGNORE_MISSING, true))) {
                $categories[] = $category->get_formatted_name();
            }
        }
        $courses[$entry->courseid] = [
            'name' => reservation::get_course_fullname($entry->courseid),
            'path' => implode(' / ', $categories),
        ];
    }
    $course = $courses[$entry->courseid];
    if ($entry->groupid && !isset($groups[$entry->courseid][$entry->groupid])) {
        $groups[$entry->courseid][$entry->groupid] = reservation::get_group_name($entry->groupid, $entry->courseid);
    }
    $group = $groups[$entry->courseid][$entry->groupid] ?? get_string('timetableallgroups', 'block_mrbs');

    $from = userdate($entry->start_time, $timeformat);
    $to = userdate($entry->end_time, $timeformat);
    $fromday = (new DateTimeImmutable('@' . $entry->start_time))->setTimezone($timezone)->format('Y-m-d');
    $endday = (new DateTimeImmutable('@' . $entry->end_time))->setTimezone($timezone)->format('Y-m-d');
    if ($fromday !== $endday) {
        $from = userdate($entry->start_time);
        $to = userdate($entry->end_time);
    }

    echo html_writer::start_tag('li', ['class' => 'list-group-item']);
    echo html_writer::start_div('row');
    echo html_writer::div(s($from . ' – ' . $to), 'col-12 col-md-3 font-weight-bold mb-2 mb-md-0');
    echo html_writer::start_div('col-12 col-md-9');
    echo html_writer::div($course['name'], 'font-weight-bold');
    if ($course['path'] !== '') {
        echo html_writer::div($course['path'], 'small text-muted');
    }
    echo html_writer::div(
        get_string('room', 'block_mrbs') . ': ' . format_string($entry->room_name)
            . ' · ' . get_string('group') . ': ' . $group,
        'small mt-2'
    );
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('li');
}
echo html_writer::end_tag('ul');
echo $OUTPUT->footer();
