<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Moodle integration tests.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback;
defined('MOODLE_INTERNAL') || die();

/** Cross-subsystem integration checks. */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class integration_test extends \advanced_testcase {
    /** Duplication round-trips settings without transferring the key. */
    public function test_duplicate_excludes_credentials(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('speakingfeedback', ['course' => $course->id,
            'instructions' => '<p>Tell a story.</p>', 'preparationtime' => 45, 'recordingtime' => 90]);
        \mod_speakingfeedback\local\credentials::save($activity->id, 'secret-not-to-copy');
        $copy = \core_courseformat\formatactions::cm($course->id)->duplicate($activity->cmid);
        $this->assertNotNull($copy);
        $restored = $DB->get_record('speakingfeedback', ['id' => $copy->instance], '*', MUST_EXIST);
        $this->assertSame($activity->instructions, $restored->instructions);
        $this->assertEquals(45, $restored->preparationtime);
        $this->assertEquals(90, $restored->recordingtime);
        $this->assertFalse(\mod_speakingfeedback\local\credentials::exists($restored->id));
        $this->assertTrue(\mod_speakingfeedback\local\credentials::exists($activity->id));
    }

    /** Templates escape student text and render all controls. */
    public function test_templates(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/');
        $html = $OUTPUT->render_from_template('mod_speakingfeedback/activity', [
            'cmid' => 1, 'instructions' => '<p>Task</p>', 'cansubmit' => true, 'canrecord' => true,
            'hasattempts' => true, 'attempts' => [[
                'id' => 5, 'date' => 'Today', 'transcript' => '<script>alert(1)</script>',
                'statuslabel' => 'Saved', 'canretry' => true,
            ]],
        ]);
        $this->assertStringContainsString('data-action="record"', $html);
        $this->assertStringContainsString('data-action="retry"', $html);
        $this->assertStringContainsString('Attempt 5', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /** Teacher form includes all native settings and validates ranges. */
    public function test_configuration_form(): void {
        global $CFG, $PAGE, $COURSE;
        require_once($CFG->dirroot . '/mod/speakingfeedback/mod_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $COURSE = $course;
        $activity = $this->getDataGenerator()->create_module('speakingfeedback', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('speakingfeedback', $activity->cmid);
        $PAGE->set_course($course);
        $PAGE->set_context(\context_module::instance($cm->id));
        $PAGE->set_url('/course/modedit.php');
        $current = (object)((array)$activity + ['instance' => $activity->id, 'coursemodule' => $cm->id,
            'modulename' => 'speakingfeedback', 'completion' => 0]);
        $form = new \mod_speakingfeedback_mod_form($current, 0, $cm, $course);
        $data = ['preparationtime' => '-1', 'recordingtime' => '601', 'maxattempts' => '1.5',
            'feedbackprompt' => 'Feedback', 'apikey' => 'newkey', 'clearkey' => 1, 'name' => 'Practice',
            'modulename' => 'speakingfeedback', 'instance' => $activity->id, 'coursemodule' => $cm->id, 'cmidnumber' => '',
            'availabilityconditionsjson' => '{"op":"&","c":[],"showc":[]}'];
        $errors = $form->validation($data, []);
        foreach (['preparationtime', 'recordingtime', 'maxattempts', 'apikey'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }
}
