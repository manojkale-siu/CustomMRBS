<?php
// This file is part of Moodle - https://moodle.org/.
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** Load the spreadsheet library supplied by Moodle, without plugin vendor copies. */
final class excel_library {
    public static function load(): void {
        global $CFG;
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return;
        }

        // Older Moodle distributions register their Composer loader here.
        // In Moodle 4.5 this compatibility file is empty: core normally loads PSR-4 classes.
        $library = $CFG->libdir . '/phpspreadsheet';
        if (is_readable($library . '/vendor/autoload.php')) {
            require_once($library . '/vendor/autoload.php');
        }
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return;
        }

        // Resolve Moodle's current layout as well as the older vendor layout when
        // the host's component class map does not expose the bundled library.
        foreach ([$library . '/phpspreadsheet/src/PhpSpreadsheet/',
                $library . '/vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/'] as $directory) {
            if (!is_readable($directory . 'Spreadsheet.php')) {
                continue;
            }
            spl_autoload_register(static function (string $classname) use ($directory): void {
                $prefix = 'PhpOffice\\PhpSpreadsheet\\';
                if (strpos($classname, $prefix) !== 0) {
                    return;
                }
                $file = $directory . str_replace('\\', '/', substr($classname, strlen($prefix))) . '.php';
                if (is_readable($file)) {
                    require_once($file);
                }
            });
            if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
                return;
            }
        }
        throw new \moodle_exception('spreadsheetunavailable', 'block_mrbs');
    }
}
