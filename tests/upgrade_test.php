<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Upgrade regression tests.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback;

defined('MOODLE_INTERNAL') || die();

/** Verify both early and current installation schemas upgrade safely. */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class upgrade_test extends \advanced_testcase {
    /** @return array Upgrade entry points before and after the first repair. */
    public static function upgrade_versions(): array {
        return [[2026101600], [2026101700]];
    }

    /** Missing attempt and completion fields are added without replacing activities. */
    #[\PHPUnit\Framework\Attributes\DataProvider('upgrade_versions')]
    public function test_missing_completion_field(int $oldversion): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/speakingfeedback/db/upgrade.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('speakingfeedback', ['course' => $course->id]);
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('speakingfeedback');
        $field = new \xmldb_field('completionfeedback', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'maxattempts');
        $attemptfield = new \xmldb_field('maxattempts', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0');
        try {
            $dbman->drop_field($table, $field);
            $dbman->drop_field($table, $attemptfield);
            set_config('version', $oldversion, 'mod_speakingfeedback');
            $this->assertTrue(xmldb_speakingfeedback_upgrade($oldversion));
            $record = $DB->get_record('speakingfeedback', ['id' => $activity->id], '*', MUST_EXIST);
            $this->assertSame($activity->name, $record->name);
            $this->assertEquals(0, $record->completionfeedback);
            $this->assertEquals(0, $record->maxattempts);
            $this->assertEquals(2026101701, get_config('mod_speakingfeedback', 'version'));
        } finally {
            // DDL is not rolled back by the PHPUnit data reset.
            if (!$dbman->field_exists($table, $attemptfield)) {
                $dbman->add_field($table, $attemptfield);
            }
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
    }

    /** Existing completion settings survive, and the completed upgrade is a no-op. */
    public function test_existing_completion_field(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/speakingfeedback/db/upgrade.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('speakingfeedback', ['course' => $course->id]);
        $DB->set_field('speakingfeedback', 'completionfeedback', 1, ['id' => $activity->id]);
        $DB->set_field('speakingfeedback', 'maxattempts', 3, ['id' => $activity->id]);
        set_config('version', 2026101600, 'mod_speakingfeedback');
        $this->assertTrue(xmldb_speakingfeedback_upgrade(2026101600));
        $this->assertTrue(xmldb_speakingfeedback_upgrade(2026101701));
        $this->assertEquals(1, $DB->get_field('speakingfeedback', 'completionfeedback', ['id' => $activity->id]));
        $this->assertEquals(3, $DB->get_field('speakingfeedback', 'maxattempts', ['id' => $activity->id]));
    }
}
