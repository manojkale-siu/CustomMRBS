<?php
require_once(__DIR__ . '/../../../config.php');
use block_mrbs\local\faculty_planning;
require_login();
$PAGE->set_context(context_system::instance());
$courses = faculty_planning::viewable_courses();
if (!$courses) {
    throw new moodle_exception('planningviewdenied', 'block_mrbs');
}
$courseid = optional_param('courseid', (int)array_key_first($courses), PARAM_INT);
$plans = faculty_planning::visible_plans($courseid);
if (data_submitted()) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('view'));
}
$url = new moodle_url('/blocks/mrbs/web/session_planning.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('planningstudentview', 'block_mrbs'));
$PAGE->set_heading(get_string('planningstudentview', 'block_mrbs'));
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/blocks/mrbs/web/timetable.php'), get_string('mytimetable', 'block_mrbs'), ['class' => 'btn btn-secondary mb-3']);
echo html_writer::div(get_string('planningviewintro', 'block_mrbs'), 'alert alert-info');
echo '<form method="get" class="card card-body mb-4" action="' . $url->out_omit_querystring() . '"><label for="planning-course">'
    . get_string('course') . '</label><select class="custom-select mb-3" id="planning-course" name="courseid">';
foreach ($courses as $course) {
    echo html_writer::tag('option', s(faculty_planning::course_label($course)), ['value' => $course->id]
        + ($course->id == $courseid ? ['selected' => 'selected'] : []));
}
echo '</select><button type="submit" class="btn btn-primary align-self-start">' . get_string('select') . '</button></form>';
if ($plans) {
    echo \block_mrbs\output\planning::table($plans);
} else {
    echo $OUTPUT->notification(get_string('planningnone', 'block_mrbs'), 'info');
}
echo $OUTPUT->footer();
