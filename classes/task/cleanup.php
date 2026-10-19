<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Draft cleanup.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\task;
defined('MOODLE_INTERNAL') || die();

/** Remove only unused draft metadata older than one day. */
class cleanup extends \core\task\scheduled_task {
    /** Localized name. */
    public function get_name() {
        return get_string('cleanup', 'speakingfeedback');
    }
    /** No API calls and no removal of submitted history. */
    public function execute() {
        global $DB;
        $DB->delete_records_select('speakingfeedback_attempts',
            'status = ? AND timesubmitted = 0 AND timemodified < ?', ['draft', time() - DAYSECS]);
    }
}
