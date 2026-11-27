<?php
// Faculty proposals do not create room bookings or Attendance sessions.
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');
use block_mrbs\local\faculty_planning;
use block_mrbs\local\faculty_plan_import;
use block_mrbs\local\booking_time;
require_login();
$PAGE->set_context(context_system::instance());
$courses = faculty_planning::courses();
if (!$courses) {
    throw new moodle_exception('planningfacultyonly', 'block_mrbs');
}
$courseid = optional_param('courseid', (int)array_key_first($courses), PARAM_INT);
faculty_planning::require_course($courseid);
$url = new moodle_url('/blocks/mrbs/web/faculty_session_planning.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('facultyplanning', 'block_mrbs'));
$PAGE->set_heading(get_string('facultyplanning', 'block_mrbs'));
if (optional_param('template', false, PARAM_BOOL)) {
    faculty_plan_import::download_template($courseid);
    exit;
}
$error = '';
$success = '';
$editid = optional_param('editid', 0, PARAM_INT);
$values = [['', '', '', 0, '', '']];
if ($editid) {
    $plan = faculty_planning::owned_plan($editid, $courseid);
    if (\block_mrbs\local\attendance_planning::is_locked($editid)) {
        throw new moodle_exception('planningtakenlocked', 'block_mrbs');
    }
    $values = [[booking_time::date('Y-m-d', $plan->proposeddate), $plan->topics, $plan->methodology,
        $plan->resourcecmid, $plan->assessment, $plan->remarks ?? '']];
}
if (data_submitted()) {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHA);
        if ($action === 'delete') {
            faculty_planning::delete(required_param('planid', PARAM_INT), $courseid);
            $success = get_string('planningdeleted', 'block_mrbs');
        } else {
            if ($action === 'import') {
                $file = $_FILES['workbook'] ?? null;
                if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > faculty_plan_import::MAX_BYTES
                        || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx'
                        || !is_uploaded_file($file['tmp_name'])) {
                    throw new moodle_exception('sessionimportfileerror', 'block_mrbs');
                }
                $rows = faculty_plan_import::read($file['tmp_name']);
                $editid = 0;
            } else if ($action === 'save') {
                $fields = ['dates', 'topics', 'methodologies', 'resources', 'assessments', 'remarks'];
                $columns = [];
                $expected = required_param('rowcount', PARAM_INT);
                if ($expected < 1 || $expected > faculty_planning::MAX_ROWS) {
                    throw new moodle_exception('planningrowlimit', 'block_mrbs');
                }
                foreach ($fields as $field) {
                    $columns[] = optional_param_array($field, [], PARAM_RAW_TRIMMED);
                    if (count(end($columns)) !== $expected || array_keys(end($columns)) !== range(0, $expected - 1)) {
                        throw new moodle_exception('planningtruncated', 'block_mrbs');
                    }
                }
                $rows = [];
                for ($i = 0; $i < $expected; $i++) {
                    $rows[$i + 1] = array_map(static function ($column) use ($i) { return $column[$i]; }, $columns);
                }
                $values = array_values($rows);
            } else {
                throw new moodle_exception('invalidrequest', 'error');
            }
            $count = faculty_planning::save($courseid, $rows, $editid);
            $success = get_string('planningsaved', 'block_mrbs', $count);
        }
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        debugging('MRBS faculty planning failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        $error = get_string('planningfailed', 'block_mrbs');
    }
    if ($success !== '') {
        redirect($url, $success, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}
$PAGE->requires->js_call_amd('block_mrbs/faculty_planning', 'init');
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/blocks/mrbs/web/timetable.php'), get_string('mytimetable', 'block_mrbs'), ['class' => 'btn btn-secondary mb-3']);
echo html_writer::div(s(get_string('planningintro', 'block_mrbs')), 'alert alert-info');
if ($error !== '') {
    echo $OUTPUT->notification($error, 'error');
}
?>
<div class="card mb-4"><div class="card-body">
<form method="get" action="<?php echo $url->out_omit_querystring(); ?>">
<label for="planning-course"><?php echo get_string('course'); ?></label>
<select id="planning-course" name="courseid" class="custom-select w-100 mb-2">
<?php foreach ($courses as $course) {
    echo html_writer::tag('option', s(faculty_planning::course_label($course)),
        ['value' => $course->id] + ($course->id == $courseid ? ['selected' => 'selected'] : []));
} ?>
</select><button class="btn btn-primary" type="submit"><?php echo get_string('select'); ?></button>
</form>
<p class="mt-3 mb-0"><?php echo s(fullname($USER) . (!empty($USER->idnumber) ? ' (' . $USER->idnumber . ')' : '')); ?></p>
</div></div>
<?php
function block_mrbs_plan_row(array $row): void {
    global $courseid;
    echo '<div class="planning-row border rounded p-3 mb-3"><div class="row">';
    echo '<div class="col-md-4"><label class="w-100">' . get_string('planningdate', 'block_mrbs')
        . '<input class="form-control" required type="date" name="dates[]" value="' . s($row[0]) . '"></label></div>';
    foreach (['methodologies' => [2, faculty_planning::METHODOLOGIES, 'planningmethodology'],
            'assessments' => [4, faculty_planning::ASSESSMENTS, 'planningassessment']] as $name => [$index, $choices, $label]) {
        echo '<div class="col-md-4"><label class="w-100">' . get_string($label, 'block_mrbs')
            . '<select class="custom-select" required name="' . $name . '[]"><option value="">' . get_string('select') . '</option>';
        foreach ($choices as $choice) {
            echo html_writer::tag('option', s($choice), ['value' => $choice] + ($row[$index] === $choice ? ['selected' => 'selected'] : []));
        }
        echo '</select></label></div>';
    }
    echo '</div><label class="w-100">' . get_string('planningtopics', 'block_mrbs')
        . '<textarea class="form-control" required maxlength="10000" rows="3" name="topics[]">' . s($row[1]) . '</textarea></label>';
    echo '<label class="w-100">' . get_string('planningresource', 'block_mrbs') . '<select class="custom-select" name="resources[]">';
    foreach ([0 => get_string('planningnoresource', 'block_mrbs')] + faculty_planning::resources($courseid) as $id => $label) {
        echo html_writer::tag('option', s($label), ['value' => $id] + ((int)$row[3] === (int)$id ? ['selected' => 'selected'] : []));
    }
    echo '</select></label><label class="w-100">' . get_string('planningremarks', 'block_mrbs')
        . '<textarea class="form-control" maxlength="10000" rows="2" name="remarks[]">' . s($row[5]) . '</textarea></label>';
    echo '<button type="button" class="btn btn-outline-secondary planning-remove">' . get_string('remove') . '</button></div>';
}
?>
<div class="card mb-4"><div class="card-body">
<h3><?php echo get_string('plansession', 'block_mrbs'); ?></h3>
<form method="post" action="<?php echo $url->out(); ?>" id="faculty-planning-form">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
<input type="hidden" name="action" value="save"><input type="hidden" name="editid" value="<?php echo $editid; ?>">
<input type="hidden" name="rowcount" id="planning-rowcount" value="<?php echo count($values); ?>">
<div id="planning-rows"><?php foreach ($values as $row) { block_mrbs_plan_row($row); } ?></div>
<?php if (!$editid) { ?><button class="btn btn-secondary mr-2" type="button" id="planning-add"><?php echo get_string('planningadd', 'block_mrbs'); ?></button><?php } ?>
<button class="btn btn-primary" type="submit"><?php echo get_string('savechanges'); ?></button>
</form>
<template id="planning-row-template"><?php block_mrbs_plan_row(['', '', '', 0, '', '']); ?></template>
</div></div>
<div class="card mb-4"><div class="card-body">
<h3><?php echo get_string('planningexcel', 'block_mrbs'); ?></h3>
<p><?php echo get_string('planningexcelhelp', 'block_mrbs'); ?></p>
<?php echo html_writer::link(new moodle_url($url, ['template' => 1]), get_string('sessionimporttemplate', 'block_mrbs'), ['class' => 'btn btn-primary mb-3']); ?>
<form method="post" enctype="multipart/form-data" action="<?php echo $url->out(); ?>">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>"><input type="hidden" name="action" value="import">
<label class="d-block"><?php echo get_string('planningworkbook', 'block_mrbs'); ?><input class="form-control-file mt-2" type="file" name="workbook" accept=".xlsx" required></label>
<button class="btn btn-primary" type="submit"><?php echo get_string('import'); ?></button></form>
</div></div>
<h3><?php echo get_string('planninglist', 'block_mrbs'); ?></h3>
<?php
 echo \block_mrbs\output\planning::table(faculty_planning::plans($courseid), $url);
 echo $OUTPUT->footer();
