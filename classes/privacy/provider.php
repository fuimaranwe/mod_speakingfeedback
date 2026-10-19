<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Personal data handling.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\privacy;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_speakingfeedback\local\attempts;
defined('MOODLE_INTERNAL') || die();

/** Export and erase students' attempts and audio through Moodle privacy requests. */
class provider implements \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider, \core_privacy\local\request\core_userlist_provider {
    /** Declare stored data and the external recipient. */
    public static function get_metadata(collection $collection): collection {
        $fields = ['userid' => 'privacy:metadata:userid'];
        foreach (['instructions', 'feedbackprompt', 'transcript', 'feedback', 'token', 'preparationtime', 'recordingtime'] as $field) {
            $fields[$field] = 'privacy:metadata:content';
        }
        foreach (['timecreated', 'timesubmitted', 'timemodified'] as $field) {
            $fields[$field] = 'privacy:metadata:time';
        }
        foreach (['status', 'errorcode', 'retryafter'] as $field) {
            $fields[$field] = 'privacy:metadata:status';
        }
        $collection->add_database_table('speakingfeedback_attempts', $fields, 'privacy:metadata:attempts');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        $collection->add_external_location_link('groq', [
            'audio' => 'privacy:metadata:audio', 'transcript' => 'privacy:metadata:transcript',
            'instructions' => 'privacy:metadata:prompt', 'feedbackprompt' => 'privacy:metadata:prompt',
        ], 'privacy:metadata:groq');
        return $collection;
    }
    /** Discover user contexts. */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql('SELECT ctx.id FROM {context} ctx
            JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :level
            JOIN {modules} m ON m.id = cm.module AND m.name = :module
            JOIN {speakingfeedback_attempts} a ON a.speakingfeedbackid = cm.instance WHERE a.userid = :userid',
            ['level' => CONTEXT_MODULE, 'module' => 'speakingfeedback', 'userid' => $userid]);
        return $list;
    }
    /** Resolve only this plugin's module contexts. */
    private static function activityid(\context $context): ?int {
        if (!$context instanceof \context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('speakingfeedback', $context->instanceid);
        return $cm ? (int)$cm->instance : null;
    }
    /** Discover users in this module context. */
    public static function get_users_in_context(userlist $userlist) {
        if ($id = self::activityid($userlist->get_context())) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {speakingfeedback_attempts} WHERE speakingfeedbackid = :id',
                ['id' => $id]);
        }
    }
    /** Export records and associated recordings. */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$id = self::activityid($context)) {
                continue;
            }
            $records = $DB->get_recordset('speakingfeedback_attempts', [
                'speakingfeedbackid' => $id, 'userid' => $contextlist->get_user()->id,
            ]);
            foreach ($records as $record) {
                $path = [get_string('history', 'speakingfeedback'), (string)$record->id];
                writer::with_context($context)->export_data($path, $record);
                writer::with_context($context)->export_area_files($path, 'mod_speakingfeedback', 'recording', $record->id);
            }
            $records->close();
        }
    }
    /** Delete all student data in an approved context. */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($id = self::activityid($context)) {
            attempts::delete($id, $context);
        }
    }
    /** Delete this user's data in approved contexts. */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            if ($id = self::activityid($context)) {
                attempts::delete($id, $context, $contextlist->get_user()->id);
            }
        }
    }
    /** Delete approved users in a single context. */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($id = self::activityid($context)) {
            foreach ($userlist->get_userids() as $userid) {
                attempts::delete($id, $context, $userid);
            }
        }
    }
}
