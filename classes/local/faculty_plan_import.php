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
        'Proposed Date', 'Topics', 'Teaching Methodology', 'Teaching Resource ID', 'Assessment Method', 'Remarks',
    ];
    private const MAX_ROWS = 500;
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Require the simplified faculty template. */
    private static function headers_match(array $headers): bool {
        return $headers === self::HEADERS;
    }

    public static function download_template(int $courseid): void {
        excel_library::load();
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Faculty Plans');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (['A' => 16, 'B' => 65, 'C' => 30, 'D' => 24, 'E' => 28, 'F' => 50] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->fromArray(['2030-01-15', 'Example topics - replace or delete this row', 'Lecture', 0, 'Quiz', 'Example only'], null, 'A2');
        $sheet->setCellValue('D2', 0);
        $sheet->getComment('A2')->getText()->createTextRun('Example only. Replace/delete this row.');
        foreach (['C' => faculty_planning::METHODOLOGIES, 'E' => faculty_planning::ASSESSMENTS] as $column => $options) {
            $validation = $sheet->getCell($column . '2')->getDataValidation();
            $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $validation->setShowDropDown(true)->setShowErrorMessage(true)->setErrorStyle('stop');
            $validation->setFormula1('"' . implode(',', $options) . '"');
            $validation->setSqref($column . '2:' . $column . '501');
        }
        $reference = $workbook->createSheet();
        $reference->setTitle('Course Resources');
        $reference->fromArray(['Teaching Resource ID', 'Course activity'], null, 'A1');
        $reference->fromArray([0, 'No linked activity'], null, 'A2');
        $reference->setCellValue('A2', 0);
        $row = 3;
        foreach (faculty_planning::resources($courseid) as $id => $name) {
            $reference->setCellValue('A' . $row, $id);
            $reference->setCellValueExplicit('B' . $row++, $name, DataType::TYPE_STRING);
        }
        $reference->getColumnDimension('A')->setWidth(24);
        $reference->getColumnDimension('B')->setWidth(80);
        $workbook->setActiveSheetIndex(0);
        $file = make_request_directory() . '/mrbs-faculty-planning-template.xlsx';
        (new XlsxWriter($workbook))->save($file);
        $workbook->disconnectWorksheets();
        send_temp_file($file, 'mrbs-faculty-planning-template.xlsx');
    }

    public static function read(string $file): array {
        excel_library::load();
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
            if (!$sheet || !self::headers_match($sheet->rangeToArray('A1:F1', null, false, false)[0])) {
                throw new \moodle_exception('planningheaders', 'block_mrbs');
            }
            $lastrow = $sheet->getHighestDataRow();
            if ($lastrow > self::MAX_ROWS + 1) {
                throw new \moodle_exception('sessionimportrowlimit', 'block_mrbs', '', self::MAX_ROWS);
            }

            $rows = [];
            for ($index = 2; $index <= $lastrow; $index++) {
                $values = [];
                foreach (range('A', 'F') as $column) {
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
