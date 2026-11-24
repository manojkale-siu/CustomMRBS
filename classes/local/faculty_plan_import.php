<?php
// This file is part of Moodle - https://moodle.org/.

namespace block_mrbs\local;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads proposed faculty topics only; never creates room bookings.
 */
class faculty_plan_import {
    private const HEADERS = [
        'Date', 'Start Time', 'End Time', 'Topics',
    ];
    private const MAX_ROWS = 500;
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Require the simplified faculty template. */
    private static function headers_match(array $headers): bool {
        return $headers === self::HEADERS;
    }

    public static function download_template(): void {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Faculty Plans');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (['A' => 16, 'B' => 14, 'C' => 14, 'D' => 70] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->fromArray(['2030-01-15', '09:00', '10:00', 'Example topics - replace or delete this row'], null, 'A2');
        $sheet->getComment('A2')->getText()->createTextRun('Example only. Replace/delete this row. ' . booking_time::label());
        $file = make_request_directory() . '/mrbs-faculty-planning-template.xlsx';
        (new XlsxWriter($workbook))->save($file);
        $workbook->disconnectWorksheets();
        send_temp_file($file, 'mrbs-faculty-planning-template.xlsx');
    }

    public static function read(string $file): array {
        if (filesize($file) > self::MAX_BYTES) {
            throw new \moodle_exception('sessionimportfileerror', 'block_mrbs');
        }
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
        $reader->setLoadSheetsOnly('Faculty Plans');
        try {
            $workbook = $reader->load($file);
        } catch (\Exception $e) {
            throw new \moodle_exception('sessionimportfileerror', 'block_mrbs');
        }
        try {
            $sheet = $workbook->getSheetByName('Faculty Plans');
            if (!$sheet || !self::headers_match($sheet->rangeToArray('A1:D1', null, false, false)[0])) {
                throw new \moodle_exception('planningheaders', 'block_mrbs');
            }
            $lastrow = $sheet->getHighestDataRow();
            if ($lastrow > self::MAX_ROWS + 1) {
                throw new \moodle_exception('sessionimportrowlimit', 'block_mrbs', '', self::MAX_ROWS);
            }

            $rows = [];
            for ($index = 2; $index <= $lastrow; $index++) {
                $values = [];
                foreach (range('A', 'D') as $column) {
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

}
