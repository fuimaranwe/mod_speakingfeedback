<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Submission event.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\event;
defined('MOODLE_INTERNAL') || die();

/** Accepted recording; contains no personal content or secrets. */
class attempt_submitted extends \core\event\base {
    /** Metadata. */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'speakingfeedback_attempts';
    }
    /** Event label. */
    public static function get_name() {
        return get_string('saved', 'speakingfeedback');
    }
    /** Safe description. */
    public function get_description() {
        return "The user with id '$this->userid' submitted speaking attempt '$this->objectid'.";
    }
    /** Activity URL. */
    public function get_url() {
        return new \moodle_url('/mod/speakingfeedback/view.php', ['id' => $this->contextinstanceid]);
    }
    /** Restore mapping. */
    public static function get_objectid_mapping() {
        return ['db' => 'speakingfeedback_attempts', 'restore' => 'speakingfeedback_attempt'];
    }
}
