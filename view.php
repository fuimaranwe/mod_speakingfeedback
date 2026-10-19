<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Activity page.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');
$id = required_param('id', PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
[$cm, $course, $context, $activity] = \mod_speakingfeedback\local\access::activity($id);
$PAGE->set_url('/mod/speakingfeedback/view.php', ['id' => $id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$completion = new completion_info($course);
$completion->set_module_viewed($cm);
\mod_speakingfeedback\event\course_module_viewed::create(['context' => $context, 'objectid' => $activity->id])->trigger();
$cansubmit = has_capability('mod/speakingfeedback:submit', $context);
$configured = \mod_speakingfeedback\local\credentials::exists($activity->id);
$count = \mod_speakingfeedback\local\attempts::count($activity->id, $USER->id);
$limitreached = $activity->maxattempts && $count >= $activity->maxattempts;
$instructions = file_rewrite_pluginfile_urls($activity->instructions, 'pluginfile.php', $context->id,
    'mod_speakingfeedback', 'instructions', 0);
$data = [
    'startlabel' => get_string($activity->preparationtime ? 'startpreparation' : 'startrecording', 'speakingfeedback'),
    'starthint' => get_string($activity->preparationtime ? 'preparationhint' : 'recordinghint',
        'speakingfeedback', $activity->preparationtime),
    'cmid' => $id, 'instructions' => format_text($instructions, $activity->instructionsformat, ['context' => $context]),
    'timing' => get_string('timing', 'speakingfeedback', (object)[
        'preparation' => $activity->preparationtime, 'recording' => $activity->recordingtime,
    ]),
    'remaining' => $activity->maxattempts ? get_string('remaining', 'speakingfeedback', max(0, $activity->maxattempts - $count))
        : get_string('unlimited', 'speakingfeedback'),
    'cansubmit' => $cansubmit, 'canrecord' => $cansubmit && $configured && !$limitreached,
    'keymissing' => !$configured, 'limitreached' => (bool)$limitreached,
    'reporturl' => has_capability('mod/speakingfeedback:viewreports', $context)
        ? (new moodle_url('/mod/speakingfeedback/report.php', ['id' => $id]))->out(false) : '',
];
$records = $DB->get_records_select('speakingfeedback_attempts',
    'speakingfeedbackid = ? AND userid = ? AND timesubmitted > 0', [$activity->id, $USER->id], 'id DESC', '*', $page * 25, 25);
$data['attempts'] = \mod_speakingfeedback\local\attempts::export_many($records, $context);
$data['hasattempts'] = !empty($records);
$data['pagination'] = $OUTPUT->paging_bar($count, $page, 25, $PAGE->url);
if ($cansubmit) {
    $PAGE->requires->js_call_amd('mod_speakingfeedback/recorder', 'init', [[
        'cmid' => $id, 'preparation' => (int)$activity->preparationtime, 'recording' => (int)$activity->recordingtime,
        'maxbytes' => \mod_speakingfeedback\local\attempts::maxbytes($course),
        'maxattempts' => (int)$activity->maxattempts, 'count' => $count,
    ]]);
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_speakingfeedback/activity', $data);
echo $OUTPUT->footer();
