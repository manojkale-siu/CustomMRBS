<?php
namespace block_mrbs\local;

defined('MOODLE_INTERNAL') || die();

/** One-time site navigation registration during installation or upgrade. */
final class navigation_setup {
    public static function ensure_menu_link(): bool {
        global $CFG;
        // Explicit config.php settings remain under the site administrator's control.
        if (isset($CFG->config_php_settings) && array_key_exists('custommenuitems', $CFG->config_php_settings)) {
            return false;
        }
        $current = (string)get_config(null, 'custommenuitems');
        foreach (preg_split('/\r\n|\r|\n/', $current) as $line) {
            $parts = explode('|', $line);
            if (isset($parts[1]) && self::is_entry_url(trim($parts[1]))) {
                return false;
            }
        }
        $newline = strpos($current, "\r\n") !== false ? "\r\n" : "\n";
        $updated = $current;
        if ($updated !== '' && !preg_match('/[\r\n]$/D', $updated)) {
            $updated .= $newline;
        }
        // Use wwwroot so Moodle installations in a subdirectory work too.
        $updated .= 'MRBS|' . rtrim($CFG->wwwroot, '/')
            . '/blocks/mrbs/web/index.php|Meeting Room Booking System';
        if (!set_config('custommenuitems', $updated)) {
            throw new \moodle_exception('navigationregistrationfailed', 'block_mrbs');
        }
        return true;
    }

    /** Recognize existing local/absolute entry links without matching another site. */
    private static function is_entry_url(string $url): bool {
        global $CFG;
        $site = parse_url($CFG->wwwroot);
        $target = parse_url(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if ($target === false || isset($target['scheme']) && !in_array(strtolower($target['scheme']), ['http', 'https'], true)) {
            return false;
        }
        if (isset($target['host'])) {
            $sitescheme = strtolower($site['scheme']);
            $scheme = strtolower($target['scheme'] ?? $sitescheme);
            $siteport = $site['port'] ?? ($sitescheme === 'https' ? 443 : 80);
            $port = $target['port'] ?? ($scheme === 'https' ? 443 : 80);
            if (strcasecmp($target['host'], $site['host']) !== 0 || $scheme !== $sitescheme || $port !== $siteport) {
                return false;
            }
        }
        $base = rtrim($site['path'] ?? '', '/');
        $path = $target['path'] ?? '';
        if ($path !== '' && $path[0] !== '/') {
            $path = $base . '/' . $path;
        }
        $path = rtrim($path, '/');
        return $path === $base . '/blocks/mrbs/web/index.php' || $path === $base . '/blocks/mrbs/web';
    }
}
