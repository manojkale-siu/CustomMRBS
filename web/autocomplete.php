<?php

// This file is part of the MRBS block for Moodle.

define('AJAX_SCRIPT', true);

require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
include "config.inc.php";
include "functions.php";
require_once('mrbs_auth.php');

$type = required_param('type', PARAM_ALPHA);
$query = trim(optional_param('q', '', PARAM_TEXT));
$limit = min(max(optional_param('limit', 20, PARAM_INT), 1), 50);
$PAGE->set_url(new moodle_url('/blocks/mrbs/web/autocomplete.php', ['type' => $type]));
$PAGE->set_context(context_system::instance());

require_login();

header('Content-Type: application/json; charset=utf-8');

function block_mrbs_json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function block_mrbs_search_condition($fields, $query, &$params) {
    global $DB;

    $conditions = array();
    $index = 0;
    foreach ($fields as $field) {
        $param = 'search'.$index;
        $conditions[] = $DB->sql_like('LOWER('.$field.')', ':'.$param, false);
        $params[$param] = '%'.$DB->sql_like_escape(core_text::strtolower($query)).'%';
        $index++;
    }

    return '('.implode(' OR ', $conditions).')';
}

if (!getAuthorised(1)) {
    block_mrbs_json_response(array('error' => get_string('accessdenied')), 403);
}

if (core_text::strlen($query) < 2) {
    block_mrbs_json_response(array('results' => array()));
}

try {
    if ($type == 'course') {
        block_mrbs_json_response(array('results' => \block_mrbs\local\booking_options::search_courses($query, $limit)));
    }

    if ($type == 'faculty') {
        block_mrbs_json_response(array('results' => \block_mrbs\local\booking_options::search_faculties($query, $limit)));
    }

    block_mrbs_json_response(array('error' => get_string('invalidrequest', 'error')), 400);
} catch (Exception $e) {
    debugging('MRBS autocomplete search failed: '.$e->getMessage(), DEBUG_DEVELOPER);
    error_log('MRBS autocomplete search failed: '.$e->getMessage());
    block_mrbs_json_response(array('error' => get_string('searchfailed', 'block_mrbs')), 500);
}
