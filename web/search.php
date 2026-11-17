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
global $PAGE, $DB;
include "config.inc.php";
include "functions.php";

$day = optional_param('day', 0, PARAM_INT);
$month = optional_param('month', 0, PARAM_INT);
$year = optional_param('year', 0, PARAM_INT);
$area = optional_param('area', 0, PARAM_INT);
$advanced = optional_param('advanced', 0, PARAM_BOOL);
$search_str = optional_param('search_str', 0, PARAM_TEXT); //may break some searches due to over-checking -ab.
$total = optional_param('total', 0, PARAM_INT);
$search_pos = optional_param('search_pos', 0, PARAM_INT);

//If we dont know the right date then make it up
if (($day == 0) or ($month == 0) or ($year == 0)) {
    $day = date("d");
    $month = date("m");
    $year = date("Y");
}

$thisurl = new moodle_url('/blocks/mrbs/web/search.php', array('day' => $day, 'month' => $month, 'year' => $year));
if ($area) {
    $thisurl->param('area', $area);
} else {
    $area = get_default_area();
}
if ($advanced) {
    $thisurl->param('advanced', $advanced);
}
if ($search_str) {
    $thisurl->param('search_str', $search_str);
}
if ($search_pos) {
    $thisurl->param('search_pos', $search_pos);
}
$PAGE->set_url($thisurl);
require_login();

print_header_mrbs($day, $month, $year, $area);

if ($advanced || !$search_str) {
    if (!$advanced) {
        echo $OUTPUT->notification(get_string('invalid_search', 'block_mrbs'), 'warning');
    }
    ?>
    <h2><?php echo get_string('advanced_search', 'block_mrbs'); ?></h2>
    <form method="get" action="search.php" class="card my-3">
        <div class="card-body">
            <input type="hidden" name="area" value="<?php echo (int)$area; ?>">
            <div class="form-row">
                <div class="form-group col-12 col-md-6">
                    <label class="font-weight-bold" for="mrbs-search-query"><?php echo get_string('search_for', 'block_mrbs'); ?></label>
                    <input class="form-control" type="text" id="mrbs-search-query" name="search_str" required
                        value="<?php echo s($search_str ?: ''); ?>">
                </div>
                <fieldset class="form-group col-12 col-md-6">
                    <legend class="h6"><?php echo get_string('from', 'block_mrbs'); ?></legend>
                    <?php genDateSelector('', $day, $month, $year); ?>
                </fieldset>
            </div>
            <button class="btn btn-primary" type="submit"><?php echo get_string('search', 'block_mrbs'); ?></button>
        </div>
    </form>
    <?php
    include "trailer.php";
    exit;
}

echo $OUTPUT->heading(get_string('search_results', 'block_mrbs') . ' “' . s($search_str) . '”', 2);
$advancedurl = new moodle_url($thisurl, ['advanced' => 1]);
$advancedurl->remove_params('search_pos', 'total');
echo html_writer::div(html_writer::link($advancedurl, get_string('advanced_search', 'block_mrbs'), [
    'class' => 'btn btn-outline-secondary',
]), 'mb-3');

$now = mktime(0, 0, 0, $month, $day, $year);

// This is the main part of the query predicate, used in both queries:
$sql_pred = "( ".$DB->sql_like("create_by", '?', false)
    ." OR ".$DB->sql_like("name", '?', false)
    ." OR ".$DB->sql_like("description", '?', false)
    .") AND end_time > ?";
$params = array($search_str, $search_str, $search_str, $now);

// The first time the search is called, we get the total
// number of matches.  This is passed along to subsequent
// searches so that we don't have to run it for each page.
if (!$total) {
    $total = $DB->count_records_select('block_mrbs_entry', $sql_pred, $params);
    $thisurl->param('total', $total);
}

if ($total <= 0) {
    echo $OUTPUT->notification(get_string('nothingtodisplay', 'block_mrbs'), 'info');
    include "trailer.php";
    exit;
}

