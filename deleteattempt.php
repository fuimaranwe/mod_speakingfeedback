<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Confirm and delete one student attempt from the teacher report.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$attemptid = required_param('attemptid', PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$student = trim(optional_param('student', '', PARAM_TEXT));
[$cm, $course, $context] = \mod_speakingfeedback\local\access::activity($id);
$attempt = \mod_speakingfeedback\local\attempts::get($attemptid);
\mod_speakingfeedback\local\access::delete_attempt($attempt, $cm);
$params = ['id' => $id, 'page' => $page, 'student' => $student];
$returnurl = new moodle_url('/mod/speakingfeedback/report.php', $params);
$deleteurl = new moodle_url('/mod/speakingfeedback/deleteattempt.php', $params + ['attemptid' => $attemptid]);
$PAGE->set_url($deleteurl);
$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$PAGE->set_title(get_string('deleteattempt', 'speakingfeedback'));
$PAGE->set_heading(format_string($course->fullname));

// A GET request only displays confirmation, regardless of its parameters.
if (data_submitted() && required_param('confirm', PARAM_BOOL)) {
    require_sesskey();
    try {
        \mod_speakingfeedback\local\attempts::delete_attempt($attemptid, $cm);
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'busy') {
            throw $e;
        }
        redirect($returnurl, get_string('deleteattemptbusy', 'speakingfeedback'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    redirect($returnurl, get_string('attemptdeleted', 'speakingfeedback'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$user = \core_user::get_user($attempt->userid, '*', MUST_EXIST);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_speakingfeedback/delete_attempt', [
    'deleteurl' => $deleteurl->out(false), 'cancelurl' => $returnurl->out(false), 'sesskey' => sesskey(),
    'message' => get_string('deleteattemptconfirm', 'speakingfeedback',
        (object)['id' => $attemptid, 'student' => fullname($user)]),
]);
echo $OUTPUT->footer();
