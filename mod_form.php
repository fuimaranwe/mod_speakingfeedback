<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Activity settings.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/moodleform_mod.php');

/** Teacher configuration using Moodle's standard module form. */
class mod_speakingfeedback_mod_form extends moodleform_mod {
    /** Define fields. */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255);
        $this->standard_intro_elements();
        $mform->addElement('editor', 'instructions_editor', get_string('instructions', 'speakingfeedback'), null,
            ['maxfiles' => EDITOR_UNLIMITED_FILES, 'context' => $this->context]);
        $mform->setType('instructions_editor', PARAM_RAW);
        $mform->addRule('instructions_editor', null, 'required', null, 'client');
        $mform->addElement('header', 'feedbacksettings', get_string('feedbacksettings', 'speakingfeedback'));
        foreach (['preparationtime' => 30, 'recordingtime' => 120, 'maxattempts' => 0] as $field => $default) {
            $mform->addElement('text', $field, get_string($field, 'speakingfeedback'), ['size' => 8]);
            $mform->setType($field, PARAM_RAW_TRIMMED);
            $mform->setDefault($field, $default);
            $mform->addRule($field, null, 'required', null, 'client');
        }
        $mform->addHelpButton('maxattempts', 'maxattempts', 'speakingfeedback');
        $mform->addElement('textarea', 'feedbackprompt', get_string('feedbackprompt', 'speakingfeedback'),
            ['rows' => 9, 'cols' => 70]);
        $mform->setType('feedbackprompt', PARAM_TEXT);
        $mform->setDefault('feedbackprompt', get_string('defaultprompt', 'speakingfeedback'));
        $mform->addRule('feedbackprompt', null, 'required', null, 'client');
        $mform->addHelpButton('feedbackprompt', 'feedbackprompt', 'speakingfeedback');
        if (!empty($this->current->instance) &&
                \mod_speakingfeedback\local\credentials::exists($this->current->instance)) {
            $mform->addElement('static', 'keystatus', '', get_string('keyconfigured', 'speakingfeedback'));
        }
        $mform->addElement('passwordunmask', 'apikey', get_string('apikey', 'speakingfeedback'),
            ['autocomplete' => 'new-password']);
        $mform->setType('apikey', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('apikey', 'apikey', 'speakingfeedback');
        $mform->addElement('advcheckbox', 'clearkey', get_string('clearkey', 'speakingfeedback'));
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /** Prepare the rich text editor; credentials are never populated. */
    public function data_preprocessing(&$values) {
        $draftid = file_get_submitted_draft_itemid('instructions_editor');
        $text = file_prepare_draft_area($draftid, $this->context->id, 'mod_speakingfeedback', 'instructions', 0,
            ['subdirs' => true, 'maxfiles' => EDITOR_UNLIMITED_FILES], $values['instructions'] ?? '');
        $values['instructions_editor'] = ['text' => $text, 'format' => $values['instructionsformat'] ?? FORMAT_HTML,
            'itemid' => $draftid];
        $values['apikey'] = '';
    }

    /** Server-side validation. */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['preparationtime' => [0, 600], 'recordingtime' => [10, 600], 'maxattempts' => [0, 1000]] as $key => $range) {
            if (!preg_match('/^\d+$/D', (string)$data[$key]) || $data[$key] < $range[0] || $data[$key] > $range[1]) {
                $errors[$key] = get_string('invalidtime', 'speakingfeedback', (object)['min' => $range[0], 'max' => $range[1]]);
            }
        }
        if (!empty($data['clearkey']) && !empty($data['apikey'])) {
            $errors['apikey'] = get_string('keyconflict', 'speakingfeedback');
        }
        if (preg_match('/[\r\n]/', $data['apikey'] ?? '')) {
            $errors['apikey'] = get_string('invalidrequest', 'speakingfeedback');
        }
        if (trim($data['feedbackprompt'] ?? '') === '') {
            $errors['feedbackprompt'] = get_string('required');
        }
        if (trim(html_to_text($data['instructions_editor']['text'] ?? '', 0, false)) === '') {
            $errors['instructions_editor'] = get_string('required');
        }
        return $errors;
    }

    /** Add the custom completion option. */
    public function add_completion_rules() {
        $field = 'completionfeedback' . $this->get_suffix();
        $this->_form->addElement('advcheckbox', $field, '', get_string('completionfeedback', 'speakingfeedback'));
        return [$field];
    }

    /** Whether custom completion is selected. */
    public function completion_rule_enabled($data) {
        return !empty($data['completionfeedback' . $this->get_suffix()]);
    }

    /** Clear disabled custom completion when completion settings are unlocked. */
    public function get_data() {
        $data = parent::get_data();
        if ($data && !empty($data->completionunlocked)) {
            $field = 'completionfeedback' . $this->get_suffix();
            if (empty($data->$field) || $data->completion != COMPLETION_TRACKING_AUTOMATIC) {
                $data->$field = 0;
            }
        }
        return $data;
    }
}
