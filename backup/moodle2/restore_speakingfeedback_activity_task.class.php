<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Restore task.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/restore_speakingfeedback_stepslib.php');

/** Restore without any provider requests. */
class restore_speakingfeedback_activity_task extends restore_activity_task {
    /** No extra settings. */
    protected function define_my_settings() {
    }
    /** Structure step. */
    protected function define_my_steps() {
        $this->add_step(new restore_speakingfeedback_activity_structure_step('speakingfeedback', 'speakingfeedback.xml'));
    }
    /** Rich text links. */
    public static function define_decode_contents() {
        return [new restore_decode_content('speakingfeedback', ['intro', 'instructions'], 'speakingfeedback')];
    }
    /** Link rules. */
    public static function define_decode_rules() {
        return [new restore_decode_rule('SPEAKINGFEEDBACKVIEW', '/mod/speakingfeedback/view.php?id=$1', 'course_module'),
            new restore_decode_rule('SPEAKINGFEEDBACKINDEX', '/mod/speakingfeedback/index.php?id=$1', 'course')];
    }
    /** No legacy log rules. */
    public static function define_restore_log_rules() {
        return [];
    }
    /** No legacy course logs. */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