if ($search_pos <= 0) {
    $search_pos = 0;
} else if ($search_pos >= $total) {
    $search_pos = intdiv($total - 1, $search["count"]) * $search["count"];
}

$sql_pred = str_replace(array('create_by', 'name', 'description'),
                        array('e.create_by', 'e.name', 'e.description'), $sql_pred);

// Now we set up the "real" query using LIMIT to just get the stuff we want.
$sql = "SELECT e.id, e.create_by, e.name, e.description, e.courseid, e.facultyid, e.start_time, r.area_id, r.room_name
        FROM {block_mrbs_entry} e, {block_mrbs_room} r
        WHERE $sql_pred
        AND e.room_id = r.id
        ORDER BY e.start_time asc ";

// this is a flag to tell us not to display a "Next" link
$result = $DB->get_records_sql($sql, $params, $search_pos, $search['count']);
$num_records = count($result);

$has_prev = $search_pos > 0;
$has_next = $search_pos < ($total - $search["count"]);

if ($has_prev || $has_next) {
    echo html_writer::start_div('d-flex flex-wrap align-items-center mb-3');
    echo html_writer::span(get_string('records', 'block_mrbs') . ($search_pos + 1) . get_string('through', 'block_mrbs')
        . ($search_pos + $num_records) . get_string('of', 'block_mrbs') . $total, 'mr-3');
    foreach ([
        'previous' => [$has_prev, max(0, $search_pos - $search['count'])],
        'next' => [$has_next, $search_pos + $search['count']],
    ] as $label => [$available, $position]) {
        if ($available) {
            $pageurl = new moodle_url($thisurl, ['search_pos' => $position, 'total' => $total]);
            echo html_writer::link($pageurl, get_string($label, 'block_mrbs'), ['class' => 'btn btn-sm btn-outline-secondary mr-2']);
        }
    }
    echo html_writer::end_div();
}
?>
<div class="table-responsive">
    <table class="table table-striped table-hover">
    <thead>
    <TR>
        <TH><?php echo get_string('entry', 'block_mrbs') ?></TH>
        <TH><?php echo get_string('createdby', 'block_mrbs') ?></TH>
        <TH><?php echo get_string('namebooker', 'block_mrbs') ?></TH>
        <TH><?php echo get_string('room', 'block_mrbs') ?></TH>
        <TH><?php echo get_string('description', 'block_mrbs') ?></TH>
        <TH><?php echo get_string('start_date', 'block_mrbs') ?></TH>
    </TR>
    </thead>
    <tbody>
<?php
foreach ($result as $entry) {
    $viewurl = new moodle_url('/blocks/mrbs/web/view_entry.php', array('id' => $entry->id));
    echo "<TR>";
    echo "<TD><A HREF=\"".$viewurl."\">".get_string('view', 'block_mrbs')."</A></TD>\n";
    echo "<TD>".s($entry->create_by)."</TD>\n";
    echo "<TD>".s(\block_mrbs\local\reservation::get_display_name($entry))."</TD>\n";
    echo "<TD>".s($entry->room_name)."</TD>\n";
    echo "<TD>".s($entry->description)."</TD>\n";
    // generate a link to the day.php
    $link = getdate($entry->start_time);
    $dayurl = new moodle_url('/blocks/mrbs/web/day.php',
                             array(
                                 'day' => $link['mday'], 'month' => $link['mon'], 'year' => $link['year'],
                                 'area' => $entry->area_id
                             ));
    echo "<TD><A HREF=\"".$dayurl."\">";
    if (empty($enable_periods)) {
        $link_str = time_date_string($entry->start_time);
    } else {
        list(, $link_str) = period_date_string($entry->start_time);
    }
    echo "$link_str</A></TD>";
    echo "</TR>\n";
}

echo "</tbody></table></div>\n";
include "trailer.php";
