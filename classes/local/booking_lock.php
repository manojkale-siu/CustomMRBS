<?php
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** One shared, reentrant application lock for all MRBS booking mutations. */
final class booking_lock {
    private static $lock;
    private static $holders = 0;
    private $released = false;

    public static function acquire(): self {
        if (!self::$lock) {
            self::$lock = \core\lock\lock_config::get_lock_factory('block_mrbs')->get_lock('save_booking', 10);
            if (!self::$lock) {
                throw new \moodle_exception('bookingbusy', 'block_mrbs');
            }
        }
        self::$holders++;
        return new self();
    }

    private function __construct() {
    }

    public static function require_held(): void {
        if (!self::$lock || self::$holders < 1) {
            throw new \moodle_exception('bookinglockrequired', 'block_mrbs');
        }
    }

    public function release(): void {
        if ($this->released) {
            return;
        }
        $this->released = true;
        if (--self::$holders === 0) {
            self::$lock->release();
            self::$lock = null;
        }
    }

    public function __destruct() {
        $this->release();
    }
}
