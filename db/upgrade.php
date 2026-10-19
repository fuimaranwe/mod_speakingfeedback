<?php
// This file is part of Moodle - http://moodle.org/
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Upgrade existing Speaking feedback installations.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Apply database changes while preserving activity and attempt data.
 *
 * @param int $oldversion Installed plugin version.
 * @return bool
 */
function xmldb_speakingfeedback_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026101701) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('speakingfeedback');
        // Repair the prerequisite before the earlier completion upgrade runs. Do not
        // specify a preceding column: older installations may lack that column too.
        $field = new xmldb_field('maxattempts', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }

    if ($oldversion < 2026101700) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('speakingfeedback');
        $field = new xmldb_field('completionfeedback', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0', 'maxattempts');

        // Early installations can lack this field even though install.xml now defines it.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026101700, 'speakingfeedback');
    }

    if ($oldversion < 2026101701) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('speakingfeedback');
        $field = new xmldb_field('completionfeedback', XMLDB_TYPE_INTEGER, '1', null,
            XMLDB_NOTNULL, null, '0');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026101701, 'speakingfeedback');
    }

    return true;
}
