<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Teacher attempt deletion tests.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback;

use mod_speakingfeedback\local\attempts;
use mod_speakingfeedback\local\credentials;

defined('MOODLE_INTERNAL') || die();

/** Verify permissions, file cleanup, completion, and processing locks. */
#[\PHPUnit\Framework\Attributes\CoversClass(attempts::class)]
final class delete_attempt_test extends \advanced_testcase {
    /** Create a submitted recording and an enrolled teacher. */
    private function setup_attempt(array $settings = []): array {
        $this->resetAfterTest();
        // Allows this test to run before the production plugin upgrade as well.
        update_capabilities('mod_speakingfeedback');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $student = $generator->create_user();
        $teacher = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'teacher');
        $activity = $generator->create_module('speakingfeedback', $settings + ['course' => $course->id]);
        credentials::save($activity->id, 'test-only-secret');
        $this->setUser($student);
        $cm = get_fast_modinfo($course)->get_cm($activity->cmid);
        $draft = attempts::begin($activity, $student->id, str_repeat('a', 32));
        $attempt = attempts::upload($draft->id, $cm, $activity, "\x1a\x45\xdf\xa3webm" . str_repeat("\0", 100), 1000);
        return [$course, $student, $teacher, $activity, $cm, $attempt];
    }

    /** Delete one attempt and audio, retain other attempts/key, free its allowance, and audit. */
    public function test_delete_attempt(): void {
        global $DB;
        [$course, $student, $teacher, $activity, $cm, $attempt] = $this->setup_attempt();
        $other = clone $attempt;
        unset($other->id);
        $other->token = str_repeat('b', 32);
        $otherid = $DB->insert_record('speakingfeedback_attempts', $other);
        $this->setUser($teacher);
        $sink = $this->redirectEvents();
        attempts::delete_attempt($attempt->id, $cm);
        $this->assertFalse($DB->record_exists('speakingfeedback_attempts', ['id' => $attempt->id]));
        $this->assertTrue($DB->record_exists('speakingfeedback_attempts', ['id' => $otherid]));
        $this->assertSame(1, attempts::count($activity->id, $student->id));
        $this->assertEmpty(get_file_storage()->get_area_files(\context_module::instance($cm->id)->id,
            'mod_speakingfeedback', 'recording', $attempt->id));
        $this->assertTrue(credentials::exists($activity->id));
        $events = $sink->get_events();
        $this->assertInstanceOf(\mod_speakingfeedback\event\attempt_deleted::class, end($events));
        $this->assertEquals($student->id, end($events)->relateduserid);
    }

    /** A student cannot delete even their own attempt. */
    public function test_student_cannot_delete(): void {
        [$course, $student, $teacher, $activity, $cm, $attempt] = $this->setup_attempt();
        $this->expectException(\required_capability_exception::class);
        attempts::delete_attempt($attempt->id, $cm);
    }

    /** A report-only teacher cannot delete. */
    public function test_capability_is_required(): void {
        global $DB;
        [$course, $student, $teacher, $activity, $cm, $attempt] = $this->setup_attempt();
        $context = \context_module::instance($cm->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('mod/speakingfeedback:deleteattempts', CAP_PROHIBIT, $roleid, $context->id);
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        attempts::delete_attempt($attempt->id, $cm);
    }

    /** An unrelated group and a different activity cannot be targeted by changing the URL. */
    public function test_activity_and_group_scope(): void {
        global $DB;
        [$course, $student, $teacher, $activity, $cm, $attempt] = $this->setup_attempt(['groupmode' => SEPARATEGROUPS]);
        $this->setUser($teacher);
        try {
            attempts::delete_attempt($attempt->id, $cm);
            $this->fail('Teacher without a shared group must not delete.');
        } catch (\required_capability_exception $e) {
            $this->assertTrue($DB->record_exists('speakingfeedback_attempts', ['id' => $attempt->id]));
        }
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        foreach ([$teacher, $student] as $user) {
            $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);
        }
        $otheractivity = $this->getDataGenerator()->create_module('speakingfeedback', ['course' => $course->id]);
        $othercm = get_fast_modinfo($course)->get_cm($otheractivity->cmid);
        try {
            attempts::delete_attempt($attempt->id, $othercm);
            $this->fail('Different activity must not delete.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidrequest', $e->errorcode);
        }
        attempts::delete_attempt($attempt->id, $cm);
        $this->assertFalse($DB->record_exists('speakingfeedback_attempts', ['id' => $attempt->id]));
    }

    /** Deletion cannot race with an upload or provider request for this student. */
    public function test_processing_lock_preserves_attempt(): void {
        global $CFG, $DB;
        [$course, $student, $teacher, $activity, $cm, $attempt] = $this->setup_attempt();
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $lock = attempts::lock('user:' . $activity->id . ':' . $student->id);
        $this->setUser($teacher);
        try {
            $this->expectException(\moodle_exception::class);
            $this->expectExceptionMessage(get_string('busy', 'speakingfeedback'));
            attempts::delete_attempt($attempt->id, $cm);
        } finally {
            $lock->release();
            $this->assertTrue($DB->record_exists('speakingfeedback_attempts', ['id' => $attempt->id]));
            $this->assertNotEmpty(get_file_storage()->get_area_files(\context_module::instance($cm->id)->id,
                'mod_speakingfeedback', 'recording', $attempt->id, 'id', false));
        }
    }

    /** Removing the last feedback changes automatic completion, preserving another complete attempt. */
    public function test_completion_is_recalculated(): void {
        global $DB;
        [$course, $student, $teacher, $activity, $cm, $attempt] = $this->setup_attempt([
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionfeedback' => 1,
        ]);
        $attempt->status = 'complete';
        $attempt->transcript = 'A response.';
        $attempt->feedback = 'Feedback.';
        $DB->update_record('speakingfeedback_attempts', $attempt);
        $other = clone $attempt;
        unset($other->id);
        $other->token = str_repeat('b', 32);
        $otherid = $DB->insert_record('speakingfeedback_attempts', $other);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->setUser($teacher);
        attempts::delete_attempt($attempt->id, $cm);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
        attempts::delete_attempt($otherid, $cm);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }

    /** Removing an attempt leaves a student's manual completion choice intact. */
    public function test_manual_completion_is_preserved(): void {
        [$course, $student, $teacher, $activity, $cm, $attempt] = $this->setup_attempt([
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->setUser($teacher);
        attempts::delete_attempt($attempt->id, $cm);
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }

    /** Confirmation uses a POST form with CSRF protection and escapes student names. */
    public function test_confirmation_template(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/');
        $html = $OUTPUT->render_from_template('mod_speakingfeedback/delete_attempt', [
            'deleteurl' => '/mod/speakingfeedback/deleteattempt.php?id=1&attemptid=2',
            'cancelurl' => '/mod/speakingfeedback/report.php?id=1', 'sesskey' => 'test-key',
            'message' => 'Delete attempt by <script>alert(1)</script>?',
        ]);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="sesskey" value="test-key"', $html);
        $this->assertStringContainsString('name="confirm" value="1"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
