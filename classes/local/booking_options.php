<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Data provider and validator for MRBS dependent booking selectors.
 *
 * The category hierarchy used by this site is:
 * Programme -> Batch -> Semester -> Course.
 *
 * @package   block_mrbs
 */
class booking_options {

    /** @var array Teaching role shortnames used to identify faculty. */
    private const FACULTY_ROLES = ['teacher', 'editingteacher', 'coursecreator'];

    /**
     * Get top-level programme categories.
     *
     * @return array
     */
    public static function get_programmes() {
        return self::category_options(0);
    }

    /**
     * Get batches for a programme category.
     *
     * @param int $programmeid
     * @return array
     */
    public static function get_batches($programmeid) {
        return self::category_options($programmeid);
    }

    /**
     * Get semesters for a batch category.
     *
     * @param int $batchid
     * @return array
     */
    public static function get_semesters($batchid) {
        return self::category_options($batchid);
    }

    /**
     * Get attendance-enabled courses below a semester.
     *
     * @param int $semesterid
     * @return array
     */
    public static function get_courses($semesterid) {
        global $DB;

        $semesterid = (int)$semesterid;
        if (!$semesterid || !$DB->record_exists('course_categories', ['id' => $semesterid])) {
            return [];
        }

        $pathlike = '%/' . $semesterid . '/%';
        $params = [
            'siteid' => SITEID,
            'semesterid' => $semesterid,
            'pathlike' => $pathlike,
        ];

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
                  FROM {course} c
                  JOIN {course_categories} cc ON cc.id = c.category
                  JOIN {attendance} a ON a.course = c.id
                 WHERE c.id <> :siteid
                   AND (c.category = :semesterid OR " . $DB->sql_like('cc.path', ':pathlike', false) . ")
              ORDER BY c.fullname ASC";

        $courses = $DB->get_records_sql($sql, $params);
        $results = [];
        foreach ($courses as $course) {
            $results[] = [
                'id' => (int)$course->id,
                'label' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
                'shortname' => $course->shortname,
            ];
        }

        return $results;
    }

    /**
     * Get teaching faculty assigned to the selected course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_faculties($courseid) {
        global $DB;

        $courseid = (int)$courseid;
        if (!$courseid || !$DB->record_exists('course', ['id' => $courseid])) {
            return [];
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal(self::FACULTY_ROLES, SQL_PARAMS_NAMED, 'facultyrole');
        $userfields = reservation::user_name_fields_sql('u');
        $params = $roleparams + [
            'courseid' => $courseid,
            'contextlevel' => CONTEXT_COURSE,
        ];

        $sql = "SELECT DISTINCT u.id, u.email, $userfields
                  FROM {user} u
                  JOIN {role_assignments} ra ON ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND ctx.contextlevel = :contextlevel
                   AND ctx.instanceid = :courseid
                   AND r.shortname $rolesql
              ORDER BY u.lastname ASC, u.firstname ASC";

        $users = $DB->get_records_sql($sql, $params);
        $results = [];
        foreach ($users as $user) {
            $results[] = [
                'id' => (int)$user->id,
                'label' => fullname($user),
            ];
        }

        return $results;
    }

    /**
     * Get groups for a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_groups($courseid) {
        $courseid = (int)$courseid;
        if (!$courseid) {
            return [];
        }

        $groups = groups_get_all_groups($courseid);
        $results = [];
        foreach ($groups as $group) {
            $results[] = [
                'id' => (int)$group->id,
                'label' => format_string($group->name),
            ];
        }

        return $results;
    }

    /**
     * Return programme/batch/semester ids for a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_course_hierarchy($courseid) {
        global $DB;

        $hierarchy = [
            'programmeid' => 0,
            'batchid' => 0,
            'semesterid' => 0,
        ];

        $course = $DB->get_record('course', ['id' => (int)$courseid], 'id, category', IGNORE_MISSING);
        if (!$course) {
            return $hierarchy;
        }

        $category = $DB->get_record('course_categories', ['id' => $course->category], 'id, path', IGNORE_MISSING);
        if (!$category || empty($category->path)) {
            return $hierarchy;
        }

        $pathids = array_values(array_filter(array_map('intval', explode('/', trim($category->path, '/')))));
        if (count($pathids) >= 1) {
            $hierarchy['programmeid'] = $pathids[0];
        }
        if (count($pathids) >= 2) {
            $hierarchy['batchid'] = $pathids[1];
        }
        if (count($pathids) >= 3) {
            $hierarchy['semesterid'] = $pathids[2];
        } else if (count($pathids) >= 1) {
            $hierarchy['semesterid'] = end($pathids);
        }

        return $hierarchy;
    }

    /**
     * Check whether a user is a faculty member in at least one course.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_faculty($userid) {
        global $DB;

        $userid = (int)$userid;
        if (!$userid) {
            return false;
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal(self::FACULTY_ROLES, SQL_PARAMS_NAMED, 'mrbsfacultyrole');
        $params = $roleparams + [
            'userid' => $userid,
            'coursecontext' => CONTEXT_COURSE,
            'systemcontext' => CONTEXT_SYSTEM,
        ];

        $sql = "SELECT 1
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid
                   AND r.shortname $rolesql
                   AND ctx.contextlevel IN (:coursecontext, :systemcontext)";

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Check whether a user is teaching the selected course.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function is_faculty_for_course($userid, $courseid) {
        global $DB;

        $userid = (int)$userid;
        $courseid = (int)$courseid;
        if (!$userid || !$courseid) {
            return false;
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal(self::FACULTY_ROLES, SQL_PARAMS_NAMED, 'coursefacultyrole');
        $params = $roleparams + [
            'userid' => $userid,
            'courseid' => $courseid,
            'contextlevel' => CONTEXT_COURSE,
        ];

        $sql = "SELECT 1
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid
                   AND ctx.contextlevel = :contextlevel
                   AND ctx.instanceid = :courseid
                   AND r.shortname $rolesql";

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Check whether a group belongs to a course.
     *
     * @param int $groupid
     * @param int $courseid
     * @return bool
     */
    public static function group_belongs_to_course($groupid, $courseid) {
        global $DB;

        $groupid = (int)$groupid;
        if ($groupid === 0) {
            return true;
        }

        return $DB->record_exists('groups', ['id' => $groupid, 'courseid' => (int)$courseid]);
    }

