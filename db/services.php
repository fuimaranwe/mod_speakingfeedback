<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * AJAX service.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
$functions = [
    'mod_speakingfeedback_attempt' => [
        'classname' => 'mod_speakingfeedback\external\attempt',
        'methodname' => 'execute',
        'description' => 'Begin, process, inspect, or list the current user\'s speaking attempts.',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'mod/speakingfeedback:submit',
    ],
];
