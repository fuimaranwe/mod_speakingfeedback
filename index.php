<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Course activity list.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$PAGE->set_url('/mod/speakingfeedback/index.php', ['id' => $id]);
$PAGE->set_context(context_course::instance($id));
$PAGE->set_title(get_string('modulenameplural', 'speakingfeedback'));
$PAGE->set_heading(format_string($course->fullname));
$activities = [];
foreach (get_fast_modinfo($course)->get_instances_of('speakingfeedback') as $cm) {
    if ($cm->uservisible) {
        $activities[] = ['name' => format_string($cm->name), 'url' => $cm->url->out(false)];
    }
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_speakingfeedback/index', ['activities' => $activities]);
echo $OUTPUT->footer();
