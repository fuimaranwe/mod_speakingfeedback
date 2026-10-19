<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Completion rule.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\completion;
defined('MOODLE_INTERNAL') || die();

/** Completion requires a successfully generated feedback result. */
class custom_completion extends \core_completion\activity_custom_completion {
    /** Determine completion for this user. */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        return $DB->record_exists('speakingfeedback_attempts', [
            'speakingfeedbackid' => $this->cm->instance, 'userid' => $this->userid, 'status' => 'complete',
        ]) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }
    /** Defined rules. */
    public static function get_defined_custom_rules(): array {
        return ['completionfeedback'];
    }
    /** Rule descriptions. */
    public function get_custom_rule_descriptions(): array {
        return ['completionfeedback' => get_string('completiondetail:feedback', 'speakingfeedback')];
    }
    /** Display order. */
    public function get_sort_order(): array {
        return ['completionview', 'completionfeedback'];
    }
}
