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

    public static function require_course(int $courseid): void {
        global $USER;
        if (!self::teaches_course((int)$USER->id, $courseid)) {
            throw new \moodle_exception('planningfacultyonly', 'block_mrbs');
        }
    }

    /** Convert one simplified template/form row into a proposed session. */
    public static function validate_row(array $values): \stdClass {
        [$date, $from, $to, $topics] = array_pad($values, 4, '');
        if (is_int($date) || is_float($date)) {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)->format('Y-m-d');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', (string)$date, $d)
                || (int)$d[1] < 1970 || !checkdate((int)$d[2], (int)$d[3], (int)$d[1])) {
            throw new \moodle_exception('planninginvalidtime', 'block_mrbs');
        }
        $times = [];
        foreach ([$from, $to] as $value) {
            if (is_int($value) || is_float($value)) {
                if ($value >= 0 && $value < 1) {
                    $minutes = (int)round($value * 1440);
                    $value = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
                }
            }
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', (string)$value, $t)) {
                throw new \moodle_exception('planninginvalidtime', 'block_mrbs');
            }
            $timestamp = booking_time::mktime((int)$t[1], (int)$t[2], 0, (int)$d[2], (int)$d[3], (int)$d[1]);
            if (booking_time::date('Y-m-d H:i', $timestamp) !== $date . ' ' . $value) {
                throw new \moodle_exception('planninginvalidtime', 'block_mrbs');
            }
            $times[] = $timestamp;
        }
        $topics = trim(clean_param((string)$topics, PARAM_TEXT));
        if ($topics === '' || \core_text::strlen($topics) > 10000) {
            throw new \moodle_exception('planningtopicsrequired', 'block_mrbs');
        }
        booking_validator::validate_time_range($times[0], $times[1],
            (bool)get_config('block/mrbs', 'enable_periods'));
        return (object)['start_time' => $times[0], 'end_time' => $times[1], 'topics' => $topics];
    }

    public static function plans(int $courseid): array {
        global $DB, $USER;
        self::require_course($courseid);
        return $DB->get_records('block_mrbs_faculty_plan', ['courseid' => $courseid, 'facultyid' => $USER->id], 'start_time, id');
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
                    $plan = self::validate_row($values);
                    if ($DB->record_exists_select('block_mrbs_faculty_plan',
                            'facultyid = ? AND start_time < ? AND end_time > ? AND id <> ?',
                            [$USER->id, $plan->end_time, $plan->start_time, $editid])) {
                        throw new \moodle_exception('planningoverlap', 'block_mrbs');
                    }
                    $plan->courseid = $courseid;
                    $plan->facultyid = $USER->id;
                    $plan->timemodified = time();
                    if ($existing) {
                        $plan->id = $existing->id;
                        $DB->update_record('block_mrbs_faculty_plan', $plan);
                    } else {
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
    /** Read proposals only within the selected booking course and faculty. */
    public static function booking_plans(int $courseid, int $facultyid): array {
        global $DB;
        if (!timetable_access::can_edit()) {
            throw new \moodle_exception('accessdenied', 'block_mrbs');
        }
        if (!self::teaches_course($facultyid, $courseid)) {
            return [];
        }
        $result = [];
        foreach ($DB->get_records('block_mrbs_faculty_plan', compact('courseid', 'facultyid'), 'start_time, id', '*', 0, 500) as $plan) {
            $result[] = ['id' => $plan->id, 'topics' => $plan->topics,
                'label' => booking_time::userdate($plan->start_time) . ' - ' . \core_text::substr($plan->topics, 0, 100)];
        }
        return $result;
    }

    public static function booking_topics(int $id, int $courseid, int $facultyid): string {
        global $DB;
        if (!timetable_access::can_edit()) {
            throw new \moodle_exception('accessdenied', 'block_mrbs');
        }
        if (self::teaches_course($facultyid, $courseid)) {
            $plan = $DB->get_record('block_mrbs_faculty_plan', ['id' => $id, 'courseid' => $courseid, 'facultyid' => $facultyid]);
            if ($plan) {
                return $plan->topics;
            }
        }
        throw new \moodle_exception('planningnotfound', 'block_mrbs');
    }

}
