<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Capabilities.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'mod/speakingfeedback:addinstance' => [
        'riskbitmask' => RISK_XSS, 'captype' => 'write', 'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],
    'mod/speakingfeedback:view' => [
        'captype' => 'read', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['student' => CAP_ALLOW, 'teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'mod/speakingfeedback:submit' => [
        'riskbitmask' => RISK_PERSONAL, 'captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['student' => CAP_ALLOW],
    ],
    'mod/speakingfeedback:viewreports' => [
        'riskbitmask' => RISK_PERSONAL, 'captype' => 'read', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'mod/speakingfeedback:deleteattempts' => [
        'riskbitmask' => RISK_DATALOSS, 'captype' => 'write', 'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
];
