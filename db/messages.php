<?php
// This file is part of Moodle - https://moodle.org/.

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'booking_saved' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_DISALLOWED,
        ],
    ],
];
