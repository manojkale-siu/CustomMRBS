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
if (!in_array($section, ['time', 'colours'], true)) {
    $table = new html_table();
    $table->head = [get_string('settings'), get_string('view', 'block_mrbs')];
    foreach (['time' => 'timesettings', 'colours' => 'coloursettings'] as $key => $label) {
        $link = new moodle_url('/blocks/mrbs/web/custom_settings.php', ['section' => $key]);
        $table->data[] = [get_string($label, 'block_mrbs'), html_writer::link($link, get_string('opensettings', 'block_mrbs'), ['class' => 'btn btn-primary'])];
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
        $defaults = optional_param_array('defaultcolour', [], PARAM_BOOL);
        $transaction = $DB->start_delegated_transaction();
        foreach ($programmes as $programme) {
            $value = !empty($defaults[$programme['id']]) ? '' : ($colours[$programme['id']] ?? '');
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
        $id = 'mrbs-colour-' . $programme['id'];
        $picker = html_writer::empty_tag('input', [
            'type' => 'color', 'id' => $id, 'name' => 'colours[' . $programme['id'] . ']',
            'value' => $value ?: '#ddffdd', 'class' => 'form-control mrbs-colour-picker',
            'oninput' => "document.getElementById(this.id + '-code').value = this.value.toUpperCase(); document.getElementById(this.id + '-default').checked = false;",
            'aria-label' => strip_tags($programme['label']),
        ]);
        $code = html_writer::empty_tag('input', [
            'type' => 'text', 'id' => $id . '-code', 'value' => strtoupper($value ?: '#ddffdd'),
            'readonly' => 'readonly', 'class' => 'form-control mrbs-colour-code',
            'aria-label' => get_string('programmecolourcode', 'block_mrbs'),
        ]);
        $default = html_writer::checkbox('defaultcolour[' . $programme['id'] . ']', 1, !$value,
            get_string('defaultprogrammecolour', 'block_mrbs'), ['id' => $id . '-default', 'class' => 'mr-2']);
        $table->data[] = [$programme['label'], html_writer::div($picker . $code, 'd-flex align-items-center') . html_writer::div($default, 'mt-2')];
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

echo $OUTPUT->heading(get_string('timesettings', 'block_mrbs'), 2);

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

<div class="card my-3">
    <div class="card-body">
        <?php if ($customtimelocked): ?>
            <?php echo $OUTPUT->notification(get_string('customtiminglocked', 'block_mrbs'), 'info'); ?>
            <dl class="row mb-0">
                <?php foreach ([
                    'from' => $currentsettings['from'] ?? '',
                    'to' => $currentsettings['to'] ?? '',
                    'timeinterval' => ($currentsettings['interval'] ?? '') . ' ' . get_string('minutes', 'block_mrbs'),
                ] as $label => $value) { ?>
                    <dt class="col-12 col-sm-4"><?php echo get_string($label, 'block_mrbs'); ?></dt>
                    <dd class="col-12 col-sm-8"><?php echo s($value); ?></dd>
                <?php } ?>
            </dl>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="section" value="time">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <fieldset class="form-group">
                    <legend class="h5"><?php echo get_string('timeconfiguration', 'block_mrbs'); ?></legend>
                    <?php foreach (['periods' => 'defaultperiods', 'time' => 'customtimeslots'] as $value => $label) { ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" id="mrbs-time-<?php echo $value; ?>"
                                name="setting_type" value="<?php echo $value; ?>" required
                                onchange="showTimeBox(this.value)">
                            <label class="form-check-label" for="mrbs-time-<?php echo $value; ?>">
                                <?php echo get_string($label, 'block_mrbs'); ?>
                            </label>
                        </div>
                    <?php } ?>
                </fieldset>
                <fieldset id="timeBox" class="border rounded p-3 mb-3" hidden disabled>
                    <legend class="h6 w-auto px-2"><?php echo get_string('definetimerange', 'block_mrbs'); ?></legend>
                    <div class="row">
                        <?php foreach (['from', 'to'] as $prefix) { ?>
                            <fieldset class="form-group col-12 col-lg-6">
                                <legend class="h6"><?php echo get_string($prefix, 'block_mrbs'); ?></legend>
                                <div class="d-flex flex-wrap">
                                    <?php
                                    $options = [
                                        'hour' => array_combine(range(1, 12), range(1, 12)),
                                        'min' => ['00' => '00', '15' => '15', '30' => '30', '45' => '45'],
                                        'ampm' => ['AM' => 'AM', 'PM' => 'PM'],
                                    ];
                                    foreach ($options as $part => $values) {
                                        echo html_writer::select($values, $prefix . '_' . $part, array_key_first($values), false, [
                                            'class' => 'custom-select w-auto mr-1 mb-1',
                                            'aria-label' => get_string($prefix, 'block_mrbs') . ' '
                                                . get_string('timepart' . $part, 'block_mrbs'),
                                        ]);
                                    }
                                    ?>
                                </div>
                            </fieldset>
                        <?php } ?>
                    </div>
                    <div class="form-group mb-0">
                        <label for="mrbs-time-interval"><?php echo get_string('timeinterval', 'block_mrbs'); ?></label>
                        <input class="form-control w-auto" id="mrbs-time-interval" type="number"
                            name="interval" min="1" required>
                    </div>
                </fieldset>
                <button class="btn btn-primary" type="submit" name="submit">
                    <?php echo get_string('savechanges'); ?>
                </button>
            </form>
            <script>
            function showTimeBox(type) {
                var box = document.getElementById('timeBox');
                box.hidden = type !== 'time';
                box.disabled = type !== 'time';
            }
            </script>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/trailer.php';
