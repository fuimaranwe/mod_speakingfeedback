<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Restore structure.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/** Restore data and remap protected audio. */
class restore_speakingfeedback_activity_structure_step extends restore_activity_structure_step {
    /** XML paths. */
    protected function define_structure() {
        $paths = [new restore_path_element('speakingfeedback', '/activity/speakingfeedback')];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('speakingfeedback_attempt', '/activity/speakingfeedback/attempts/attempt');
        }
        return $this->prepare_activity_structure($paths);
    }
    /** Restore activity; credential table is intentionally never populated. */
    protected function process_speakingfeedback($data) {
        global $DB;
        $data = (object)$data;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $newid = $DB->insert_record('speakingfeedback', $data);
        $this->apply_activity_instance($newid);
    }
    /** Remap student and assign a fresh idempotency token. */
    protected function process_speakingfeedback_attempt($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->speakingfeedbackid = $this->get_new_parentid('speakingfeedback');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->token = bin2hex(random_bytes(24));
        if (in_array($data->status, ['transcribing', 'generating'])) {
            $data->status = 'saved';
        }
        $data->retryafter = 0;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timesubmitted = $this->apply_date_offset($data->timesubmitted);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $id = $DB->insert_record('speakingfeedback_attempts', $data);
        $this->set_mapping('speakingfeedback_attempt', $oldid, $id, true);
    }
    /** Restore files in the new context. */
    protected function after_execute() {
        $this->add_related_files('mod_speakingfeedback', 'intro', null);
        $this->add_related_files('mod_speakingfeedback', 'instructions', null);
        $this->add_related_files('mod_speakingfeedback', 'recording', 'speakingfeedback_attempt');
    }
}
