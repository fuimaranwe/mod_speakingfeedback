<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Protected multipart endpoint.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
$cmid = required_param('id', PARAM_INT);
$attemptid = required_param('attemptid', PARAM_INT);
[$cm, $course, $context, $activity] = \mod_speakingfeedback\local\access::activity($cmid);
require_sesskey();
require_capability('mod/speakingfeedback:submit', $context);
header('Content-Type: application/json; charset=utf-8');
try {
    // Multipart file metadata has no required_param equivalent. Validate PHP's upload
    // provenance and actual file size, following repository_upload's handling.
    $upload = $_FILES['audio'] ?? null;
    if (!$upload || !is_array($upload) || !isset($upload['tmp_name'], $upload['error']) ||
            !is_string($upload['tmp_name']) || $upload['error'] !== UPLOAD_ERR_OK ||
            !is_uploaded_file($upload['tmp_name'])) {
        throw new moodle_exception('uploaderror', 'speakingfeedback');
    }
    $maxbytes = \mod_speakingfeedback\local\attempts::maxbytes($course);
    if (filesize($upload['tmp_name']) > $maxbytes) {
        throw new moodle_exception('filetoolarge', 'speakingfeedback');
    }
    \core\antivirus\manager::scan_file($upload['tmp_name'], 'recording', true);
    $content = file_get_contents($upload['tmp_name']);
    if ($content === false) {
        throw new moodle_exception('invalidfile', 'speakingfeedback');
    }
    $record = \mod_speakingfeedback\local\attempts::upload($attemptid, $cm, $activity, $content,
        $maxbytes);
    echo json_encode(\mod_speakingfeedback\local\attempts::export($record, $context), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    $safe = ['invalidfile', 'filetoolarge', 'limitreached', 'busy', 'uploaderror'];
    $code = $e instanceof moodle_exception && in_array($e->errorcode, $safe) ? $e->errorcode : 'uploaderror';
    echo json_encode(['error' => get_string($code, 'speakingfeedback')]);
}
