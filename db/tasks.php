<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Draft cleanup schedule.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
$tasks = [[
    'classname' => 'mod_speakingfeedback\task\cleanup', 'blocking' => 0,
    'minute' => 'R', 'hour' => '3', 'day' => '*', 'month' => '*', 'dayofweek' => '*',
]];
