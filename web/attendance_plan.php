<?php
require_once(__DIR__ . '/../../../config.php');
use block_mrbs\local\attendance_planning;
use block_mrbs\local\booking_time;
require_login();
$sessionid = required_param('sessionid', PARAM_INT);
$session = attendance_planning::session($sessionid);
$cm = get_coursemodule_from_instance('attendance', $session->attendanceid, $session->courseid, false, MUST_EXIST);
require_login($session->courseid, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/attendance:takeattendances', $context);
if ($session->groupid && !has_capability('moodle/site:accessallgroups', $context)
        && !isset(groups_get_activity_allowed_groups($cm)[$session->groupid])) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('takeattendance', 'attendance'));
}
$grouptype = optional_param('grouptype', 0, PARAM_INT);
$takeurl = new moodle_url('/mod/attendance/take.php', ['id' => $cm->id, 'sessionid' => $sessionid, 'grouptype' => $grouptype]);
$selected = attendance_planning::selection($sessionid);
if (attendance_planning::taken($session)) {
    throw new moodle_exception('planningattendancelocked', 'block_mrbs');
}
$url = new moodle_url('/blocks/mrbs/web/attendance_plan.php', ['sessionid' => $sessionid, 'grouptype' => $grouptype]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('planningattendance', 'block_mrbs'));
$PAGE->set_heading(get_string('planningattendance', 'block_mrbs'));
$plans = attendance_planning::eligible_plans($session);
$planid = optional_param('planid', $selected ? (int)$selected->planid : 0, PARAM_INT);
$taught = optional_param('taughttopics', $selected ? $selected->taughttopics : ($plans[$planid]->topics ?? ''), PARAM_TEXT);
$error = '';
if (data_submitted()) {
    require_sesskey();
    try { attendance_planning::select($sessionid, $planid, $taught); }
    catch (moodle_exception $e) { $error = $e->getMessage(); }
    if (!$error) { redirect($takeurl); }
}
echo $OUTPUT->header();
echo html_writer::div(get_string('planningattendanceintro', 'block_mrbs'), 'alert alert-info');
echo html_writer::div(s(booking_time::userdate($session->sessdate)), 'mb-3');
if ($error) { echo $OUTPUT->notification($error, 'error'); }
if (!$plans) {
    echo $OUTPUT->notification(get_string('planningattendanceempty', 'block_mrbs'), 'warning');
} else {
    echo '<form method="get" action="' . $url->out_omit_querystring() . '" class="card card-body mb-3">';
    echo '<input type="hidden" name="sessionid" value="' . $sessionid . '"><input type="hidden" name="grouptype" value="' . $grouptype . '">';
    echo '<label for="plan-choice">' . get_string('planningselect', 'block_mrbs') . '</label><select id="plan-choice" name="planid" class="custom-select mb-3" required><option value="">' . get_string('select') . '</option>';
    foreach ($plans as $plan) {
        $label = booking_time::date('Y-m-d', $plan->proposeddate) . ' / '
            . \block_mrbs\local\reservation::get_user_fullname($plan->facultyid) . ' / ' . \core_text::substr($plan->topics, 0, 120);
        echo html_writer::tag('option', s($label), ['value' => $plan->id] + ($plan->id == $planid ? ['selected' => 'selected'] : []));
    }
    echo '</select><button type="submit" class="btn btn-secondary align-self-start">' . get_string('planningreview', 'block_mrbs') . '</button></form>';
    if (isset($plans[$planid])) {
        echo \block_mrbs\output\planning::table([$plans[$planid]]);
        echo '<form method="post" action="' . $url->out() . '" class="card card-body"><input type="hidden" name="sesskey" value="' . sesskey()
            . '"><input type="hidden" name="planid" value="' . $planid . '"><label for="taught-topics">' . get_string('planningtaught', 'block_mrbs')
            . '</label><textarea class="form-control mb-3" id="taught-topics" name="taughttopics" required rows="4" maxlength="10000">' . s($taught)
            . '</textarea><button class="btn btn-primary align-self-start" type="submit">' . get_string('planningcontinuetake', 'block_mrbs') . '</button></form>';
    }
}
echo $OUTPUT->footer();
