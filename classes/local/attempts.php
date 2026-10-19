<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Attempt lifecycle.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback\local;
defined('MOODLE_INTERNAL') || die();

/** Persistent, idempotent attempts and bounded synchronous processing. */
class attempts {
    /** Resolve a module-owned attempt. */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record('speakingfeedback_attempts', ['id' => $id], '*', MUST_EXIST);
    }

    /** Obtain a short-wait cross-process lock. */
    public static function lock(string $resource): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('mod_speakingfeedback')->get_lock($resource, 0, 180);
        if (!$lock) {
            throw new \moodle_exception('busy', 'speakingfeedback');
        }
        return $lock;
    }

    /** Create one draft for this submission token, just before uploading. */
    public static function begin(\stdClass $activity, int $userid, string $token): \stdClass {
        global $DB;
        if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $token)) {
            throw new \moodle_exception('invalidrequest', 'speakingfeedback');
        }
        $lock = self::lock('user:' . $activity->id . ':' . $userid);
        try {
            $conditions = ['speakingfeedbackid' => $activity->id, 'userid' => $userid, 'token' => $token];
            if ($existing = $DB->get_record('speakingfeedback_attempts', $conditions)) {
                return $existing;
            }
            self::check_limit($activity, $userid);
            if (!credentials::exists($activity->id)) {
                throw new \moodle_exception('keymissing', 'speakingfeedback');
            }
            // One unused draft per user avoids unbounded metadata from repeated begin calls.
            $DB->delete_records('speakingfeedback_attempts', [
                'speakingfeedbackid' => $activity->id, 'userid' => $userid, 'status' => 'draft',
            ]);
            $record = (object)($conditions + [
                'status' => 'draft', 'instructions' => html_to_text($activity->instructions, 0, false),
                'feedbackprompt' => $activity->feedbackprompt, 'preparationtime' => $activity->preparationtime,
                'recordingtime' => $activity->recordingtime, 'timecreated' => time(), 'timemodified' => time(),
                'timesubmitted' => 0, 'retryafter' => 0, 'errorcode' => '',
            ]);
            $record->id = $DB->insert_record('speakingfeedback_attempts', $record);
            return self::get($record->id);
        } finally {
            $lock->release();
        }
    }

    /** Count accepted submissions, not drafts or retries. */
    public static function count(int $activityid, int $userid): int {
        global $DB;
        return $DB->count_records_select('speakingfeedback_attempts',
            'speakingfeedbackid = ? AND userid = ? AND timesubmitted > 0', [$activityid, $userid]);
    }

    /** Enforce the current teacher limit under the per-user lock. */
    private static function check_limit(\stdClass $activity, int $userid): void {
        if ($activity->maxattempts && self::count($activity->id, $userid) >= $activity->maxattempts) {
            throw new \moodle_exception('limitreached', 'speakingfeedback');
        }
    }

    /** Validate browser recording containers using signatures, not supplied MIME types. */
    public static function audio_type(string $content): array {
        if (strlen($content) < 32) {
            throw new \moodle_exception('invalidfile', 'speakingfeedback');
        }
        if (substr($content, 0, 4) === "\x1a\x45\xdf\xa3" && strpos(substr($content, 0, 4096), 'webm') !== false) {
            return ['webm', 'audio/webm'];
        }
        if (substr($content, 0, 4) === 'OggS' && strpos(substr($content, 0, 4096), 'OpusHead') !== false) {
            return ['ogg', 'audio/ogg'];
        }
        if (substr($content, 4, 4) === 'ftyp') {
            return ['mp4', 'audio/mp4'];
        }
        throw new \moodle_exception('invalidfile', 'speakingfeedback');
    }

    /** Effective upload cap. */
    public static function maxbytes(\stdClass $course): int {
        global $CFG;
        return min(25000000, get_max_upload_file_size($CFG->maxbytes, $course->maxbytes, 25000000));
    }

    /** Atomically accept a recording and consume one attempt. */
    public static function upload(int $id, \cm_info $cm, \stdClass $activity, string $content, int $maxbytes): \stdClass {
        global $DB;
        $attempt = self::get($id);
        access::attempt($attempt, $cm, true);
        $lock = self::lock('user:' . $activity->id . ':' . $attempt->userid);
        try {
            $attempt = self::get($id);
            if ($attempt->timesubmitted) {
                return $attempt;
            }
            self::check_limit($activity, $attempt->userid);
            if (strlen($content) > $maxbytes) {
                throw new \moodle_exception('filetoolarge', 'speakingfeedback');
            }
            [$extension, $mime] = self::audio_type($content);
            $context = \context_module::instance($cm->id);
            $transaction = $DB->start_delegated_transaction();
            get_file_storage()->delete_area_files($context->id, 'mod_speakingfeedback', 'recording', $id);
            get_file_storage()->create_file_from_string([
                'contextid' => $context->id, 'component' => 'mod_speakingfeedback', 'filearea' => 'recording',
                'itemid' => $id, 'filepath' => '/', 'filename' => 'recording.' . $extension,
                'mimetype' => $mime, 'userid' => $attempt->userid,
            ], $content);
            $attempt->status = 'saved';
            $attempt->timesubmitted = $attempt->timemodified = time();
            $DB->update_record('speakingfeedback_attempts', $attempt);
            \mod_speakingfeedback\event\attempt_submitted::create([
                'context' => $context, 'objectid' => $id, 'relateduserid' => $attempt->userid,
            ])->trigger();
            $transaction->allow_commit();
            return $attempt;
        } finally {
            $lock->release();
        }
    }

    /** Execute one persisted stage. Callers must close the session first. */
    public static function process(int $id, \cm_info $cm, string $stage, ?groq_client $client = null): \stdClass {
        global $DB;
        $attempt = self::get($id);
        access::attempt($attempt, $cm, true);
        if (!in_array($stage, ['transcribe', 'feedback'], true) || !$attempt->timesubmitted) {
            throw new \moodle_exception('invalidrequest', 'speakingfeedback');
        }
        $userlock = self::lock('user:' . $attempt->speakingfeedbackid . ':' . $attempt->userid);
        $slot = null;
        try {
            $attempt = self::get($id);
            if ($attempt->status === 'complete' || $attempt->status === 'nospeech' ||
                    ($stage === 'transcribe' && !empty($attempt->transcript))) {
                return $attempt;
            }
            if ($stage === 'feedback' && empty($attempt->transcript)) {
                throw new \moodle_exception('invalidrequest', 'speakingfeedback');
            }
            if ($attempt->retryafter > time()) {
                return $attempt;
            }
            $factory = \core\lock\lock_config::get_lock_factory('mod_speakingfeedback');
            for ($i = 0; $i < 2; $i++) {
                $slot = $factory->get_lock('provider:' . $attempt->speakingfeedbackid . ':' . $i, 0, 180);
                if ($slot) {
                    break;
                }
            }
            if (!$slot) {
                throw new \moodle_exception('busy', 'speakingfeedback');
            }
            $attempt->status = $stage === 'transcribe' ? 'transcribing' : 'generating';
            $attempt->errorcode = '';
            $attempt->timemodified = time();
            $DB->update_record('speakingfeedback_attempts', $attempt);
            try {
                \core_php_time_limit::raise(120);
                $key = credentials::get($attempt->speakingfeedbackid);
                $client = $client ?? new groq_client();
                if ($stage === 'transcribe') {
                    $files = get_file_storage()->get_area_files(\context_module::instance($cm->id)->id,
                        'mod_speakingfeedback', 'recording', $id, 'id', false);
                    if (!$files) {
                        throw new provider_exception('invalidfile');
                    }
                    $attempt->transcript = $client->transcribe(reset($files), $key);
                    $attempt->status = 'saved';
                } else {
                    $attempt->feedback = $client->feedback($attempt, $key);
                    $attempt->status = 'complete';
                }
                $attempt->retryafter = 0;
            } catch (\moodle_exception $e) {
                $allowed = ['nospeech', 'credentialerror', 'keymissing', 'providererror', 'ratelimited', 'invalidresponse', 'invalidfile'];
                $attempt->errorcode = in_array($e->errorcode, $allowed, true) ? $e->errorcode : 'providererror';
                $attempt->status = $attempt->errorcode === 'nospeech' ? 'nospeech' : 'saved';
                $attempt->retryafter = time() + ($e instanceof provider_exception ? $e->delay : 10);
            } catch (\Throwable $e) {
                $attempt->status = 'saved';
                $attempt->errorcode = 'providererror';
                $attempt->retryafter = time() + 10;
            }
            $attempt->timemodified = time();
            $DB->update_record('speakingfeedback_attempts', $attempt);
            if ($attempt->status === 'complete') {
                $completion = new \completion_info($cm->get_course());
                if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
                    $completion->update_state($cm, COMPLETION_COMPLETE, $attempt->userid);
                }
                \mod_speakingfeedback\event\feedback_received::create([
                    'context' => \context_module::instance($cm->id), 'objectid' => $id,
                    'relateduserid' => $attempt->userid,
                ])->trigger();
            }
            return $attempt;
        } finally {
            if ($slot) {
                $slot->release();
            }
            $userlock->release();
        }
    }

    /** Safe output; never include prompts or credentials. */
    public static function export(\stdClass $attempt, \context_module $context, ?array $files = null): array {
        $files = $files ?? get_file_storage()->get_area_files($context->id,
            'mod_speakingfeedback', 'recording', $attempt->id, 'id', false);
        $url = '';
        if ($files) {
            $file = reset($files);
            $url = \moodle_url::make_pluginfile_url($context->id, 'mod_speakingfeedback', 'recording',
                $attempt->id, '/', $file->get_filename())->out(false);
        }
        $error = $attempt->errorcode ? get_string($attempt->errorcode, 'speakingfeedback') : '';
        if ($attempt->retryafter > time()) {
            $error .= ' ' . get_string('retryat', 'speakingfeedback', userdate($attempt->retryafter));
        }
        return [
            'id' => (int)$attempt->id, 'status' => $attempt->status,
            'statuslabel' => get_string($attempt->status, 'speakingfeedback'),
            'date' => userdate($attempt->timesubmitted ?: $attempt->timecreated),
            'transcript' => $attempt->transcript ?? '',
            'feedback' => format_text($attempt->feedback ?? '', FORMAT_MARKDOWN,
                ['context' => $context, 'filter' => false, 'noclean' => false, 'para' => false]),
            'audiourl' => $url, 'error' => $error, 'retryafter' => (int)$attempt->retryafter,
            'canretry' => (bool)$attempt->timesubmitted && !in_array($attempt->status, ['complete', 'nospeech']),
        ];
    }

    /** Export a page with a single file metadata query, avoiding per-attempt queries. */
    public static function export_many(array $records, \context_module $context): array {
        if (!$records) {
            return [];
        }
        $ids = array_map(static fn($record) => $record->id, $records);
        $files = get_file_storage()->get_area_files($context->id, 'mod_speakingfeedback', 'recording', $ids, 'id', false);
        $byattempt = [];
        foreach ($files as $file) {
            $byattempt[$file->get_itemid()][] = $file;
        }
        return array_map(static fn($record) => self::export($record, $context, $byattempt[$record->id] ?? []),
            array_values($records));
    }

    /** Delete a single attempt as an authorized teacher, without racing processing or upload. */
    public static function delete_attempt(int $id, \cm_info $cm): void {
        global $DB;
        $attempt = self::get($id);
        access::delete_attempt($attempt, $cm);
        $lock = self::lock('user:' . $attempt->speakingfeedbackid . ':' . $attempt->userid);
        try {
            $attempt = self::get($id);
            access::delete_attempt($attempt, $cm);
            $context = \context_module::instance($cm->id);
            $transaction = $DB->start_delegated_transaction();
            get_file_storage()->delete_area_files($context->id, 'mod_speakingfeedback', 'recording', $id);
            $DB->delete_records('speakingfeedback_attempts', ['id' => $id]);
            $completion = new \completion_info($cm->get_course());
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
                $completion->update_state($cm, COMPLETION_UNKNOWN, $attempt->userid);
            }
            \mod_speakingfeedback\event\attempt_deleted::create([
                'context' => $context, 'objectid' => $id, 'relateduserid' => $attempt->userid,
            ])->trigger();
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /** Delete personal records and their files. Caller supplies authorized activity/user scope. */
    public static function delete(int $activityid, \context_module $context, ?int $userid = null): void {
        global $DB;
        $conditions = ['speakingfeedbackid' => $activityid];
        if ($userid !== null) {
            $conditions['userid'] = $userid;
        }
        $records = $DB->get_recordset('speakingfeedback_attempts', $conditions, '', 'id');
        foreach ($records as $record) {
            get_file_storage()->delete_area_files($context->id, 'mod_speakingfeedback', 'recording', $record->id);
        }
        $records->close();
        $DB->delete_records('speakingfeedback_attempts', $conditions);
    }
}
