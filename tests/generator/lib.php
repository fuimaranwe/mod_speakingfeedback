<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Test generator.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/** Generate realistic activities without any live provider credentials. */
class mod_speakingfeedback_generator extends testing_module_generator {
    /** Supply required settings. */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)((array)$record + [
            'name' => 'Speaking practice', 'intro' => '', 'instructions' => 'Describe your favourite place.',
            'instructionsformat' => FORMAT_HTML, 'feedbackprompt' => get_string('defaultprompt', 'speakingfeedback'),
            'preparationtime' => 30, 'recordingtime' => 120, 'maxattempts' => 0, 'completionfeedback' => 0,
        ]);
        return parent::create_instance($record, $options);
    }
}
