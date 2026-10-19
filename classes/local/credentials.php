<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Credential storage.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\local;
defined('MOODLE_INTERNAL') || die();

/** Keeps secrets out of activity records and backups. */
class credentials {
    /** Whether the activity has a key. */
    public static function exists(int $id): bool {
        global $DB;
        return $DB->record_exists('speakingfeedback_keys', ['speakingfeedbackid' => $id]);
    }

    /** Store a replacement or explicitly clear; blank preserves the current key. */
    public static function save(int $id, string $key, bool $clear = false): void {
        global $DB;
        if (preg_match('/[\r\n]/', $key)) {
            throw new \moodle_exception('invalidrequest', 'speakingfeedback');
        }
        if ($clear && trim($key) !== '') {
            throw new \moodle_exception('keyconflict', 'mod_speakingfeedback');
        }
        if ($clear) {
            $DB->delete_records('speakingfeedback_keys', ['speakingfeedbackid' => $id]);
        } else if (trim($key) !== '') {
            $record = $DB->get_record('speakingfeedback_keys', ['speakingfeedbackid' => $id]) ?: new \stdClass();
            $record->speakingfeedbackid = $id;
            $record->secret = \core\encryption::encrypt(trim($key));
            if (!empty($record->id)) {
                $DB->update_record('speakingfeedback_keys', $record);
            } else {
                $DB->insert_record('speakingfeedback_keys', $record);
            }
        }
    }

    /** Decrypt only immediately before an API call; never include exception details. */
    public static function get(int $id): string {
        global $DB;
        $secret = $DB->get_field('speakingfeedback_keys', 'secret', ['speakingfeedbackid' => $id]);
        if (!$secret) {
            throw new \moodle_exception('keymissing', 'mod_speakingfeedback');
        }
        try {
            return \core\encryption::decrypt($secret);
        } catch (\Throwable $e) {
            throw new \moodle_exception('credentialerror', 'mod_speakingfeedback');
        }
    }
}
