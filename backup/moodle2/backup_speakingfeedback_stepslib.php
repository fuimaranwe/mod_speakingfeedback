<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Backup structure.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/** Explicit field allowlists prevent credentials entering backups. */
class backup_speakingfeedback_activity_structure_step extends backup_activity_structure_step {
    /** Define the XML structure. */
    protected function define_structure() {
        $activity = new backup_nested_element('speakingfeedback', ['id'], [
            'name', 'intro', 'introformat', 'instructions', 'instructionsformat', 'feedbackprompt',
            'preparationtime', 'recordingtime', 'maxattempts', 'completionfeedback', 'timecreated', 'timemodified',
        ]);
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'status', 'instructions', 'feedbackprompt', 'preparationtime', 'recordingtime',
            'transcript', 'feedback', 'errorcode', 'retryafter', 'timecreated', 'timesubmitted', 'timemodified',
        ]);
        $activity->add_child($attempts);
        $attempts->add_child($attempt);
        $activity->set_source_table('speakingfeedback', ['id' => backup::VAR_ACTIVITYID]);
        if ($this->get_setting_value('userinfo')) {
            $attempt->set_source_sql('SELECT * FROM {speakingfeedback_attempts}
                WHERE speakingfeedbackid = ? AND timesubmitted > 0', [backup::VAR_PARENTID]);
        }
        $attempt->annotate_ids('user', 'userid');
        $attempt->annotate_files('mod_speakingfeedback', 'recording', 'id');
        $activity->annotate_files('mod_speakingfeedback', 'intro', null);
        $activity->annotate_files('mod_speakingfeedback', 'instructions', null);
        return $this->prepare_activity_structure($activity);
    }
}
