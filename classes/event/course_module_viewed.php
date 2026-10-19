<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * View event.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\event;
defined('MOODLE_INTERNAL') || die();

/** A student or teacher viewed the activity. */
class course_module_viewed extends \core\event\course_module_viewed {
    /** Event metadata. */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'speakingfeedback';
    }
    /** Restore mapping. */
    public static function get_objectid_mapping() {
        return ['db' => 'speakingfeedback', 'restore' => 'speakingfeedback'];
    }
}
