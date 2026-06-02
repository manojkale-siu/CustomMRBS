<?php
require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');

global $PAGE, $DB, $OUTPUT;
include "config.inc.php";
include "functions.php";
require_once('mrbs_auth.php');
include "mrbs_sql.php";

// Required by Moodle before $OUTPUT->header()
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/custom_settings.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('MRBS Custom Settings');
$PAGE->set_heading('MRBS Custom Settings');

require_login();
print_header_mrbs();
// echo $OUTPUT->header();

echo "<h2>MRBS Custom Settings</h2>";

// if (isset($_POST['submit'])) {
//     echo "SUBMIT BUTTON IS CLICKED <br>";
// }

// if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if (data_submitted()) {
    require_sesskey();

    $setting_type = optional_param('setting_type', '', PARAM_ALPHA);

    try {
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
    } catch (dml_exception $e) {
        debugging('MRBS custom settings DB error: '.$e->getMessage(), DEBUG_DEVELOPER);
        echo $OUTPUT->notification(get_string('settingssavefailed', 'block_mrbs'), 'notifyproblem');
    } catch (Exception $e) {
        debugging('MRBS custom settings error: '.$e->getMessage(), DEBUG_DEVELOPER);
        echo $OUTPUT->notification(get_string('settingssavefailed', 'block_mrbs'), 'notifyproblem');
    }

} else {
    // echo "<br> No post data found <br>";
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
</style>

<script>
function showTimeBox(type) {
    document.getElementById("timeBox").style.display = (type === 'time') ? "block" : "none";
}
</script>

<div class="box">
    <h3>Time Settings</h3>

    <form method="POST">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <label>
            <input type="radio" name="setting_type" value="periods" onclick="showTimeBox('periods')" required>
            Use Default MRBS Periods
        </label>

        <!-- Hidden field to detect default mode -->
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
                <?php for ($i=1; $i<=12; $i++): ?>
                <option value="<?= $i ?>"><?= $i ?></option>
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
                <?php for ($i=1; $i<=12; $i++): ?>
                <option value="<?= $i ?>"><?= $i ?></option>
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
</div>

<script>
// SET DEFAULT FLAG ONLY IF PERIODS SELECTED
document.querySelector("input[value='periods']").addEventListener('change', function() {
    document.getElementById("default_flag").value = "yes";
});

// CLEAR DEFAULT FLAG IF CUSTOM TIME SELECTED
document.querySelector("input[value='time']").addEventListener('change', function() {
    document.getElementById("default_flag").value = "";
});
</script>
