<?php
// This file is part of Moodle - https://moodle.org/.

namespace block_mrbs\local;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

defined('MOODLE_INTERNAL') || die();

/**
 * Imports one scheduled session per workbook row.
 */
class session_import {
    private const HEADERS = [
        'Date', 'Start Time', 'End Time', 'Area', 'Room', 'Course Short Name', 'Faculty Username', 'Group', 'Description',
    ];
    private const MAX_ROWS = 500;
    public const MAX_BYTES = 2 * 1024 * 1024;

    public static function download_template(): void {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Sessions');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (array_combine(range('A', 'I'), [15, 14, 14, 25, 25, 25, 25, 25, 48]) as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('A2:A501')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('B2:C501')->getNumberFormat()->setFormatCode('hh:mm');
        $sheet->getStyle('D2:I501')->getNumberFormat()->setFormatCode('@');

        // Demonstrate each column using sample values that the user replaces.
        $sheet->fromArray([
            '2030-01-15', '09:00', '09:30', 'Example Area', 'Example Room',
            'COURSE101', 'faculty.username', 'Group A', 'Example session - replace or delete this row',
        ], null, 'A2');
        $sheet->getStyle('A2:I2')->getFont()->setItalic(true);
        $sheet->getComment('A2')->getText()->createTextRun(
            'Example data only. Replace or delete row 2 before importing. Use your configured period times.'
        );

        $file = make_request_directory() . '/mrbs-session-template.xlsx';
        (new XlsxWriter($workbook))->save($file);
        $workbook->disconnectWorksheets();
        send_temp_file($file, 'mrbs-session-template.xlsx');
    }

    public static function read(string $file): array {
        // Bound the expanded archive before the spreadsheet reader loads it.
        $archive = new \ZipArchive();
        if ($archive->open($file) !== true) {
            throw new \moodle_exception('sessionimportfileerror', 'block_mrbs');
        }
        try {
            $expanded = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $expanded += $entry['size'];
                if ($expanded > 16 * self::MAX_BYTES) {
                    throw new \moodle_exception('sessionimportfileerror', 'block_mrbs');
                }
            }
        } finally {
            $archive->close();
        }

        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly('Sessions');
        try {
            $workbook = $reader->load($file);
        } catch (\Exception $e) {
            throw new \moodle_exception('sessionimportfileerror', 'block_mrbs');
        }
        try {
            $sheet = $workbook->getSheetByName('Sessions');
            if (!$sheet || $sheet->rangeToArray('A1:I1', null, false, false)[0] !== self::HEADERS) {
                throw new \moodle_exception('sessionimportheaders', 'block_mrbs');
            }
            $lastrow = $sheet->getHighestDataRow();
            if ($lastrow > self::MAX_ROWS + 1) {
                throw new \moodle_exception('sessionimportrowlimit', 'block_mrbs', '', self::MAX_ROWS);
            }

            $rows = [];
            for ($index = 2; $index <= $lastrow; $index++) {
                $values = [];
                foreach (range('A', 'I') as $column) {
                    $cell = $sheet->getCell($column . $index);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        throw new \moodle_exception('sessionimportrow', 'block_mrbs', '', (object)[
                            'row' => $index, 'message' => get_string('sessionimportformula', 'block_mrbs'),
                        ]);
                    }
                    $value = $cell->getValue();
                    $values[] = is_string($value) ? trim($value) : $value;
                }
                if (array_filter($values, static function ($value) { return $value !== null && $value !== ''; })) {
                    $rows[$index] = $values;
                }
            }
            return $rows;
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public static function import(array $rows, bool $allowcapacity, bool $periodmode): array {
        global $DB, $USER;

        if (!$rows) {
            throw new \moodle_exception('sessionimportempty', 'block_mrbs');
        }
        if (!settings_manager::has_valid_custom_time_range()) {
            throw new \moodle_exception('mrbscustomtimerangerequired', 'block_mrbs');
        }
        $lock = \core\lock\lock_config::get_lock_factory('block_mrbs')->get_lock('save_booking', 10);
        if (!$lock) {
            throw new \moodle_exception('failed_to_acquire', 'block_mrbs');
        }
        $counts = [];
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($rows as $number => $values) {
                    try {
                        [$date, $start, $end, $room, $course, $faculty, $group, $description] =
                            self::validate_row($values, $allowcapacity, $periodmode);
                        $name = reservation::build_name($course, $faculty, $group);
                        $type = mrbsCanOnlyEditUnconfirmed() && $room->room_admin_email !== $USER->email ? 'U' : 'I';
                        $id = mrbsCreateSingleEntry($start, $end, 0, 0, $room->id, $USER->username,
                            $name, $type, $description, 0, false, $course, $faculty, $group);
                        if (!$id || !attendance_manager::sync_for_entry($id, $course, $faculty, $group)) {
                            throw new \moodle_exception('sessioncreatefailed', 'block_mrbs');
                        }
                        \block_mrbs\event\booking_created::create([
                            'objectid' => $id,
                            'context' => \context_system::instance(),
                            'other' => ['name' => $name, 'room' => $room->room_name],
                        ])->trigger();
                        $counts[$date] = ($counts[$date] ?? 0) + 1;
                    } catch (\moodle_exception $e) {
                        throw new \moodle_exception('sessionimportrow', 'block_mrbs', '', (object)[
                            'row' => $number, 'message' => $e->getMessage(),
                        ]);
                    }
                }
                $transaction->allow_commit();
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally {
            $lock->release();
        }

        ksort($counts);
        return $counts;
    }

