<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Teacher review.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
$id = required_param('id', PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$student = trim(optional_param('student', '', PARAM_TEXT));
[$cm, $course, $context, $activity] = \mod_speakingfeedback\local\access::activity($id);
require_capability('mod/speakingfeedback:viewreports', $context);
$PAGE->set_url('/mod/speakingfeedback/report.php', ['id' => $id, 'student' => $student]);
$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$PAGE->set_title(get_string('report', 'speakingfeedback'));
$PAGE->set_heading(format_string($course->fullname));
$group = groups_get_activity_group($cm, true);
$params = ['activity' => $activity->id];
$where = 'a.speakingfeedbackid = :activity AND a.timesubmitted > 0';
$allowed = groups_get_activity_allowed_groups($cm);
if ($group && !isset($allowed[$group])) {
    throw new required_capability_exception($context, 'mod/speakingfeedback:viewreports', 'nopermissions', '');
}
if ($group) {
    $where .= ' AND EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.userid = a.userid AND gm.groupid = :groupid)';
    $params['groupid'] = $group;
} else if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
    if (!$allowed) {
        $where .= ' AND 1 = 0';
    } else {
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($allowed), SQL_PARAMS_NAMED, 'grp');
        $where .= " AND EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.userid = a.userid AND gm.groupid $insql)";
        $params += $inparams;
    }
}
if ($student !== '') {
    $where .= ' AND (' . $DB->sql_like('u.firstname', ':firstname', false) . ' OR '
        . $DB->sql_like('u.lastname', ':lastname', false) . ')';
    $params['firstname'] = $params['lastname'] = '%' . $DB->sql_like_escape($student) . '%';
}
$from = '{speakingfeedback_attempts} a JOIN {user} u ON u.id = a.userid';
$total = $DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
$namefields = \core_user\fields::get_name_fields();
$userfields = implode(', ', array_map(static fn($f) => 'u.' . $f, $namefields));
$records = $DB->get_records_sql("SELECT a.*, $userfields FROM $from WHERE $where ORDER BY a.id DESC", $params, $page * 25, 25);
$attempts = \mod_speakingfeedback\local\attempts::export_many($records, $context);
$candelete = has_capability('mod/speakingfeedback:deleteattempts', $context);
foreach ($attempts as &$entry) {
    $entry['studentname'] = fullname($records[$entry['id']]);
    $entry['canretry'] = false;
    if ($candelete) {
        $entry['deleteurl'] = (new moodle_url('/mod/speakingfeedback/deleteattempt.php', [
            'id' => $id, 'attemptid' => $entry['id'], 'page' => $page, 'student' => $student,
        ]))->out(false);
    }
}
unset($entry);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_speakingfeedback/report', [
    'cmid' => $id, 'student' => $student,
    'groupmenu' => groups_print_activity_menu($cm, $PAGE->url, true),
    'attempts' => $attempts, 'hasattempts' => (bool)$attempts,
    'pagination' => $OUTPUT->paging_bar($total, $page, 25, $PAGE->url),
    'backurl' => (new moodle_url('/mod/speakingfeedback/view.php', ['id' => $id]))->out(false),
]);
echo $OUTPUT->footer();
