<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Attempt deletion event.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\event;

defined('MOODLE_INTERNAL') || die();

/** Teacher deletion audit containing identifiers only. */
class attempt_deleted extends \core\event\base {
    /** Event metadata. */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'speakingfeedback_attempts';
    }

    /** Event label. */
    public static function get_name() {
        return get_string('attemptdeleted', 'speakingfeedback');
    }

    /** Description excludes recordings, transcripts, prompts, and feedback. */
    public function get_description() {
        return "The user with id '$this->userid' deleted speaking attempt '$this->objectid' for user '$this->relateduserid'.";
    }

    /** Report URL. */
    public function get_url() {
        return new \moodle_url('/mod/speakingfeedback/report.php', ['id' => $this->contextinstanceid]);
    }

    /** Deleted records cannot be mapped during restore. */
    public static function get_objectid_mapping() {
        return ['db' => 'speakingfeedback_attempts', 'restore' => self::NOT_MAPPED];
    }
}
