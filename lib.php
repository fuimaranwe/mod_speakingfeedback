<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Moodle callbacks.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/** Supported activity features. */
function speakingfeedback_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO, FEATURE_SHOW_DESCRIPTION, FEATURE_GROUPS, FEATURE_GROUPINGS,
        FEATURE_COMPLETION_TRACKS_VIEWS, FEATURE_COMPLETION_HAS_RULES, FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_GRADE_HAS_GRADE, FEATURE_GRADE_OUTCOMES => false,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ASSESSMENT,
        default => null,
    };
}

/** Persist editor files after the course module exists. */
function speakingfeedback_save_editor($data): void {
    global $DB;
    if (isset($data->instructions_editor)) {
        $context = context_module::instance($data->coursemodule);
        $text = file_save_draft_area_files($data->instructions_editor['itemid'], $context->id,
            'mod_speakingfeedback', 'instructions', 0, ['subdirs' => true, 'maxfiles' => EDITOR_UNLIMITED_FILES],
            $data->instructions_editor['text']);
        $DB->update_record('speakingfeedback', (object)['id' => $data->id, 'instructions' => $text,
            'instructionsformat' => $data->instructions_editor['format']]);
    }
    \mod_speakingfeedback\local\credentials::save($data->id, $data->apikey ?? '', !empty($data->clearkey));
    \core_completion\api::update_completion_date_event($data->coursemodule, 'speakingfeedback', $data->id,
        $data->completionexpected ?? null);
}

/** Create an activity. */
function speakingfeedback_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated = $data->timemodified = time();
    $data->instructions = $data->instructions ?? '';
    $data->instructionsformat = $data->instructionsformat ?? FORMAT_HTML;
    $data->id = $DB->insert_record('speakingfeedback', $data);
    speakingfeedback_save_editor($data);
    return $data->id;
}

/** Update an activity without returning its key to the form. */
function speakingfeedback_update_instance($data, $mform = null) {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('speakingfeedback', $data);
    speakingfeedback_save_editor($data);
    return true;
}

/** Delete activity data and protected files. */
function speakingfeedback_delete_instance($id) {
    global $DB;
    if (!$DB->record_exists('speakingfeedback', ['id' => $id])) {
        return false;
    }
    $cm = get_coursemodule_from_instance('speakingfeedback', $id);
    if ($cm) {
        $context = context_module::instance($cm->id);
        \mod_speakingfeedback\local\attempts::delete($id, $context);
        get_file_storage()->delete_area_files($context->id, 'mod_speakingfeedback');
    }
    $DB->delete_records('speakingfeedback_attempts', ['speakingfeedbackid' => $id]);
    $DB->delete_records('speakingfeedback_keys', ['speakingfeedbackid' => $id]);
    $DB->delete_records('speakingfeedback', ['id' => $id]);
    return true;
}

/** Serve instruction files and recordings after authorization. */
function speakingfeedback_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_MODULE || !in_array($filearea, ['instructions', 'recording'])) {
        return false;
    }
    [$cm] = \mod_speakingfeedback\local\access::activity($cm->id);
    $itemid = (int)array_shift($args);
    if ($filearea === 'recording') {
        $attempt = \mod_speakingfeedback\local\attempts::get($itemid);
        \mod_speakingfeedback\local\access::attempt($attempt, $cm);
    } else if ($itemid !== 0) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file($context->id, 'mod_speakingfeedback', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    // Private audio must not be cached across users or after permission changes.
    send_stored_file($file, $filearea === 'recording' ? 0 : 86400, 0, $forcedownload, $options);
}

/** Course module completion metadata. */
function speakingfeedback_get_coursemodule_info($cm) {
    global $DB;
    $activity = $DB->get_record('speakingfeedback', ['id' => $cm->instance], 'id, name, intro, introformat, completionfeedback');
    if (!$activity) {
        return null;
    }
    $info = new cached_cm_info();
    $info->name = $activity->name;
    if ($cm->showdescription) {
        $info->content = format_module_intro('speakingfeedback', $activity, $cm->id, false);
    }
    if ($cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionfeedback'] = $activity->completionfeedback;
    }
    return $info;
}

/** Custom completion description. */
function mod_speakingfeedback_get_completion_active_rule_descriptions($cm) {
    return !empty($cm->customdata['customcompletionrules']['completionfeedback'])
        ? [get_string('completionfeedback', 'speakingfeedback')] : [];
}

/** Course reset option. */
function speakingfeedback_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'speakingfeedbackheader', get_string('pluginname', 'speakingfeedback'));
    $mform->addElement('advcheckbox', 'reset_speakingfeedback', get_string('resetattempts', 'speakingfeedback'));
}

/** Reset default. */
function speakingfeedback_reset_course_form_defaults($course) {
    return ['reset_speakingfeedback' => 1];
}

/** Delete attempts on course reset; preserve settings and key. */
function speakingfeedback_reset_userdata($data) {
    global $DB;
    if (empty($data->reset_speakingfeedback)) {
        return [];
    }
    foreach ($DB->get_records('speakingfeedback', ['course' => $data->courseid]) as $activity) {
        $cm = get_coursemodule_from_instance('speakingfeedback', $activity->id);
        \mod_speakingfeedback\local\attempts::delete($activity->id, context_module::instance($cm->id));
        $completion = new completion_info(get_course($data->courseid));
        $completion->reset_all_state($cm);
    }
    return [['component' => get_string('pluginname', 'speakingfeedback'),
        'item' => get_string('resetattempts', 'speakingfeedback'), 'error' => false]];
}
