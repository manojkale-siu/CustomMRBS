<?php
namespace block_mrbs;
defined('MOODLE_INTERNAL') || die();

/** The two import routes must use Moodle's bundled Excel classes and read real XLSX files. */
final class spreadsheet_test extends \advanced_testcase {
    public function test_bundled_library_and_both_import_readers(): void {
        $this->resetAfterTest();
        local\excel_library::load();
        $this->assertTrue(class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class));
        foreach ([
            ['Faculty Plans', ['Date', 'Start Time', 'End Time', 'Topics'],
                ['2030-01-15', '09:00', '10:00', "Topic one\nTopic two"], local\faculty_plan_import::class],
            ['Sessions', ['Date', 'Start Time', 'End Time', 'Area', 'Room', 'Course Short Name',
                'Faculty Username', 'Group', 'Description/Topics'],
                ['2030-01-15', '09:00', '10:00', 'Area A', 'Room A', 'COURSE1', 'teacher1', '', 'Topic one'],
                local\session_import::class],
        ] as [$title, $headers, $values, $importer]) {
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($headers, null, 'A1');
            $sheet->fromArray($values, null, 'A2');
            $file = make_request_directory() . '/template.xlsx';
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
            $rows = $importer::read($file);
            $this->assertCount(1, $rows);
            $this->assertSame('2030-01-15', $rows[2][0]);
            $this->assertSame(end($values), end($rows[2]));
            $sheet->setCellValue('D2', '=1+1');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($file);
            try {
                $importer::read($file);
                $this->fail('Formula cells must be rejected.');
            } catch (\moodle_exception $e) {
                $this->assertSame('sessionimportrow', $e->errorcode);
            } finally {
                $book->disconnectWorksheets();
            }
        }
    }
}
