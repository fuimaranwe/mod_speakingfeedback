<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Access checks.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\local;
defined('MOODLE_INTERNAL') || die();

/** Shared authorization for pages, AJAX and files. */
class access {
    /** Resolve and authorize a visible activity. */
    public static function activity(int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('speakingfeedback', $cmid, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        require_login($course, false, $cm);
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        $context = \context_module::instance($cmid);
        require_capability('mod/speakingfeedback:view', $context);
        return [$cm, $course, $context, $DB->get_record('speakingfeedback', ['id' => $cm->instance], '*', MUST_EXIST)];
    }

    /** Apply ownership and separate-group restrictions, including direct audio URLs. */
    public static function attempt(\stdClass $attempt, \cm_info $cm, bool $write = false): void {
        global $USER;
        $context = \context_module::instance($cm->id);
        if ((int)$attempt->speakingfeedbackid !== (int)$cm->instance) {
            throw new \moodle_exception('invalidrequest', 'speakingfeedback');
        }
        if ($write) {
            require_capability('mod/speakingfeedback:submit', $context);
            if ((int)$attempt->userid !== (int)$USER->id) {
                throw new \required_capability_exception($context, 'mod/speakingfeedback:submit', 'nopermissions', '');
            }
        } else if ((int)$attempt->userid !== (int)$USER->id) {
            require_capability('mod/speakingfeedback:viewreports', $context);
            if (!self::can_review_user($cm, $attempt->userid)) {
                throw new \required_capability_exception($context, 'mod/speakingfeedback:viewreports', 'nopermissions', '');
            }
        }
    }

    /** Require teacher deletion permission for this activity and student's group. */
    public static function delete_attempt(\stdClass $attempt, \cm_info $cm): void {
        $context = \context_module::instance($cm->id);
        require_capability('mod/speakingfeedback:viewreports', $context);
        require_capability('mod/speakingfeedback:deleteattempts', $context);
        if ((int)$attempt->speakingfeedbackid !== (int)$cm->instance) {
            throw new \moodle_exception('invalidrequest', 'speakingfeedback');
        }
        if (!self::can_review_user($cm, $attempt->userid)) {
            throw new \required_capability_exception($context, 'mod/speakingfeedback:deleteattempts', 'nopermissions', '');
        }
    }

    /** Can this teacher review a student under the activity's group mode? */
    public static function can_review_user(\cm_info $cm, int $userid): bool {
        global $USER;
        $context = \context_module::instance($cm->id);
        if (groups_get_activity_groupmode($cm) != SEPARATEGROUPS || has_capability('moodle/site:accessallgroups', $context)) {
            return true;
        }
        $mine = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid, 'g.id');
        $theirs = groups_get_all_groups($cm->course, $userid, $cm->groupingid, 'g.id');
        return (bool)array_intersect_key($mine, $theirs);
    }
}
