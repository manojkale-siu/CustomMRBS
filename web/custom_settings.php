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
print_header_mrbs();

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
