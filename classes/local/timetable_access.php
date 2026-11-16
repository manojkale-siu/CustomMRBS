<?php
// This file is part of Moodle - https://moodle.org/

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves access for the current Moodle user.
 */
class timetable_access {
    /** @var array|null Current user's system roles and eligible enrolments. */
    private static $profile;

    private static function is_teacher(array $roles) {
        return isset($roles['teacher']) || isset($roles['editingteacher']);
    }

    private static function profile() {
        global $DB, $USER;

        if (self::$profile !== null) {
            return self::$profile;
        }

        $system = [];
        $bycourse = [];
        $learner = false;
        $assignments = $DB->get_records_sql("SELECT ra.id, ctx.contextlevel, ctx.instanceid, r.shortname, r.archetype
                                               FROM {role_assignments} ra
                                               JOIN {context} ctx ON ctx.id = ra.contextid
                                               JOIN {role} r ON r.id = ra.roleid
                                              WHERE ra.userid = :userid
                                                AND ctx.contextlevel IN (:systemlevel, :courselevel)", [
            'userid' => $USER->id, 'systemlevel' => CONTEXT_SYSTEM, 'courselevel' => CONTEXT_COURSE,
        ]);
        foreach ($assignments as $role) {
            $types = array_fill_keys(array_filter([$role->shortname, $role->archetype]), true);
            $learner = $learner || self::is_teacher($types) || isset($types['student']);
            if ((int)$role->contextlevel === CONTEXT_SYSTEM) {
                $system += $types;
            } else {
                $bycourse[$role->instanceid] = ($bycourse[$role->instanceid] ?? []) + $types;
            }
        }

        return self::$profile = ['system' => $system, 'learner' => $learner, 'bycourse' => $bycourse];
    }

    public static function is_admin() {
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        $roles = self::profile()['system'];
        return isset($roles['manager']) || isset($roles['mrbsadmin'])
            || has_capability('block/mrbs:administermrbs', \context_system::instance());
    }

    public static function can_edit() {
        if (self::is_admin()) {
            return true;
        }
        if (!self::can_view_calendar()) {
            return false;
        }
        $profile = self::profile();
        if ($profile['learner'] && !isset($profile['system']['mrbseditor'])) {
            return false;
        }
        $context = \context_system::instance();
        return has_capability('block/mrbs:editmrbs', $context)
            || has_capability('block/mrbs:editunconfirmed', $context, null, false);
    }

    public static function can_view_other_timetables() {
        return self::is_admin() || (!self::profile()['learner']
            && self::can_view_calendar() && has_capability('block/mrbs:viewalltt', \context_system::instance()));
    }

    public static function personal_only() {
        if (!isloggedin() || isguestuser() || self::is_admin()) {
            return false;
        }
        $profile = self::profile();
        return $profile['learner'] && !isset($profile['system']['mrbsviewer'])
            && !isset($profile['system']['mrbseditor']);
    }

    public static function can_view_calendar() {
        if (!isloggedin() || isguestuser() || self::personal_only()) {
            return false;
        }
        $context = \context_system::instance();
        return self::is_admin() || has_any_capability([
            'block/mrbs:viewmrbs', 'block/mrbs:editmrbs', 'block/mrbs:editunconfirmed',
        ], $context);
    }

    public static function require_calendar() {
        if (!self::can_view_calendar()) {
            throw new \moodle_exception('accessdenied', 'block_mrbs');
        }
    }

    public static function can_view_personal() {
        return isloggedin() && !isguestuser() && (self::profile()['learner'] || self::can_view_calendar());
    }

    public static function entries($start, $end) {
        global $DB, $USER;

        $teacher = [];
        $student = [];
        $profile = self::profile();
        foreach (enrol_get_users_courses($USER->id, true) as $course) {
            $roles = $profile['bycourse'][$course->id] ?? [];
            if (!self::is_teacher($roles) && !isset($roles['student'])) {
                $roles = $profile['system'];
            }
            if (self::is_teacher($roles)) {
                $teacher[] = $course->id;
            } else if (isset($roles['student'])) {
                $student[] = $course->id;
            }
        }
        $conditions = [];
        $params = ['starttime' => $start, 'endtime' => $end];
        if ($teacher) {
            [$insql, $inparams] = $DB->get_in_or_equal($teacher, SQL_PARAMS_NAMED, 'teach');
            $conditions[] = "(e.courseid $insql AND e.facultyid = :faculty)";
            $params += $inparams + ['faculty' => $USER->id];
        }
        if ($student) {
            [$insql, $inparams] = $DB->get_in_or_equal($student, SQL_PARAMS_NAMED, 'stud');
            $conditions[] = "(e.courseid $insql AND (e.groupid = 0 OR EXISTS (
                SELECT 1 FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE gm.userid = :student AND g.id = e.groupid AND g.courseid = e.courseid)))";
            $params += $inparams + ['student' => $USER->id];
        }
        if (!$conditions) {
            return [];
        }

        $visibility = implode(' OR ', $conditions);
        return $DB->get_records_sql("SELECT e.id, e.courseid, e.facultyid, e.groupid, e.start_time, e.end_time, r.room_name
                                       FROM {block_mrbs_entry} e
                                       JOIN {block_mrbs_room} r ON r.id = e.room_id
                                      WHERE e.start_time < :endtime AND e.end_time > :starttime
                                        AND ($visibility)
                                   ORDER BY e.start_time, e.end_time, e.id", $params);
    }
}
