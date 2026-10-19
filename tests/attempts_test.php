<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Integration tests.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback;
use mod_speakingfeedback\local\attempts;
use mod_speakingfeedback\local\credentials;
use mod_speakingfeedback\local\groq_client;
use mod_speakingfeedback\local\provider_exception;
defined('MOODLE_INTERNAL') || die();

/** Integration coverage for the attempt lifecycle. */
#[\PHPUnit\Framework\Attributes\CoversClass(attempts::class)]
final class attempts_test extends \advanced_testcase {
    /** Create an enrolled student and activity. */
    private function setup_activity(array $settings = []): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $activity = $this->getDataGenerator()->create_module('speakingfeedback', $settings + ['course' => $course->id]);
        credentials::save($activity->id, 'test-only-secret');
        $this->setUser($student);
        $cm = get_fast_modinfo($course)->get_cm($activity->cmid);
        return [$course, $student, $activity, $cm];
    }

    /** Small container fixture sufficient for upload validation; no actual API call. */
    private function audio(): string {
        return "\x1a\x45\xdf\xa3webm" . str_repeat("\0", 100);
    }

    /** A double click creates and counts only one submission. */
    public function test_idempotent_submission_and_limit(): void {
        [$course, $student, $activity, $cm] = $this->setup_activity(['maxattempts' => 1]);
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        $this->assertSame(0, attempts::count($activity->id, $student->id));
        $this->assertEquals($attempt->id, attempts::begin($activity, $student->id, str_repeat('a', 32))->id);
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $this->assertSame(1, attempts::count($activity->id, $student->id));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('limitreached', 'speakingfeedback'));
        attempts::begin($activity, $student->id, str_repeat('b', 32));
    }

    /** A stale draft cannot bypass a limit consumed in another tab. */
    public function test_limit_rechecked_at_upload(): void {
        global $DB;
        [$course, $student, $activity, $cm] = $this->setup_activity(['maxattempts' => 1]);
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        $other = clone $attempt;
        unset($other->id);
        $other->token = str_repeat('b', 32);
        $other->status = 'complete';
        $other->timesubmitted = time();
        $DB->insert_record('speakingfeedback_attempts', $other);
        $this->expectException(\moodle_exception::class);
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
    }

    /** Other students cannot inspect or mutate an attempt. */
    public function test_ownership(): void {
        [$course, $student, $activity, $cm] = $this->setup_activity();
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $this->setUser($other);
        $this->expectException(\required_capability_exception::class);
        \mod_speakingfeedback\local\access::attempt($attempt, $cm);
    }

    /** Group membership applies even to direct review of an attempt. */
    public function test_separate_groups(): void {
        [$course, $student, $activity, $cm] = $this->setup_activity(['groupmode' => SEPARATEGROUPS]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->setUser($teacher);
        $this->assertFalse(\mod_speakingfeedback\local\access::can_review_user($cm, $student->id));
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $this->assertTrue(\mod_speakingfeedback\local\access::can_review_user($cm, $student->id));
    }

    /** Save transcription once, retry feedback, and mark completion only on success. */
    public function test_feedback_retry_and_completion(): void {
        global $DB;
        [$course, $student, $activity, $cm] = $this->setup_activity([
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionfeedback' => 1,
        ]);
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $client = $this->createMock(groq_client::class);
        $client->expects($this->once())->method('transcribe')->willReturn('My favourite place is the park.');
        $client->expects($this->exactly(2))->method('feedback')->willReturnCallback(function() {
            static $calls = 0;
            if (++$calls === 1) {
                throw new provider_exception('providererror');
            }
            return '## Strengths' . "\nClear and relevant.";
        });
        $saved = attempts::process($attempt->id, $cm, 'transcribe', $client);
        $this->assertNotEmpty($saved->transcript);
        $failed = attempts::process($attempt->id, $cm, 'feedback', $client);
        $this->assertSame('saved', $failed->status);
        $this->assertNotEmpty($failed->transcript);
        attempts::process($attempt->id, $cm, 'transcribe', $client);
        $done = attempts::process($attempt->id, $cm, 'feedback', $client);
        $this->assertSame('complete', $done->status);
        $this->assertSame(1, attempts::count($activity->id, $student->id));
        $state = $DB->get_field('course_modules_completion', 'completionstate', ['coursemoduleid' => $cm->id, 'userid' => $student->id]);
        $this->assertEquals(COMPLETION_COMPLETE, $state);
        attempts::process($attempt->id, $cm, 'feedback', $client);
    }

    /** Held processing lock prevents overlapping requests. */
    public function test_concurrent_processing_is_rejected(): void {
        global $CFG;
        [$course, $student, $activity, $cm] = $this->setup_activity();
        // Separate file handles model distinct requests. MySQL permits the same
        // database connection to reacquire a lock through another factory instance.
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $lock = attempts::lock('user:' . $activity->id . ':' . $student->id);
        try {
            $client = $this->createMock(groq_client::class);
            $client->expects($this->never())->method('transcribe');
            $this->expectException(\moodle_exception::class);
            attempts::process($attempt->id, $cm, 'transcribe', $client);
        } finally {
            $lock->release();
        }
    }

    /** Silent recordings never generate feedback. */
    public function test_no_speech(): void {
        [$course, $student, $activity, $cm] = $this->setup_activity();
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $client = $this->createMock(groq_client::class);
        $client->method('transcribe')->willThrowException(new provider_exception('nospeech'));
        $client->expects($this->never())->method('feedback');
        $result = attempts::process($attempt->id, $cm, 'transcribe', $client);
        $this->assertSame('nospeech', $result->status);
        $this->assertSame('nospeech', attempts::process($attempt->id, $cm, 'feedback', $client)->status);
    }

    /** Feedback does not override a student's manual completion choice. */
    public function test_manual_completion_unchanged(): void {
        global $DB;
        [$course, $student, $activity, $cm] = $this->setup_activity(['completion' => COMPLETION_TRACKING_MANUAL]);
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        $attempt = attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $attempt->transcript = 'I enjoy reading.';
        $DB->update_record('speakingfeedback_attempts', $attempt);
        $client = $this->createMock(groq_client::class);
        $client->method('feedback')->willReturn('A clear response.');
        attempts::process($attempt->id, $cm, 'feedback', $client);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }

    /** Rate-limit delays persist and block repeated provider calls until expiry. */
    public function test_rate_limit_delay(): void {
        [$course, $student, $activity, $cm] = $this->setup_activity();
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $client = $this->createMock(groq_client::class);
        $client->expects($this->once())->method('transcribe')->willThrowException(new provider_exception('ratelimited', 60));
        $result = attempts::process($attempt->id, $cm, 'transcribe', $client);
        $this->assertGreaterThan(time(), $result->retryafter);
        $this->assertSame('ratelimited', attempts::process($attempt->id, $cm, 'transcribe', $client)->errorcode);
    }

    /** Both occupied provider slots prevent a third request without losing audio. */
    public function test_provider_concurrency_limit(): void {
        global $CFG;
        [$course, $student, $activity, $cm] = $this->setup_activity();
        $CFG->lock_factory = '\\core\\lock\\file_lock_factory';
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $one = attempts::lock('provider:' . $activity->id . ':0');
        $two = attempts::lock('provider:' . $activity->id . ':1');
        try {
            $client = $this->createMock(groq_client::class);
            $client->expects($this->never())->method('transcribe');
            $this->expectException(\moodle_exception::class);
            $this->expectExceptionMessage(get_string('busy', 'speakingfeedback'));
            attempts::process($attempt->id, $cm, 'transcribe', $client);
        } finally {
            $one->release();
            $two->release();
            $this->assertSame('saved', attempts::get($attempt->id)->status);
        }
    }

    /** API keys are encrypted, blank preserves, and clear removes. */
    public function test_credentials(): void {
        global $DB;
        [$course, $student, $activity] = $this->setup_activity();
        $stored = $DB->get_field('speakingfeedback_keys', 'secret', ['speakingfeedbackid' => $activity->id]);
        $this->assertStringNotContainsString('test-only-secret', $stored);
        credentials::save($activity->id, '');
        $this->assertSame('test-only-secret', credentials::get($activity->id));
        credentials::save($activity->id, 'replacement');
        $this->assertSame('replacement', credentials::get($activity->id));
        credentials::save($activity->id, '', true);
        $this->assertFalse(credentials::exists($activity->id));
    }

    /** Reject script uploads and oversized data. */
    public function test_upload_validation(): void {
        [$course, $student, $activity, $cm] = $this->setup_activity();
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        $this->expectException(\moodle_exception::class);
        attempts::upload($attempt->id, $cm, $activity, str_repeat('<script>alert(1)</script>', 5), 1000);
    }

    /** Feedback is cleaned before display. */
    public function test_output_and_privacy_deletion(): void {
        global $DB;
        [$course, $student, $activity, $cm] = $this->setup_activity();
        $attempt = attempts::begin($activity, $student->id, str_repeat('a', 32));
        $attempt = attempts::upload($attempt->id, $cm, $activity, $this->audio(), 1000);
        $attempt->feedback = '<script>alert(1)</script><b>Feedback</b>';
        $export = attempts::export($attempt, \context_module::instance($cm->id));
        $this->assertStringNotContainsString('<script>', $export['feedback']);
        $this->assertArrayNotHasKey('feedbackprompt', $export);
        $context = \context_module::instance($cm->id);
        $contexts = \mod_speakingfeedback\privacy\provider::get_contexts_for_userid($student->id);
        $this->assertContains((int)$context->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist($student, 'mod_speakingfeedback', [$context->id]);
        \mod_speakingfeedback\privacy\provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('speakingfeedback_attempts', ['id' => $attempt->id]));
        $this->assertEmpty(get_file_storage()->get_area_files($context->id, 'mod_speakingfeedback', 'recording', $attempt->id));
        $this->assertTrue(credentials::exists($activity->id));
    }
}
