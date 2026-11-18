<?php
require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');

global $PAGE, $DB, $OUTPUT;
include 'config.inc.php';
include 'functions.php';
require_once('mrbs_auth.php');
include 'mrbs_sql.php';

// Required by Moodle before $OUTPUT->header().
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/custom_settings.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('MRBS Custom Settings');
$PAGE->set_heading('MRBS Custom Settings');

require_login();
if (!\block_mrbs\local\timetable_access::is_admin()) {
    throw new moodle_exception('accessdenied', 'block_mrbs');
}
$section = optional_param('section', '', PARAM_ALPHA);
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/custom_settings.php', ['section' => $section]));
print_header_mrbs();
echo html_writer::link(new moodle_url('/blocks/mrbs/web/custom_settings.php'), get_string('settings'), ['class' => 'btn btn-outline-secondary mb-3']);
if (!in_array($section, ['time', 'colours'], true)) {
    $table = new html_table();
    $table->head = [get_string('settings'), get_string('view', 'block_mrbs')];
    foreach (['time' => 'timesettings', 'colours' => 'coloursettings'] as $key => $label) {
        $link = new moodle_url('/blocks/mrbs/web/custom_settings.php', ['section' => $key]);
        $table->data[] = [get_string($label, 'block_mrbs'), html_writer::link($link, get_string('open'), ['class' => 'btn btn-primary'])];
    }
    echo html_writer::div(html_writer::table($table), 'card card-body table-responsive');
    include __DIR__ . '/trailer.php';
    exit;
}
if ($section === 'colours') {
    $programmes = \block_mrbs\local\booking_options::get_programmes();
    if (data_submitted()) {
        require_sesskey();
        $colours = optional_param_array('colours', [], PARAM_RAW_TRIMMED);
        $transaction = $DB->start_delegated_transaction();
        foreach ($programmes as $programme) {
            $value = $colours[$programme['id']] ?? '';
            if ($value !== '' && !preg_match('/^#[0-9a-f]{6}$/iD', $value)) {
                throw new moodle_exception('invalidcolour', 'block_mrbs');
            }
            $params = ['setting_group' => 'programme_colours', 'setting_key' => (string)$programme['id']];
            $record = $DB->get_record('block_mrbs_settings', $params);
            if ($value === '') {
                $DB->delete_records('block_mrbs_settings', $params);
            } else if ($record) {
                $record->setting_value = $value;
                $record->updated_at = time();
                $DB->update_record('block_mrbs_settings', $record);
            } else {
                $DB->insert_record('block_mrbs_settings', (object)($params + [
                    'setting_value' => $value, 'created_at' => time(), 'updated_at' => time(), 'creatoruserid' => $USER->id,
                ]));
            }
        }
        $transaction->allow_commit();
        echo $OUTPUT->notification(get_string('settingssaved', 'block_mrbs'), 'success');
    }
    echo $OUTPUT->heading(get_string('coloursettings', 'block_mrbs'), 2);
    echo '<form method="post" class="card card-body">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => 'colours']);
    $table = new html_table();
    $table->head = [get_string('programme', 'block_mrbs'), get_string('coloursettings', 'block_mrbs')];
    foreach ($programmes as $programme) {
        $value = $DB->get_field('block_mrbs_settings', 'setting_value', [
            'setting_group' => 'programme_colours', 'setting_key' => (string)$programme['id'],
        ]);
        $table->data[] = [$programme['label'], html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'colours[' . $programme['id'] . ']', 'value' => $value ?: '',
            'class' => 'form-control', 'pattern' => '#[0-9a-fA-F]{6}', 'placeholder' => '#DDFFDD',
            'aria-label' => strip_tags($programme['label']),
        ])];
    }
    echo html_writer::tag('p', get_string('colourinstructions', 'block_mrbs'));
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo html_writer::tag('button', get_string('savechanges'), ['class' => 'btn btn-primary', 'type' => 'submit']);
    echo '</form>';
    include __DIR__ . '/trailer.php';
    exit;
}

$customtimelocked = \block_mrbs\local\settings_manager::has_valid_custom_time_range();
$currentsettings = \block_mrbs\local\settings_manager::get_time_settings();

echo '<h2>MRBS Custom Settings</h2>';

