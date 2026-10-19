<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Safe API error.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\local;
defined('MOODLE_INTERNAL') || die();

/** Contains only a safe error identifier and a bounded retry delay. */
class provider_exception extends \moodle_exception {
    /** @var int Retry delay in seconds. */
    public int $delay;
    /** Construct without provider response bodies or secrets. */
    public function __construct(string $code, int $delay = 0) {
        $this->delay = min(86400, max(0, $delay));
        parent::__construct($code, 'speakingfeedback');
    }
}
