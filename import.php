<?php
// The legacy CSV writer bypassed MRBS validation and is intentionally disabled.
require_once(__DIR__ . '/../../config.php');
throw new moodle_exception('legacycsvdisabled', 'block_mrbs');