    /**
     * Check whether the selected course has an attendance activity.
     *
     * @param int $courseid
     * @return bool
     */
    public static function course_has_attendance($courseid) {
        global $DB;

        return $DB->record_exists('attendance', ['course' => (int)$courseid]);
    }

    /**
     * Validate the submitted hierarchy.
     *
     * @param int $programmeid
     * @param int $batchid
     * @param int $semesterid
     * @param int $courseid
     * @param int $facultyid
     * @param int $groupid
     * @return array
     */
    public static function validate_selection($programmeid, $batchid, $semesterid, $courseid, $facultyid, $groupid) {
        global $DB;

        $errors = [];
        $programmeid = (int)$programmeid;
        $batchid = (int)$batchid;
        $semesterid = (int)$semesterid;
        $courseid = (int)$courseid;
        $facultyid = (int)$facultyid;
        $groupid = (int)$groupid;

        if (!$programmeid || !$DB->record_exists('course_categories', ['id' => $programmeid, 'parent' => 0])) {
            $errors[] = get_string('invalidprogramme', 'block_mrbs');
        }
        if (!$batchid || !$DB->record_exists('course_categories', ['id' => $batchid, 'parent' => $programmeid])) {
            $errors[] = get_string('invalidbatch', 'block_mrbs');
        }
        if (!$semesterid || !$DB->record_exists('course_categories', ['id' => $semesterid, 'parent' => $batchid])) {
            $errors[] = get_string('invalidsemester', 'block_mrbs');
        }
        if (!$courseid || !$DB->record_exists('course', ['id' => $courseid]) || $courseid == SITEID) {
            $errors[] = get_string('invalidcourse', 'block_mrbs');
        } else {
            if (!self::course_in_semester($courseid, $semesterid)) {
                $errors[] = get_string('invalidcoursehierarchy', 'block_mrbs');
            }
            if (!self::course_has_attendance($courseid)) {
                $errors[] = get_string('courseattendancemissing', 'block_mrbs');
            }
        }
        if (!$facultyid || !self::is_faculty_for_course($facultyid, $courseid)) {
            $errors[] = get_string('invalidfacultycourse', 'block_mrbs');
        }
        if ($courseid && $groupid === 0 && !empty(self::get_groups($courseid))) {
            $errors[] = get_string('invalidgroup', 'block_mrbs');
        } else if (!self::group_belongs_to_course($groupid, $courseid)) {
            $errors[] = get_string('invalidgroup', 'block_mrbs');
        }

        return $errors;
    }

