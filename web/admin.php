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

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php'); //for Moodle integration
global $PAGE, $CFG, $DB;
require "config.inc.php";
require "functions.php";
require_once("mrbs_auth.php");

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);
$area_name = optional_param('area_name', '', PARAM_TEXT);

//If we dont know the right date then make it up
if (($day == 0) or ($month == 0) or ($year == 0)) {
    $day = \block_mrbs\local\booking_time::date("d");
    $month = \block_mrbs\local\booking_time::date("m");
    $year = \block_mrbs\local\booking_time::date("Y");
}

$thisurl = new moodle_url('/blocks/mrbs/web/admin.php', array('day' => $day, 'month' => $month, 'year' => $year));
if ($area) {
    $thisurl->param('area', $area);
} else {
    $area = get_default_area();
}

$PAGE->set_url($thisurl);
require_login();
$PAGE->set_context(context_system::instance());

if (!getAuthorised(2)) {
    showAccessDenied($day, $month, $year, $area);
    exit();
}

print_header_mrbs($day, $month, $year, isset($area) ? $area : "");

// If area is set but area name is not known, get the name.
if ($area) {
    if (empty($area_name)) {
        $dbarea = $DB->get_record('block_mrbs_area', array('id' => $area), 'area_name', MUST_EXIST);
        $area_name = $dbarea->area_name;
    }
}
$areas = $DB->get_records('block_mrbs_area', null, 'area_name');
$rooms = $area ? $DB->get_records('block_mrbs_room', ['area_id' => $area], 'room_name') : [];
$addareaurl = new moodle_url('/blocks/mrbs/web/add.php', [
    'type' => 'area', 'sesskey' => sesskey(), 'day' => $day, 'month' => $month, 'year' => $year,
]);
$addroomurl = new moodle_url($addareaurl, ['type' => 'room', 'area' => $area]);

$listitem = static function ($label, $editurl, $deleteurl) {
    $actions = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-outline-secondary mr-2'])
        . html_writer::link($deleteurl, get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']);
    return html_writer::div(
        html_writer::span($label, 'mr-3') . html_writer::span($actions, 'text-nowrap'),
        'list-group-item d-flex flex-wrap justify-content-between align-items-center'
    );
};
?>
<h2><?php echo get_string('administration'); ?></h2>
<div class="row mt-3">
    <div class="col-12 col-lg-6 mb-3">
        <section class="card mb-3">
            <div class="card-body">
                <h3 class="h5"><?php echo get_string('areas', 'block_mrbs'); ?></h3>
                <?php if (!$areas) { ?>
                    <p class="text-muted mb-0"><?php echo get_string('noareas', 'block_mrbs'); ?></p>
                <?php } else { ?>
                    <div class="list-group">
                        <?php foreach ($areas as $dbarea) {
                            $params = ['area' => $dbarea->id, 'sesskey' => sesskey()];
                            $adminurl = new moodle_url($thisurl, ['area' => $dbarea->id]);
                            $editurl = new moodle_url('/blocks/mrbs/web/edit_area_room.php', $params);
                            $deleteurl = new moodle_url('/blocks/mrbs/web/del.php', $params + ['type' => 'area']);
                            $attributes = $dbarea->id == $area
                                ? ['class' => 'font-weight-bold', 'aria-current' => 'page'] : [];
                            echo $listitem(html_writer::link($adminurl, s($dbarea->area_name), $attributes), $editurl, $deleteurl);
                        } ?>
                    </div>
                <?php } ?>
            </div>
        </section>

        <form class="card" method="post" action="<?php echo $addareaurl->out_omit_querystring(); ?>"
            onsubmit="return this.elements['name'].value.trim().length > 0;">
            <div class="card-body">
                <h3 class="h5"><?php echo get_string('addarea', 'block_mrbs'); ?></h3>
                <?php echo html_writer::input_hidden_params($addareaurl); ?>
                <div class="form-group">
                    <label for="mrbs-area-name"><?php echo get_string('name'); ?></label>
                    <input class="form-control" id="mrbs-area-name" name="name" required maxlength="30" pattern=".*\S.*">
                </div>
                <button class="btn btn-primary" type="submit"><?php echo get_string('addarea', 'block_mrbs'); ?></button>
            </div>
        </form>
    </div>

    <div class="col-12 col-lg-6 mb-3">
        <section class="card mb-3">
            <div class="card-body">
                <h3 class="h5"><?php echo get_string('rooms', 'block_mrbs');
                    if ($area_name !== '') {
                        echo ' ' . get_string('in', 'block_mrbs') . ' ' . s($area_name);
                    } ?></h3>
                <?php if (!$rooms) { ?>
                    <p class="text-muted mb-0"><?php echo get_string($area ? 'norooms' : 'noarea', 'block_mrbs'); ?></p>
                <?php } else { ?>
                    <div class="list-group">
                        <?php foreach ($rooms as $dbroom) {
                            $params = ['room' => $dbroom->id, 'sesskey' => sesskey()];
                            $editurl = new moodle_url('/blocks/mrbs/web/edit_area_room.php', $params);
                            $deleteurl = new moodle_url('/blocks/mrbs/web/del.php', $params + ['type' => 'room', 'area' => $area]);
                            $details = array_filter([trim((string)$dbroom->description), $dbroom->capacity]);
                            $label = s($dbroom->room_name);
                            if ($details) {
                                $label .= ' ' . html_writer::span('(' . s(implode(', ', $details)) . ')', 'text-muted');
                            }
                            echo $listitem($label, $editurl, $deleteurl);
                        } ?>
                    </div>
                <?php } ?>
            </div>
        </section>

        <?php if ($area) { ?>
            <form class="card" method="post" action="<?php echo $addroomurl->out_omit_querystring(); ?>"
                onsubmit="return this.elements['name'].value.trim().length > 0;">
                <div class="card-body">
                    <h3 class="h5"><?php echo get_string('addroom', 'block_mrbs'); ?></h3>
                    <?php echo html_writer::input_hidden_params($addroomurl); ?>
                    <div class="form-group">
                        <label for="mrbs-room-name"><?php echo get_string('name'); ?></label>
                        <input class="form-control" id="mrbs-room-name" name="name" required maxlength="25" pattern=".*\S.*">
                    </div>
                    <div class="form-group">
                        <label for="mrbs-room-description"><?php echo get_string('description'); ?></label>
                        <input class="form-control" id="mrbs-room-description" name="description">
                    </div>
                    <div class="form-group">
                        <label for="mrbs-room-capacity"><?php echo get_string('capacity', 'block_mrbs'); ?></label>
                        <input class="form-control" type="number" min="0" id="mrbs-room-capacity" name="capacity">
                    </div>
                    <button class="btn btn-primary" type="submit"><?php echo get_string('addroom', 'block_mrbs'); ?></button>
                </div>
            </form>
        <?php } ?>
    </div>
</div>
<?php include 'trailer.php';
