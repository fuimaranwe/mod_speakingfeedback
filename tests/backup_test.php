<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Backup and restore integration coverage.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback;
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/backup/tests/backup_restore_base_testcase.php');

/** Round-trip user history using Moodle's real backup engine. */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class backup_test extends \core_backup_backup_restore_base_testcase {
    /** Audio, transcript and feedback survive; credentials and submission tokens do not. */
    public function test_restore_user_history(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $activity = $this->getDataGenerator()->create_module('speakingfeedback', ['course' => $course->id]);
        \mod_speakingfeedback\local\credentials::save($activity->id, 'never-back-up-this-secret');
        $this->setUser($student);
        $cm = get_fast_modinfo($course)->get_cm($activity->cmid);
        $attempt = \mod_speakingfeedback\local\attempts::begin($activity, $student->id, str_repeat('a', 32));
        $audio = "\x1a\x45\xdf\xa3webm" . str_repeat("\0", 100);
        $attempt = \mod_speakingfeedback\local\attempts::upload($attempt->id, $cm, $activity, $audio, 1000);
        $attempt->transcript = 'The park is peaceful.';
        $attempt->feedback = 'A clear description.';
        $attempt->status = 'complete';
        $DB->update_record('speakingfeedback_attempts', $attempt);
        $this->setAdminUser();
        $backupid = $this->perform_backup($course);
        $destination = $this->getDataGenerator()->create_course();
        $this->perform_restore($backupid, $destination);
        $restored = $DB->get_record('speakingfeedback', ['course' => $destination->id], '*', MUST_EXIST);
        $saved = $DB->get_record('speakingfeedback_attempts', ['speakingfeedbackid' => $restored->id], '*', MUST_EXIST);
        $this->assertSame($attempt->transcript, $saved->transcript);
        $this->assertSame($attempt->feedback, $saved->feedback);
        $this->assertSame('complete', $saved->status);
        $this->assertNotEquals($attempt->token, $saved->token);
        $this->assertFalse(\mod_speakingfeedback\local\credentials::exists($restored->id));
        $newcm = get_coursemodule_from_instance('speakingfeedback', $restored->id);
        $files = get_file_storage()->get_area_files(\context_module::instance($newcm->id)->id,
            'mod_speakingfeedback', 'recording', $saved->id, 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame($audio, reset($files)->get_content());
    }
}