    private static function validate_row(array $values, bool $allowcapacity, bool $periodmode): array {
        global $DB, $CFG, $USER;

        [$date, $from, $to, $areaname, $roomname, $shortname, $username, $groupname, $description] = $values;
        if (is_int($date) || is_float($date)) {
            $date = Date::excelToDateTimeObject($date)->format('Y-m-d');
        }
        $date = (string)$date;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
                || (int)$parts[1] < 1970 || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new \moodle_exception('sessionimportdatetime', 'block_mrbs');
        }
        $start = self::timestamp($from, $parts);
        $end = self::timestamp($to, $parts);
        if ($end <= $start) {
            throw new \moodle_exception('sessionimportdatetime', 'block_mrbs');
        }
        if (!check_max_advance_days_timestamp($start)) {
            throw new \moodle_exception('sessionimportadvance', 'block_mrbs');
        }
        if ($periodmode) {
            [$open, $close] = settings_manager::get_period_day_bounds($parts[1], $parts[2], $parts[3]);
            $interval = settings_manager::get_period_interval_seconds();
            if ($start < $open || $end > $close || ($start - $open) % $interval || ($end - $start) % $interval) {
                throw new \moodle_exception('sessionimportperiod', 'block_mrbs');
            }
        }

        $area = $DB->get_record('block_mrbs_area', ['area_name' => (string)$areaname], 'id', IGNORE_MISSING);
        $room = $area ? $DB->get_record('block_mrbs_room', [
            'area_id' => $area->id, 'room_name' => (string)$roomname,
        ], '*', IGNORE_MISSING) : false;
        if (!$room || !allowed_to_book($USER, $room)) {
            throw new \moodle_exception('sessionimportroom', 'block_mrbs');
        }

        $course = (int)$DB->get_field('course', 'id', ['shortname' => (string)$shortname]);
        $faculty = (int)$DB->get_field('user', 'id', [
            'username' => (string)$username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0, 'suspended' => 0,
        ]);
        $group = 0;
        if ((string)$groupname !== '') {
            $group = (int)$DB->get_field('groups', 'id', ['courseid' => $course, 'name' => (string)$groupname]);
            if (!$group) {
                throw new \moodle_exception('invalidgroup', 'block_mrbs');
            }
        }

        $hierarchy = booking_options::get_course_hierarchy($course);
        $errors = booking_options::validate_selection(
            $hierarchy['programmeid'], $hierarchy['batchid'], $hierarchy['semesterid'], $course, $faculty, $group
        );
        if ($errors) {
            throw new \moodle_exception('sessionimportdetails', 'block_mrbs', '', implode(' ', $errors));
        }
        $description = clean_param((string)$description, PARAM_TEXT);
        if ($description === '') {
            throw new \moodle_exception('must_set_description', 'block_mrbs');
        }
        if (mrbsCheckFree($room->id, $start, $end, 0, 0)) {
            throw new \moodle_exception('sessionimportroomconflict', 'block_mrbs');
        }
        if (booking_validator::find_student_conflicts($course, $group, $start, $end)) {
            throw new \moodle_exception('studentbookingconflict', 'block_mrbs');
        }
        if (booking_validator::find_faculty_conflicts($faculty, $start, $end)) {
            throw new \moodle_exception('facultybookingconflict', 'block_mrbs');
        }
        if (!$allowcapacity && booking_validator::check_room_capacity($course, $group, [(int)$room->id])['blocked']) {
            throw new \moodle_exception('sessionimportcapacity', 'block_mrbs');
        }
        return [$date, $start, $end, $room, $course, $faculty, $group, $description];
    }

    private static function timestamp($value, array $date): int {
        if (is_int($value) || is_float($value)) {
            if ($value >= 0 && $value < 1) {
                $minutes = (int)round($value * 1440);
                $value = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            }
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', (string)$value, $parts)) {
            throw new \moodle_exception('sessionimportdatetime', 'block_mrbs');
        }
        return mktime((int)$parts[1], (int)$parts[2], 0, (int)$date[2], (int)$date[3], (int)$date[1]);
    }
}
