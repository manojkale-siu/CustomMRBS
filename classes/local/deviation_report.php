<?php
namespace block_mrbs\local;
defined('MOODLE_INTERNAL') || die();

/** Counts actual Attendance evidence; proposal dates never imply attendance was taken. */
final class deviation_report {
    public static function topics_differ(string $planned, string $taught): bool {
        return preg_replace('/\s+/u', ' ', trim($planned)) !== preg_replace('/\s+/u', ' ', trim($taught));
    }

    public static function data(int $courseid = 0, int $facultyid = 0): array {
        global $DB;
        director_access::require_director();
        $where = ['1=1']; $params = [];
        if ($courseid) { $where[] = 'courseid = :courseid'; $params['courseid'] = $courseid; }
        if ($facultyid) { $where[] = 'facultyid = :facultyid'; $params['facultyid'] = $facultyid; }
        $plans = $DB->get_records_select('block_mrbs_faculty_plan', implode(' AND ', $where), $params, 'courseid, facultyid, proposeddate, id');
        $summary = []; $detail = []; $planids = [];
        $today = booking_time::mktime(0, 0, 0);
        foreach ($plans as $plan) {
            $key = $plan->courseid . ':' . $plan->facultyid;
            if (!isset($summary[$key])) {
                $course = $DB->get_record('course', ['id' => $plan->courseid], '*', MUST_EXIST);
                $user = $DB->get_record('user', ['id' => $plan->facultyid], '*', MUST_EXIST);
                $summary[$key] = ['course' => $course, 'faculty' => $user, 'planned' => 0,
                    'conducted' => 0, 'completed' => [], 'overdue' => 0, 'differences' => 0, 'remarks' => []];
            }
            $summary[$key]['planned']++;
            $planids[$plan->id] = $key;
            if (!empty($plan->remarks)) { $summary[$key]['remarks'][$plan->remarks] = $plan->remarks; }
        }
        $usewhere = $where;
        $usewhere[] = 'confirmedat > 0';
        foreach ($DB->get_records_select('block_mrbs_plan_use', implode(' AND ', $usewhere), $params, 'conducteddate, id') as $use) {
            if (!isset($planids[$use->planid])) { continue; }
            $key = $planids[$use->planid];
            $summary[$key]['conducted']++;
            $summary[$key]['completed'][$use->planid] = true;
            $datediff = booking_time::date('Y-m-d', $use->proposeddate) !== booking_time::date('Y-m-d', $use->conducteddate);
            $topicdiff = self::topics_differ($use->plannedtopics, $use->taughttopics);
            if ($datediff || $topicdiff) { $summary[$key]['differences']++; }
            $detail[] = ['use' => $use, 'course' => $summary[$key]['course'], 'faculty' => $summary[$key]['faculty'],
                'datediff' => $datediff, 'topicdiff' => $topicdiff];
        }
        foreach ($plans as $plan) {
            $key = $planids[$plan->id];
            if ($plan->proposeddate < $today && !isset($summary[$key]['completed'][$plan->id])) {
                $summary[$key]['overdue']++;
            }
        }
        // Historical or unpatched Attendance is counted separately, never presented as verified evidence.
        $unmapped = null;
        if ($DB->get_manager()->table_exists(new \xmldb_table('attendance_sessions'))) {
            $sql = 'SELECT COUNT(1) FROM {attendance_sessions} s JOIN {attendance} a ON a.id=s.attendanceid
                WHERE (s.lasttaken > 0 OR EXISTS (SELECT 1 FROM {attendance_log} al WHERE al.sessionid=s.id))
                AND NOT EXISTS (SELECT 1 FROM {block_mrbs_plan_use} pu WHERE pu.sessionid=s.id AND pu.confirmedat > 0)';
            $p = [];
            if ($courseid) { $sql .= ' AND a.course = :courseid'; $p['courseid'] = $courseid; }
            if ($facultyid) {
                $sql .= ' AND EXISTS (SELECT 1 FROM {block_mrbs_entry} e WHERE e.attendancesessionid=s.id AND e.facultyid=:facultyid)';
                $p['facultyid'] = $facultyid;
            }
            $unmapped = (int)$DB->count_records_sql($sql, $p);
        }
        return compact('summary', 'detail', 'unmapped');
    }
}
