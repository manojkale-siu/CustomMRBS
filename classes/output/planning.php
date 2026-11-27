<?php
namespace block_mrbs\output;
defined('MOODLE_INTERNAL') || die();

/** Common accessible plan listing for faculty and enrolled viewers. */
final class planning {
    public static function table(array $plans, ?\moodle_url $editurl = null): string {
        global $USER;
        $table = new \html_table();
        $table->attributes['class'] = 'table table-striped';
        $table->head = array_map(static function ($key) { return get_string($key, 'block_mrbs'); },
            ['planningdate', 'faculty', 'planningtopics', 'planningmethodology', 'planningresource', 'planningassessment', 'planningremarks']);
        if ($editurl) {
            $table->head[] = get_string('planningactions', 'block_mrbs');
        }
        foreach ($plans as $plan) {
            $row = [s(\block_mrbs\local\booking_time::date('Y-m-d', $plan->proposeddate)),
                s(\block_mrbs\local\reservation::get_user_fullname($plan->facultyid)), nl2br(s($plan->topics)),
                s($plan->methodology), \block_mrbs\local\faculty_planning::resource_link($plan),
                s($plan->assessment), nl2br(s($plan->remarks ?? ''))];
            if ($editurl && (int)$plan->facultyid === (int)$USER->id) {
                $edit = \html_writer::link(new \moodle_url($editurl, ['editid' => $plan->id]), get_string('edit'),
                    ['class' => 'btn btn-secondary btn-sm mb-2']);
                $delete = '<form method="post" action="' . $editurl->out() . '"><input type="hidden" name="sesskey" value="' . sesskey()
                    . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="planid" value="' . $plan->id
                    . '"><button class="btn btn-outline-danger btn-sm" type="submit">' . get_string('delete') . '</button></form>';
                $row[] = $edit . $delete;
            }
            $table->data[] = $row;
        }
        return \html_writer::div(\html_writer::table($table), 'table-responsive');
    }
}
