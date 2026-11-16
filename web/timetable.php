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
$coursefilter = max(0, optional_param('course', 0, PARAM_INT));
$facultyfilter = max(0, optional_param('faculty', 0, PARAM_INT));
$startfrom = optional_param('startfrom', '', PARAM_RAW_TRIMMED);
$startto = optional_param('startto', '', PARAM_RAW_TRIMMED);
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
$coursenames = [];
$facultynames = [];
foreach ($entries as $entry) {
    $coursenames[$entry->courseid] = $coursenames[$entry->courseid] ?? reservation::get_course_fullname($entry->courseid);
    if (!empty($entry->facultyid)) {
        $facultynames[$entry->facultyid] = $facultynames[$entry->facultyid] ?? reservation::get_user_fullname($entry->facultyid);
    }
}

$courseoptions = array_filter($coursenames);
$facultyoptions = array_filter($facultynames);
asort($courseoptions, SORT_NATURAL | SORT_FLAG_CASE);
asort($facultyoptions, SORT_NATURAL | SORT_FLAG_CASE);
$coursefilter = isset($courseoptions[$coursefilter]) ? $coursefilter : 0;
$facultyfilter = isset($facultyoptions[$facultyfilter]) ? $facultyfilter : 0;

$timepattern = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D';
$startfrom = preg_match($timepattern, $startfrom) ? $startfrom : '';
$startto = preg_match($timepattern, $startto) ? $startto : '';
$invalidrange = $startfrom !== '' && $startto !== '' && $startfrom > $startto;
$filteredentries = array_filter($entries, static function ($entry) use (
    $coursefilter, $facultyfilter, $startfrom, $startto, $invalidrange, $timezone
) {
    if (($coursefilter && (int)$entry->courseid !== $coursefilter)
            || ($facultyfilter && (int)$entry->facultyid !== $facultyfilter)) {
        return false;
    }
    if ($invalidrange || ($startfrom === '' && $startto === '')) {
        return true;
    }
    $localstart = (new DateTimeImmutable('@' . $entry->start_time))->setTimezone($timezone)->format('H:i');
    return ($startfrom === '' || $localstart >= $startfrom) && ($startto === '' || $localstart <= $startto);
});

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

$clearurl = $timetableurl($selected, $view);
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $clearurl->out_omit_querystring(false),
    'class' => 'border rounded p-3 mt-3 mb-4',
    'aria-labelledby' => 'mrbs-timetable-filters',
]);
foreach ($clearurl->params() as $name => $value) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
}
echo html_writer::tag('h3', get_string('timetablefilters', 'block_mrbs'), ['id' => 'mrbs-timetable-filters', 'class' => 'h5']);
echo html_writer::start_div('form-row');
foreach ([
    'course' => [$courseoptions, $coursefilter, 'timetableallcourses'],
    'faculty' => [array_map('s', $facultyoptions), $facultyfilter, 'timetableallfaculty'],
] as $field => [$options, $value, $placeholder]) {
    $id = 'mrbs-timetable-' . $field;
    echo html_writer::start_div('form-group col-12 col-md-3');
    echo html_writer::label(get_string($field, 'block_mrbs'), $id);
    echo html_writer::select($options, $field, $value, [0 => get_string($placeholder, 'block_mrbs')], [
        'id' => $id, 'class' => 'custom-select w-100',
    ]);
    echo html_writer::end_div();
}
foreach (['startfrom' => $startfrom, 'startto' => $startto] as $field => $value) {
    $id = 'mrbs-timetable-' . $field;
    echo html_writer::start_div('form-group col-6 col-md-3');
    echo html_writer::label(get_string('timetable' . $field, 'block_mrbs'), $id);
    echo html_writer::empty_tag('input', [
        'type' => 'time', 'name' => $field, 'value' => $value, 'id' => $id, 'class' => 'form-control',
    ]);
    echo html_writer::end_div();
}
echo html_writer::end_div();
if ($invalidrange) {
    echo html_writer::div(get_string('timetableinvalidrange', 'block_mrbs'), 'alert alert-warning');
}
echo html_writer::tag('button', get_string('search', 'block_mrbs'), ['type' => 'submit', 'class' => 'btn btn-primary mr-2']);
echo html_writer::link($clearurl, get_string('timetableclearfilters', 'block_mrbs'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

if (!$filteredentries) {
    echo html_writer::div(get_string('timetablenofiltermatches', 'block_mrbs'), 'alert alert-info');
    echo $OUTPUT->footer();
    return;
}

$courses = [];
$groups = [];
$currentday = '';
$timeformat = get_string('strftimetime', 'langconfig');
$dayformat = get_string('strftimedaydate', 'langconfig');
$now = time();
foreach ($filteredentries as $entry) {
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
            'name' => $coursenames[$entry->courseid],
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

    if ($now < (int)$entry->start_time) {
        $status = 'timetablestatusincoming';
        $badge = 'badge-info';
    } else if ($now < (int)$entry->end_time) {
        $status = 'timetablestatuslive';
        $badge = 'badge-success';
    } else {
        $status = 'timetablestatusdone';
        $badge = 'badge-secondary';
    }

    echo html_writer::start_tag('li', ['class' => 'list-group-item']);
    echo html_writer::start_div('row');
    echo html_writer::div(s($from . ' – ' . $to), 'col-12 col-md-3 font-weight-bold mb-2 mb-md-0');
    echo html_writer::start_div('col-12 col-md-9');
    echo html_writer::start_div('d-flex flex-wrap align-items-center justify-content-between');
    echo html_writer::div($course['name'], 'font-weight-bold mr-2');
    echo html_writer::span(get_string($status, 'block_mrbs'), 'badge badge-pill ' . $badge);
    echo html_writer::end_div();
    if ($course['path'] !== '') {
        echo html_writer::div($course['path'], 'small text-muted');
    }
    if (!empty($entry->facultyid)) {
        $facultyid = (int)$entry->facultyid;
        if ($facultynames[$facultyid] !== '') {
            echo html_writer::div(get_string('faculty', 'block_mrbs') . ': ' . s($facultynames[$facultyid]), 'small mt-2');
        }
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
