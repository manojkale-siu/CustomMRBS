<?php
// This file is part of the MRBS block for Moodle.

namespace block_mrbs\external;

defined('MOODLE_INTERNAL') || die();

/**
 * AJAX facade for MRBS booking selector data.
 *
 * @package   block_mrbs
 */
class booking_options {

    /**
     * Dispatch a read-only selector action.
     *
     * @param string $action
     * @param array $params
     * @return array
     */
    public static function execute($action, array $params) {
        switch ($action) {
            case 'programmes':
                return ['results' => \block_mrbs\local\booking_options::get_programmes()];

            case 'batches':
                return ['results' => \block_mrbs\local\booking_options::get_batches($params['programmeid'] ?? 0)];

            case 'semesters':
                return ['results' => \block_mrbs\local\booking_options::get_semesters($params['batchid'] ?? 0)];

            case 'courses':
                return ['results' => \block_mrbs\local\booking_options::get_courses($params['semesterid'] ?? 0)];

            case 'faculties':
                return ['results' => \block_mrbs\local\booking_options::get_faculties($params['courseid'] ?? 0)];

            case 'groups':
                return ['results' => \block_mrbs\local\booking_options::get_groups($params['courseid'] ?? 0)];

            case 'facultyplans':
                return ['results' => \block_mrbs\local\faculty_planning::booking_plans(
                    $params['courseid'] ?? 0, $params['facultyid'] ?? 0)];

            case 'capacity':
                return [
                    'results' => \block_mrbs\local\booking_validator::check_room_capacity(
                        $params['courseid'] ?? 0,
                        $params['groupid'] ?? 0,
                        $params['rooms'] ?? []
                    ),
                ];

            default:
                throw new \moodle_exception('invalidrequest', 'error');
        }
    }
}