if (data_submitted()) {
    require_sesskey();

    try {
        if ($customtimelocked) {
            throw new \moodle_exception('customtiminglocked', 'block_mrbs');
        }

        $setting_type = optional_param('setting_type', '', PARAM_ALPHA);

        if ($setting_type === 'periods') {
            \block_mrbs\local\settings_manager::save_default_periods();
            echo $OUTPUT->notification(get_string('settingssaved', 'block_mrbs'), 'notifysuccess');
        } else {
            $from_hour = optional_param('from_hour', 0, PARAM_INT);
            $from_min = optional_param('from_min', 0, PARAM_INT);
            $from_ampm = optional_param('from_ampm', 'AM', PARAM_ALPHA);
            $to_hour = optional_param('to_hour', 0, PARAM_INT);
            $to_min = optional_param('to_min', 0, PARAM_INT);
            $to_ampm = optional_param('to_ampm', 'AM', PARAM_ALPHA);
            $interval = optional_param('interval', 0, PARAM_INT);

            $from_24 = \block_mrbs\local\settings_manager::to_24_hour_time($from_hour, $from_min, $from_ampm);
            $to_24 = \block_mrbs\local\settings_manager::to_24_hour_time($to_hour, $to_min, $to_ampm);

            if ($from_24 >= $to_24) {
                echo $OUTPUT->notification(get_string('frombeforeto', 'block_mrbs'), 'notifyproblem');
            } else if ($interval <= 0) {
                echo $OUTPUT->notification(get_string('invalidinterval', 'block_mrbs'), 'notifyproblem');
            } else {
                \block_mrbs\local\settings_manager::save_time_settings($from_24, $to_24, $interval);
                echo $OUTPUT->notification(get_string('settingssaved', 'block_mrbs'), 'notifysuccess');
            }
        }
    } catch (\moodle_exception $e) {
        if ($e->errorcode === 'customtiminglocked') {
            echo $OUTPUT->notification(get_string('customtiminglocked', 'block_mrbs'), 'notifyproblem');
        } else {
            debugging('MRBS custom settings exception: '.$e->getMessage(), DEBUG_DEVELOPER);
            echo $OUTPUT->notification(get_string('settingssavefailed', 'block_mrbs'), 'notifyproblem');
        }
    } catch (dml_exception $e) {
        debugging('MRBS custom settings DB error: '.$e->getMessage(), DEBUG_DEVELOPER);
        echo $OUTPUT->notification(get_string('settingssavefailed', 'block_mrbs'), 'notifyproblem');
    } catch (Exception $e) {
        debugging('MRBS custom settings error: '.$e->getMessage(), DEBUG_DEVELOPER);
        echo $OUTPUT->notification(get_string('settingssavefailed', 'block_mrbs'), 'notifyproblem');
    }
}
?>

<style>
.box {
    border: 1px solid #ccc;
    border-radius: 8px;
    padding: 15px;
    width: 60%;
    margin-bottom: 20px;
}
.readonly-settings {
    background: #f8f9fa;
}
</style>

<script>
function showTimeBox(type) {
    document.getElementById('timeBox').style.display = (type === 'time') ? 'block' : 'none';
}
</script>

<div class="box <?php echo $customtimelocked ? 'readonly-settings' : ''; ?>">
    <h3>Time Settings</h3>

    <?php if ($customtimelocked): ?>
        <?php echo $OUTPUT->notification(get_string('customtiminglocked', 'block_mrbs'), 'notifyproblem'); ?>
        <p><strong><?php echo s(get_string('from', 'block_mrbs')); ?>:</strong> <?php echo s($currentsettings['from'] ?? ''); ?></p>
        <p><strong><?php echo s(get_string('to', 'block_mrbs')); ?>:</strong> <?php echo s($currentsettings['to'] ?? ''); ?></p>
        <p><strong><?php echo s(get_string('duration', 'block_mrbs')); ?>:</strong> <?php echo s(($currentsettings['interval'] ?? '') . ' ' . get_string('minutes', 'block_mrbs')); ?></p>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="section" value="time">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

            <label>
                <input type="radio" name="setting_type" value="periods" onclick="showTimeBox('periods')" required>
                Use Default MRBS Periods
            </label>

            <input type="hidden" name="default_flag" id="default_flag" value="">

            <br><br>

            <label>
                <input type="radio" name="setting_type" value="time" onclick="showTimeBox('time')" required>
                Use Custom Time Slots
            </label>

            <div id="timeBox" style="display:none; margin-top:20px;">
                <h4>Define Time Range</h4>

                <strong>From:</strong><br>
                <select name="from_hour">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>

                <select name="from_min">
                    <option value="00">00</option>
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="45">45</option>
                </select>

                <select name="from_ampm">
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                </select>

                <br><br>

                <strong>To:</strong><br>
                <select name="to_hour">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>

                <select name="to_min">
                    <option value="00">00</option>
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="45">45</option>
                </select>

                <select name="to_ampm">
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                </select>

                <br><br>

                <strong>Slot Interval (Minutes):</strong><br>
                <input type="number" name="interval" min="1">
            </div>

            <br><br>
            <button type="submit" name="submit">Save</button>
        </form>
        <script>
        document.querySelector("input[value='periods']").addEventListener('change', function() {
            document.getElementById('default_flag').value = 'yes';
        });

        document.querySelector("input[value='time']").addEventListener('change', function() {
            document.getElementById('default_flag').value = '';
        });
        </script>
    <?php endif; ?>
</div>
