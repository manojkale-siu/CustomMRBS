<?php
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** Faculty-owned proposals, separate from room reservations and Attendance. */
final class faculty_planning {
    public const MAX_ROWS = 500;

    public static function teaches_course(int $userid, int $courseid): bool {
        global $DB;
        if (!$courseid || !$userid) {
            return false;
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user || !is_enrolled(\context_course::instance($courseid), $user, '', true)) {
            return false;
        }
        foreach (get_user_roles(\context_course::instance($courseid), $userid, true) as $role) {
            $archetype = $DB->get_field('role', 'archetype', ['id' => $role->roleid]);
            if (in_array($role->shortname, ['teacher', 'editingteacher'], true)
                    || in_array($archetype, ['teacher', 'editingteacher'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function courses(): array {
        global $USER;
        $result = [];
        foreach (enrol_get_users_courses($USER->id, true) as $course) {
            if (self::teaches_course((int)$USER->id, (int)$course->id)) {
                $result[$course->id] = $course;
            }
        }
        return $result;
    }

    public static function course_label(\stdClass $course): string {
        global $DB;
        $category = $DB->get_record('course_categories', ['id' => $course->category], 'id,path');
        $names = [];
        if ($category) {
            foreach (explode('/', trim($category->path, '/')) as $id) {
                $item = \core_course_category::get((int)$id, IGNORE_MISSING, true);
                if ($item) {
                    $names[] = html_to_text($item->get_formatted_name(), 0, false);
                }
            }
        }
        $names[] = html_to_text(format_string($course->fullname), 0, false);
        return implode(' / ', $names);
    }

    public static function viewable_courses(): array {
        global $USER, $DB;
        $user = $DB->get_record('user', ['id' => $USER->id, 'deleted' => 0, 'suspended' => 0]);
        if (!$user) {
            return [];
        }
        return array_filter(enrol_get_users_courses($USER->id, true), static function ($course) use ($user) {
            return is_enrolled(\context_course::instance($course->id), $user, '', true);
        });
    }

    public static function visible_plans(int $courseid): array {
        global $DB;
        if (!isset(self::viewable_courses()[$courseid])) {
            throw new \moodle_exception('planningviewdenied', 'block_mrbs');
        }
        return $DB->get_records('block_mrbs_faculty_plan', ['courseid' => $courseid], 'proposeddate, facultyid, id');
    }

    public static function require_course(int $courseid): void {
        global $USER;
        if (!self::teaches_course((int)$USER->id, $courseid)) {
            throw new \moodle_exception('planningfacultyonly', 'block_mrbs');
        }
    }

    public const METHODOLOGIES = ['Lecture', 'Discussion', 'Case Study', 'Group Activity',
        'Problem-Based Learning', 'Project-Based Learning', 'Flipped Classroom', 'Seminar',
        'Workshop', 'Demonstration', 'Lab / Practical', 'Simulation', 'Other'];
    public const ASSESSMENTS = ['Quiz', 'Assignment', 'Class Discussion', 'Presentation',
        'Case Analysis', 'Reflective Activity', 'Practical', 'Poll', 'Exit Ticket', 'Other'];

    /** Use Moodle visibility rules; a material link never grants access to a hidden activity. */
    public static function resources(int $courseid): array {
        global $USER;
        $result = [];
        foreach (get_fast_modinfo($courseid, $USER->id)->get_cms() as $cm) {
            if ($cm->uservisible && $cm->url && !$cm->deletioninprogress) {
                $result[$cm->id] = html_to_text(format_string($cm->name), 0, false);
            }
        }
        return $result;
    }

    public static function resource_link(\stdClass $plan): string {
        if (empty($plan->resourcecmid)) {
            return get_string('planningnoresource', 'block_mrbs');
        }
        $info = get_fast_modinfo($plan->courseid);
        if (!isset($info->cms[$plan->resourcecmid])) {
            return get_string('planningresourceunavailable', 'block_mrbs');
        }
        $cm = $info->get_cm($plan->resourcecmid);
        if (!$cm->uservisible || !$cm->url || $cm->deletioninprogress) {
            return get_string('planningresourceunavailable', 'block_mrbs');
        }
        return \html_writer::link($cm->url, format_string($cm->name),
            ['target' => '_blank', 'rel' => 'noopener noreferrer']);
    }

    /** A proposal has a date, not a reserved room or time interval. */
    public static function validate_row(array $values, int $courseid = 0): \stdClass {
        [$date, $topics, $methodology, $resource, $assessment, $remarks] = array_pad($values, 6, '');
        if (is_int($date) || is_float($date)) {
            excel_library::load();
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)->format('Y-m-d');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', (string)$date, $d)
                || (int)$d[1] < 1970 || !checkdate((int)$d[2], (int)$d[3], (int)$d[1])) {
            throw new \moodle_exception('planninginvaliddate', 'block_mrbs');
        }
        $topics = trim(clean_param((string)$topics, PARAM_TEXT));
        if ($topics === '' || \core_text::strlen($topics) > 10000) {
            throw new \moodle_exception('planningtopicsrequired', 'block_mrbs');
        }
        if (!in_array($methodology, self::METHODOLOGIES, true) || !in_array($assessment, self::ASSESSMENTS, true)) {
            throw new \moodle_exception('planninginvalidmethod', 'block_mrbs');
        }
        if (!preg_match('/^\d*$/D', (string)$resource)
                || ((int)$resource && !isset(self::resources($courseid)[(int)$resource]))) {
            throw new \moodle_exception('planninginvalidresource', 'block_mrbs');
        }
        $remarks = trim(clean_param((string)$remarks, PARAM_TEXT));
        if (\core_text::strlen($remarks) > 10000) {
            throw new \moodle_exception('planningremarklimit', 'block_mrbs');
        }
        return (object)['proposeddate' => booking_time::mktime(0, 0, 0, $d[2], $d[3], $d[1]),
            'topics' => $topics, 'methodology' => $methodology, 'resourcecmid' => (int)$resource,
            'assessment' => $assessment, 'remarks' => $remarks];
    }

    public static function plans(int $courseid): array {
        global $DB, $USER;
        self::require_course($courseid);
        return $DB->get_records('block_mrbs_faculty_plan', ['courseid' => $courseid, 'facultyid' => $USER->id], 'proposeddate, id');
    }

    /** Reject a forged plan ID or an edit to another faculty member's plan. */
    public static function owned_plan(int $id, int $courseid): \stdClass {
        global $DB, $USER;
        self::require_course($courseid);
        $plan = $DB->get_record('block_mrbs_faculty_plan', ['id' => $id, 'courseid' => $courseid, 'facultyid' => $USER->id]);
        if (!$plan) {
            throw new \moodle_exception('planningnotfound', 'block_mrbs');
        }
        return $plan;
    }

    private static function write(callable $operation) {
        global $DB, $USER;
        $lock = \core\lock\lock_config::get_lock_factory('block_mrbs')->get_lock('faculty_plan_' . $USER->id, 10);
        if (!$lock) {
            throw new \moodle_exception('bookingbusy', 'block_mrbs');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally {
            $lock->release();
        }
    }

    /** All submitted rows succeed together; blank extra form rows are ignored. */
    public static function save(int $courseid, array $rows, int $editid = 0): int {
        global $DB, $USER;
        self::require_course($courseid);
        if (!$rows || count($rows) > self::MAX_ROWS || ($editid && count($rows) !== 1)) {
            throw new \moodle_exception('planningrowlimit', 'block_mrbs');
        }
        return self::write(static function () use ($courseid, $rows, $editid, $DB, $USER) {
            self::require_course($courseid);
            $existing = $editid ? self::owned_plan($editid, $courseid) : null;
            $count = 0;
            foreach ($rows as $number => $values) {
                if (!array_filter($values, static function ($value) { return $value !== '' && $value !== null; })) {
                    continue;
                }
                try {
                    $plan = self::validate_row($values, $courseid);
                    $plan->courseid = $courseid;
                    $plan->facultyid = $USER->id;
                    $plan->timemodified = time();
                    if ($existing) {
                        $plan->id = $existing->id;
                        $DB->update_record('block_mrbs_faculty_plan', $plan);
                    } else {
                        // Retain old timing columns for upgrade compatibility only.
                        $plan->start_time = 0;
                        $plan->end_time = 0;
                        $plan->timecreated = time();
                        $DB->insert_record('block_mrbs_faculty_plan', $plan);
                    }
                    $count++;
                } catch (\moodle_exception $error) {
                    throw new \moodle_exception('sessionimportrow', 'block_mrbs', '',
                        (object)['row' => $number, 'message' => $error->getMessage()]);
                }
            }
            if (!$count) {
                throw new \moodle_exception('planningempty', 'block_mrbs');
            }
            return $count;
        });
    }

    public static function delete(int $id, int $courseid): void {
        global $DB;
        self::write(static function () use ($id, $courseid, $DB) {
            self::owned_plan($id, $courseid);
            $DB->delete_records('block_mrbs_faculty_plan', ['id' => $id]);
        });
    }
}
