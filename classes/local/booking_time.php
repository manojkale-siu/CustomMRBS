<?php
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** Physical room schedules always use the Moodle site timezone, never a viewer's timezone. */
final class booking_time {
    public static function timezone(): \DateTimeZone {
        return \core_date::get_server_timezone_object();
    }

    public static function at(?int $timestamp = null): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(self::timezone());
    }

    public static function date(string $format, ?int $timestamp = null): string {
        return self::at($timestamp)->format($format);
    }

    /** Normalize legacy calendar navigation (month/day overflow) in an explicit timezone. */
    public static function mktime($hour = null, $minute = null, $second = null,
            $month = null, $day = null, $year = null): int {
        $now = self::at();
        $year = $year === null ? (int)$now->format('Y') : (int)$year;
        if ($year >= 0 && $year <= 69) {
            $year += 2000;
        } else if ($year >= 70 && $year <= 100) {
            $year += 1900;
        }
        return $now->setDate($year, $month === null ? (int)$now->format('n') : (int)$month,
                $day === null ? (int)$now->format('j') : (int)$day)
            ->setTime($hour === null ? (int)$now->format('G') : (int)$hour,
                $minute === null ? (int)$now->format('i') : (int)$minute,
                $second === null ? (int)$now->format('s') : (int)$second)->getTimestamp();
    }

    public static function getdate(?int $timestamp = null): array {
        $date = self::at($timestamp);
        return ['seconds' => (int)$date->format('s'), 'minutes' => (int)$date->format('i'),
            'hours' => (int)$date->format('G'), 'mday' => (int)$date->format('j'),
            'wday' => (int)$date->format('w'), 'mon' => (int)$date->format('n'),
            'year' => (int)$date->format('Y'), 'yday' => (int)$date->format('z'),
            'weekday' => $date->format('l'), 'month' => $date->format('F'), 0 => $date->getTimestamp()];
    }

    public static function strtotime(string $text, ?int $base = null) {
        try {
            $date = self::at($base)->modify($text);
            return $date ? $date->getTimestamp() : false;
        } catch (\Exception $error) {
            return false;
        }
    }

    /** Retain Moodle localization, explicitly overriding user/forced display timezones. */
    public static function userdate($timestamp, $format = '', $fixday = true, $fixhour = true): string {
        return \userdate($timestamp, $format, self::timezone()->getName(), $fixday, $fixhour);
    }

    public static function shift_days(int $timestamp, int $days): int {
        return self::at($timestamp)->modify(sprintf('%+d days', $days))->getTimestamp();
    }

    /** Offset differences also cover half-hour daylight-saving changes. */
    public static function offset_change(int $start, int $end): int {
        return self::at($start)->getOffset() - self::at($end)->getOffset();
    }

    /** Preserve the base session's wall-clock length when another date has a different UTC offset. */
    public static function occurrence_end(int $basestart, int $baseend, int $start): int {
        $wallseconds = ($baseend - $basestart) - self::offset_change($basestart, $baseend);
        return self::at($start)->modify(sprintf('%+d seconds', $wallseconds))->getTimestamp();
    }

    public static function label(): string {
        return get_string('bookingtimezone', 'block_mrbs', self::timezone()->getName());
    }
}
