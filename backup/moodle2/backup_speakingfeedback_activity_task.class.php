<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Backup task.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/backup_speakingfeedback_stepslib.php');

/** Backup configuration and optional user data, never credentials. */
class backup_speakingfeedback_activity_task extends backup_activity_task {
    /** No extra settings. */
    protected function define_my_settings() {
    }
    /** Structure step. */
    protected function define_my_steps() {
        $this->add_step(new backup_speakingfeedback_activity_structure_step('speakingfeedback', 'speakingfeedback.xml'));
    }
    /** Portable links. */
    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot, '/');
        $content = preg_replace('/' . $base . '\/mod\/speakingfeedback\/view.php\?id=([0-9]+)/',
            '$@SPEAKINGFEEDBACKVIEW*$1@$', $content);
        return preg_replace('/' . $base . '\/mod\/speakingfeedback\/index.php\?id=([0-9]+)/',
            '$@SPEAKINGFEEDBACKINDEX*$1@$', $content);
    }
}