    /**
     * Legacy autocomplete course search.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public static function search_courses($query, $limit = 20) {
        global $DB;

        $query = trim($query);
        if (\core_text::strlen($query) < 2) {
            return [];
        }

        $params = [
            'siteid' => SITEID,
            'search' => '%' . $DB->sql_like_escape(\core_text::strtolower($query)) . '%',
        ];

        $sql = "SELECT c.id, c.fullname, c.shortname
                  FROM {course} c
                 WHERE c.id <> :siteid
                   AND " . $DB->sql_like('LOWER(c.fullname)', ':search', false) . "
              ORDER BY c.fullname ASC";

        $courses = $DB->get_records_sql($sql, $params, 0, $limit);
        $results = [];
        foreach ($courses as $course) {
            $results[] = [
                'id' => (int)$course->id,
                'label' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
                'shortname' => $course->shortname,
            ];
        }

        return $results;
    }

    /**
     * Legacy autocomplete faculty search.
     *
     * @param string $query
     * @param int $limit
     * @return array
     */
    public static function search_faculties($query, $limit = 20) {
        global $DB;

        $query = trim($query);
        if (\core_text::strlen($query) < 2) {
            return [];
        }

        [$rolesql, $roleparams] = $DB->get_in_or_equal(self::FACULTY_ROLES, SQL_PARAMS_NAMED, 'facultyrole');
        $userfields = reservation::user_name_fields_sql('u');
        $params = $roleparams + [
            'coursecontext' => CONTEXT_COURSE,
            'systemcontext' => CONTEXT_SYSTEM,
            'search0' => '%' . $DB->sql_like_escape(\core_text::strtolower($query)) . '%',
            'search1' => '%' . $DB->sql_like_escape(\core_text::strtolower($query)) . '%',
            'search2' => '%' . $DB->sql_like_escape(\core_text::strtolower($query)) . '%',
            'search3' => '%' . $DB->sql_like_escape(\core_text::strtolower($query)) . '%',
        ];

        $searchsql = '(' .
            $DB->sql_like('LOWER(u.firstname)', ':search0', false) . ' OR ' .
            $DB->sql_like('LOWER(u.lastname)', ':search1', false) . ' OR ' .
            $DB->sql_like('LOWER(u.email)', ':search2', false) . ' OR ' .
            $DB->sql_like('LOWER(u.username)', ':search3', false) . ')';

        $sql = "SELECT DISTINCT u.id, u.email, $userfields
                  FROM {user} u
                  JOIN {role_assignments} ra ON ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND r.shortname $rolesql
                   AND ctx.contextlevel IN (:coursecontext, :systemcontext)
                   AND $searchsql
              ORDER BY u.lastname ASC, u.firstname ASC";

        $users = $DB->get_records_sql($sql, $params, 0, $limit);
        $results = [];
        foreach ($users as $user) {
            $label = fullname($user);
            if (!empty($user->email)) {
                $label .= ' (' . $user->email . ')';
            }
            $results[] = [
                'id' => (int)$user->id,
                'label' => $label,
            ];
        }

        return $results;
    }

    /**
     * Get child category options.
     *
     * @param int $parentid
     * @return array
     */
    private static function category_options($parentid) {
        global $DB;

        $categories = $DB->get_records('course_categories', ['parent' => (int)$parentid], 'sortorder ASC, name ASC', 'id, name');
        $results = [];
        foreach ($categories as $category) {
            $results[] = [
                'id' => (int)$category->id,
                'label' => format_string($category->name),
            ];
        }

        return $results;
    }

    /**
     * Check whether a course is directly or indirectly under a semester category.
     *
     * @param int $courseid
     * @param int $semesterid
     * @return bool
     */
    private static function course_in_semester($courseid, $semesterid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => (int)$courseid], 'id, category', IGNORE_MISSING);
        if (!$course) {
            return false;
        }
        if ((int)$course->category === (int)$semesterid) {
            return true;
        }

        $category = $DB->get_record('course_categories', ['id' => $course->category], 'id, path', IGNORE_MISSING);
        if (!$category || empty($category->path)) {
            return false;
        }

        $ids = array_filter(array_map('intval', explode('/', trim($category->path, '/'))));
        return in_array((int)$semesterid, $ids, true);
    }
}
