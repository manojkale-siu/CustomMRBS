<?php
// This file is part of the MRBS block for Moodle.

define('AJAX_SCRIPT', true);

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
require_once(__DIR__ . '/config.inc.php');
require_once(__DIR__ . '/functions.php');
require_once(__DIR__ . '/mrbs_auth.php');

$action = required_param('action', PARAM_ALPHANUMEXT);
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/ajax.php', ['action' => $action]));
$PAGE->set_context(context_system::instance());

require_login();

header('Content-Type: application/json; charset=utf-8');

/**
 * Emit a JSON response.
 *
 * @param array $data
 * @param int $status
 * @return void
 */
function block_mrbs_ajax_response(array $data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (!getAuthorised(1)) {
    block_mrbs_ajax_response(['error' => get_string('accessdenied')], 403);
}

$prerequisites = \block_mrbs\local\booking_prerequisites::validate(0, 0, true, true);
if (!empty($prerequisites->errors)) {
    block_mrbs_ajax_response(['error' => implode(' ', $prerequisites->errors)], 400);
}

$params = [
    'programmeid' => optional_param('programmeid', 0, PARAM_INT),
    'batchid' => optional_param('batchid', 0, PARAM_INT),
    'semesterid' => optional_param('semesterid', 0, PARAM_INT),
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'groupid' => optional_param('groupid', 0, PARAM_INT),
    'rooms' => optional_param_array('rooms', [], PARAM_INT),
];

try {
    block_mrbs_ajax_response(\block_mrbs\external\booking_options::execute($action, $params));
} catch (moodle_exception $e) {
    block_mrbs_ajax_response(['error' => $e->getMessage()], 400);
} catch (Exception $e) {
    debugging('MRBS booking selector AJAX failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    block_mrbs_ajax_response(['error' => get_string('searchfailed', 'block_mrbs')], 500);
}
