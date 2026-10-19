<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Feedback event.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\event;
defined('MOODLE_INTERNAL') || die();

/** Feedback successfully persisted. */
class feedback_received extends attempt_submitted {
    /** Metadata. */
    protected function init() {
        parent::init();
        $this->data['crud'] = 'u';
    }
    /** Event label. */
    public static function get_name() {
        return get_string('complete', 'speakingfeedback');
    }
    /** Safe description. */
    public function get_description() {
        return "The user with id '$this->userid' received feedback for speaking attempt '$this->objectid'.";
    }
}
