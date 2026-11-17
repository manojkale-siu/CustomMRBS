<?php
// This file is part of Moodle - https://moodle.org/.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once(__DIR__ . '/config.inc.php');
require_once(__DIR__ . '/functions.php');
require_once(__DIR__ . '/mrbs_sql.php');

use block_mrbs\local\session_import;

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);
$url = new moodle_url('/blocks/mrbs/web/session_import.php', compact('day', 'month', 'year', 'area'));
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
require_login();

if (!getAuthorised(1)) {
    throw new moodle_exception('accessdenied', 'block_mrbs');
}
if (optional_param('template', false, PARAM_BOOL)) {
    session_import::download_template();
    exit;
}

$counts = [];
$error = '';
if (data_submitted()) {
    require_sesskey();
    $upload = $_FILES['workbook'] ?? null;
    try {
        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > session_import::MAX_BYTES
                || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'xlsx'
                || !is_uploaded_file($upload['tmp_name'])) {
            throw new moodle_exception('sessionimportfileerror', 'block_mrbs');
        }
        $counts = session_import::import(
            session_import::read($upload['tmp_name']),
            optional_param('allowcapacity', false, PARAM_BOOL),
            (bool)$enable_periods
        );
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

print_header_mrbs($day, $month, $year, $area);
echo $OUTPUT->heading(get_string('sessionimport', 'block_mrbs'), 2);
if ($error !== '') {
    echo $OUTPUT->notification(s(get_string('sessionimportfailed', 'block_mrbs', $error)), 'error');
}
if ($counts) {
    echo $OUTPUT->notification(get_string('sessionimportsuccess', 'block_mrbs', array_sum($counts)), 'success');
    $table = new html_table();
    $table->head = [get_string('date'), get_string('sessionimportcount', 'block_mrbs')];
    foreach ($counts as $date => $count) {
        $table->data[] = [s($date), $count];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

$templateurl = new moodle_url($url, ['template' => 1]);
?>
<form method="post" enctype="multipart/form-data" action="<?php echo $url->out(); ?>" class="card my-3">
    <div class="card-body">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <p><?php echo get_string('sessionimportinstructions', 'block_mrbs'); ?></p>
        <p><?php echo html_writer::link($templateurl, get_string('sessionimporttemplate', 'block_mrbs'), [
            'class' => 'btn btn-outline-primary',
        ]); ?></p>
        <div class="form-group">
            <label for="mrbs-session-workbook"><?php echo get_string('sessionimportworkbook', 'block_mrbs'); ?></label>
            <input class="form-control-file" type="file" id="mrbs-session-workbook" name="workbook"
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="mrbs-import-capacity" name="allowcapacity" value="1">
            <label class="form-check-label" for="mrbs-import-capacity">
                <?php echo get_string('sessionimportallowcapacity', 'block_mrbs'); ?>
            </label>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo get_string('sessionimport', 'block_mrbs'); ?></button>
    </div>
</form>
<?php include __DIR__ . '/trailer.php';
