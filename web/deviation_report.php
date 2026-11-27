<?php
require_once(__DIR__ . '/../../../config.php');
use block_mrbs\local\director_access;
use block_mrbs\local\deviation_report;
use block_mrbs\local\booking_time;
require_login();
director_access::require_director();
$courseid = max(0, optional_param('courseid', 0, PARAM_INT));
$facultyid = max(0, optional_param('facultyid', 0, PARAM_INT));
$PAGE->set_context(context_system::instance());
$url = new moodle_url('/blocks/mrbs/web/deviation_report.php', compact('courseid', 'facultyid'));
$PAGE->set_url($url);
$PAGE->set_title(get_string('deviationreport', 'block_mrbs'));
$PAGE->set_heading(get_string('deviationreport', 'block_mrbs'));
$data = deviation_report::data($courseid, $facultyid);
$courses = $DB->get_records_sql('SELECT c.* FROM {course} c WHERE EXISTS
    (SELECT 1 FROM {block_mrbs_faculty_plan} p WHERE p.courseid=c.id) ORDER BY c.fullname');
$faculty = $DB->get_records_sql('SELECT u.* FROM {user} u WHERE EXISTS
    (SELECT 1 FROM {block_mrbs_faculty_plan} p WHERE p.facultyid=u.id) ORDER BY u.lastname,u.firstname');
echo $OUTPUT->header();
echo html_writer::div(get_string('deviationintro', 'block_mrbs'), 'alert alert-info');
echo '<form method="get" action="' . $url->out_omit_querystring() . '" class="card card-body mb-4"><div class="row">';
foreach (['courseid' => [$courses, $courseid, 'course'], 'facultyid' => [$faculty, $facultyid, 'faculty']] as $name => [$options, $selected, $label]) {
    echo '<div class="col-md-5"><label class="w-100" for="' . $name . '">' . ($label === 'course' ? get_string('course') : get_string('faculty', 'block_mrbs'))
        . '</label><select class="custom-select mb-3" name="' . $name . '" id="' . $name . '"><option value="0">' . get_string('all') . '</option>';
    foreach ($options as $option) {
        $text = $label === 'course' ? \block_mrbs\local\faculty_planning::course_label($option) : fullname($option);
        echo html_writer::tag('option', s($text), ['value' => $option->id] + ($selected == $option->id ? ['selected' => 'selected'] : []));
    }
    echo '</select></div>';
}
echo '<div class="col-md-2 align-self-end"><button type="submit" class="btn btn-primary mb-3">' . get_string('search') . '</button></div></div></form>';
if ($data['unmapped'] !== null) {
    echo $OUTPUT->notification(get_string('deviationunmapped', 'block_mrbs', $data['unmapped']), 'info');
}
$table = new html_table(); $table->attributes['class'] = 'table table-striped';
$table->head = array_map(static function ($key) { return get_string($key, 'block_mrbs'); },
    ['faculty', 'deviationempcode', 'deviationcoursecode', 'deviationcoursepath', 'deviationplanned', 'deviationconducted',
        'deviationcompleted', 'deviationcompletion', 'deviationoverdue', 'deviationdifferences', 'planningremarks']);
foreach ($data['summary'] as $row) {
    $completed = count($row['completed']);
    $table->data[] = [s(fullname($row['faculty'])), s($row['faculty']->idnumber), s($row['course']->shortname),
        s(\block_mrbs\local\faculty_planning::course_label($row['course'])), $row['planned'], $row['conducted'], $completed,
        format_float(100 * $completed / $row['planned'], 2) . '%', $row['overdue'], $row['differences'],
        s(implode('; ', $row['remarks']))];
}
echo $OUTPUT->heading(get_string('deviationsummary', 'block_mrbs'), 3);
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->heading(get_string('deviationdetail', 'block_mrbs'), 3);
$detail = new html_table(); $detail->attributes['class'] = 'table table-striped';
$detail->head = array_map(static function ($key) { return get_string($key, 'block_mrbs'); },
    ['faculty', 'deviationcoursecode', 'planningdate', 'deviationconducteddate', 'deviationplannedtopics', 'planningtaught', 'deviationstatus']);
foreach ($data['detail'] as $row) {
    $use = $row['use'];
    $status = [];
    if ($row['datediff']) { $status[] = get_string('deviationdatechanged', 'block_mrbs'); }
    if ($row['topicdiff']) { $status[] = get_string('deviationtopicschanged', 'block_mrbs'); }
    $detail->data[] = [s(fullname($row['faculty'])), s($row['course']->shortname), s(booking_time::date('Y-m-d', $use->proposeddate)),
        s(booking_time::date('Y-m-d', $use->conducteddate)), nl2br(s($use->plannedtopics)), nl2br(s($use->taughttopics)),
        s($status ? implode('; ', $status) : get_string('deviationmatched', 'block_mrbs'))];
}
echo html_writer::div(html_writer::table($detail), 'table-responsive');
if (!$data['summary']) { echo $OUTPUT->notification(get_string('planningnone', 'block_mrbs'), 'info'); }
echo $OUTPUT->footer();
