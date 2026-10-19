<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * AJAX controller.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\external;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use mod_speakingfeedback\local\access;
use mod_speakingfeedback\local\attempts;
defined('MOODLE_INTERNAL') || die();

/** Small action interface with an explicit safe JSON result. */
class attempt extends external_api {
    /** Request schema. */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'action' => new external_value(PARAM_ALPHA, 'begin, transcribe, feedback, status, or list'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt ID', VALUE_DEFAULT, 0),
            'token' => new external_value(PARAM_ALPHANUMEXT, 'Idempotency token', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'History page', VALUE_DEFAULT, 0),
        ]);
    }

    /** Execute after Moodle AJAX sesskey validation and explicit authorization. */
    public static function execute($cmid, $action, $attemptid = 0, $token = '', $page = 0): string {
        global $USER, $DB, $OUTPUT;
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'action', 'attemptid', 'token', 'page'));
        [$cm, $course, $context, $activity] = access::activity($params['cmid']);
        self::validate_context($context);
        require_sesskey();
        require_capability('mod/speakingfeedback:submit', $context);
        if ($action === 'begin') {
            $result = attempts::export(attempts::begin($activity, $USER->id, $token), $context);
        } else if ($action === 'list') {
            $records = $DB->get_records_select('speakingfeedback_attempts',
                'speakingfeedbackid = ? AND userid = ? AND timesubmitted > 0', [$activity->id, $USER->id],
                'id DESC', '*', max(0, $page) * 25, 25);
            $total = attempts::count($activity->id, $USER->id);
            $result = ['attempts' => attempts::export_many($records, $context),
                'total' => $total, 'hasattempts' => $total > 0,
                'pagination' => $OUTPUT->paging_bar($total, max(0, $page), 25,
                    new \moodle_url('/mod/speakingfeedback/view.php', ['id' => $cmid]))];
        } else if (in_array($action, ['status', 'transcribe', 'feedback'], true)) {
            $record = attempts::get($attemptid);
            access::attempt($record, $cm, true);
            if ($action !== 'status') {
                \core\session\manager::write_close();
                $record = attempts::process($attemptid, $cm, $action);
            }
            $result = attempts::export($record, $context);
        } else {
            throw new \moodle_exception('invalidrequest', 'speakingfeedback');
        }
        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /** Response schema. */
    public static function execute_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON containing only student-visible attempt fields');
    }
}
