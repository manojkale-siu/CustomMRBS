<?php

// This file is part of the MRBS block for Moodle
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
include "config.inc.php";
include "functions.php";
require_once("mrbs_auth.php");

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);
$period = optional_param('period', optional_param('start_min', 0, PARAM_INT), PARAM_INT);
$start_hour = optional_param('start_hour', 0, PARAM_INT);
$start_min = optional_param('start_min', 0, PARAM_INT);
$duration = optional_param('duration', 1, PARAM_INT);
$all_day = optional_param('all_day', false, PARAM_BOOL);
$dur_units = optional_param('dur_units', $enable_periods ? 'periods' : 'hours', PARAM_ALPHA);

if ($year < 1970 || $year > 9999 || !checkdate($month, $day, $year)) {
    [$year, $month, $day] = explode('-', date('Y-n-j'));
}

$PAGE->set_url(new moodle_url('/blocks/mrbs/web/roomsearch.php', compact('day', 'month', 'year', 'area')));
require_login();
$PAGE->set_context(context_system::instance());

$prerequisites = block_mrbs_require_prerequisites($day, $month, $year, $area, 0, false, true);
$area = $prerequisites->areaid;
print_header_mrbs($day, $month, $year, $area);

$units = [];
foreach ($enable_periods ? ['periods', 'days'] : ['minutes', 'hours', 'days', 'weeks'] as $unit) {
    $units[$unit] = get_string($unit, 'block_mrbs');
}
$periodoptions = [];
foreach ($periods ?? [] as $number => $label) {
    $periodoptions[$number] = s(html_entity_decode($label, ENT_QUOTES, 'UTF-8'));
}
$endpoint = new moodle_url('/blocks/mrbs/web/roomsearch_ss.php');
?>
<h2><?php echo get_string('roomsearch', 'block_mrbs'); ?></h2>

<form id="mrbs-room-search" class="card my-3"
    data-endpoint="<?php echo $endpoint->out(); ?>"
    data-booked="<?php echo s(get_string('roomsearchbooked', 'block_mrbs')); ?>"
    data-available="<?php echo s(get_string('roomsearchavailable', 'block_mrbs')); ?>"
    data-empty="<?php echo s(get_string('noroomsfound', 'block_mrbs')); ?>"
    data-window="<?php echo s(get_string('roomsearchwindow', 'block_mrbs')); ?>"
    data-error="<?php echo s(get_string('roomsearchfailed', 'block_mrbs')); ?>">
    <div class="card-body">
        <div class="form-row">
            <div class="form-group col-12 col-md-6 col-lg-3">
                <label class="font-weight-bold" for="mrbs-search-date"><?php echo get_string('date'); ?></label>
                <input class="form-control" type="date" id="mrbs-search-date" name="date" required
                    value="<?php echo sprintf('%04d-%02d-%02d', $year, $month, $day); ?>">
            </div>

            <div class="form-group col-12 col-md-6 col-lg-3">
                <?php if ($enable_periods) { ?>
                    <label class="font-weight-bold" for="mrbs-search-period"><?php echo get_string('period', 'block_mrbs'); ?></label>
                    <?php echo html_writer::select($periodoptions, 'period', $period, false, [
                        'id' => 'mrbs-search-period', 'class' => 'custom-select w-100',
                    ]); ?>
                <?php } else { ?>
                    <label class="font-weight-bold" for="mrbs-search-time"><?php echo get_string('time'); ?></label>
                    <input class="form-control" type="time" id="mrbs-search-time" name="time" required
                        value="<?php echo sprintf('%02d:%02d', $start_hour, $start_min); ?>">
                <?php } ?>
            </div>

            <div class="form-group col-12 col-md-6 col-lg-4">
                <label class="font-weight-bold" for="mrbs-search-duration"><?php echo get_string('duration', 'block_mrbs'); ?></label>
                <div class="input-group">
                    <input class="form-control" type="number" min="1" id="mrbs-search-duration" name="duration" required
                        value="<?php echo max(1, $duration); ?>">
                    <?php echo html_writer::select($units, 'dur_units', $dur_units, false, [
                        'class' => 'custom-select', 'aria-label' => get_string('duration', 'block_mrbs'),
                    ]); ?>
                </div>
            </div>

            <div class="form-group col-12 col-md-6 col-lg-2">
                <label class="font-weight-bold" for="mrbs-search-mincap"><?php echo get_string('mincapacity', 'block_mrbs'); ?></label>
                <input class="form-control" type="number" min="0" id="mrbs-search-mincap" name="mincap">
            </div>
        </div>

        <div class="d-flex flex-wrap mb-3">
            <?php foreach ([
                'all_day' => ['all_day', $all_day],
                'teaching' => ['teachingroom', false],
                'special' => ['specialroom', true],
                'computer' => ['computerroom', false],
            ] as $field => [$label, $checked]) { ?>
                <div class="form-check form-check-inline mb-2">
                    <input class="form-check-input" type="checkbox" id="mrbs-search-<?php echo $field; ?>"
                        name="<?php echo $field; ?>" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="mrbs-search-<?php echo $field; ?>">
                        <?php echo get_string($label, 'block_mrbs'); ?>
                    </label>
                </div>
            <?php } ?>
        </div>
        <button class="btn btn-primary" type="submit"><?php echo get_string('search'); ?></button>
    </div>
</form>

<section aria-labelledby="mrbs-room-results">
    <h3 id="mrbs-room-results" class="h5"><?php echo get_string('roomsearchresults', 'block_mrbs'); ?></h3>
    <p id="mrbs-room-window" class="text-muted" aria-live="polite" hidden></p>
    <div id="mrbs-room-status" role="status" aria-live="polite" hidden></div>
    <div id="mrbs-room-table" class="table-responsive" hidden>
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th scope="col"><?php echo get_string('area', 'block_mrbs'); ?></th>
                    <th scope="col"><?php echo get_string('room', 'block_mrbs'); ?></th>
                    <th scope="col"><?php echo get_string('description'); ?></th>
                    <th scope="col"><?php echo get_string('capacity', 'block_mrbs'); ?></th>
                    <th scope="col"><?php echo get_string('roomsearchstatus', 'block_mrbs'); ?></th>
                </tr>
            </thead>
            <tbody id="mrbs-room-rows"></tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/trailer.php';
