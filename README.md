# Speaking feedback

English speaking practice for Moodle 5.2+, styled with the active theme. Students record locally, submit audio, and receive transcript-based feedback from Groq. There is no gradebook score.

## Installation

1. Place this directory at `public/mod/speakingfeedback` in Moodle 5.2.
2. Visit **Site administration → Notifications** to install the module schema and capabilities. Review any other pending site upgrades before running Moodle's general CLI upgrader.
3. Add **Speaking feedback** to a course. Enter a Groq API key and the question, then adjust the feedback prompt, timers, attempts, and standard Moodle settings.
4. Use HTTPS for remote learners. Browsers permit microphone capture on localhost for development. The site also needs PHP cURL, Sodium, outbound HTTPS access to `api.groq.com`, and a writable Moodle secret-key directory.

No changes to Moodle core or Weeky are required. AMD production assets are included. To rebuild from the Moodle root:

```text
npx grunt amd --root=public/mod/speakingfeedback
```

## Updating existing installations

Back up the site's database and moodledata, upload the updated module files, then visit **Site administration → Notifications** (`/admin/index.php`) and complete the plugin upgrade. Review any other pending upgrades before proceeding. Purge Moodle caches after the upgrade. Copying PHP files alone does not update an installed database; `install.xml` is only used for new installations.

Version 0.1.1 (2026101700) adds the missing `completionfeedback` column on early installations. Existing activities default to having the optional feedback completion rule disabled. If the column already exists, its values are preserved. This resolves `Unknown column 'completionfeedback'` when loading course information, including through Weeky Analytics. Do not uninstall/reinstall the activity to repair this error, as uninstalling deletes its data.

Version 0.1.2 (2026101701) also repairs a missing `maxattempts` column before the completion upgrade. It supports retrying the failed 0.1.1 upgrade and upgrading sites where 0.1.1 already succeeded. Missing attempt limits default to unlimited; existing limits and completion settings are preserved. Upload the updated files and return to Notifications to retry a failed upgrade.

Version 0.1.3 (2026101702) adds teacher deletion of individual attempts. Upload the complete updated module and run Notifications to register the new deletion permission, then purge Moodle caches.

## Settings and behaviour

- Preparation: 30 seconds by default, configurable from 0–600 seconds.
- Recording: 120 seconds by default, configurable from 10–600 seconds. This is a browser practice timer, not a proctored examination boundary.
- Attempts: zero means unlimited, otherwise 1–1000 accepted recordings. Re-recording before submission is local and free; processing retries reuse the original submission.
- **Start preparation** requests microphone permission and begins the preparation countdown, with a notice explaining that recording starts automatically afterward. With zero preparation time the button reads **Start recording**. Recording shows “Recording — speak now” and a red indicator, and stops at the limit or when **Stop recording** is selected. **Try again** restarts the configured preparation and recording flow.
- **Get feedback** uploads and saves the recording, then runs transcription and feedback in separate requests. Audio is not sent to Groq until submission.
- Whisper uses `whisper-large-v3-turbo`, English, and the transcription endpoint. Feedback uses `openai/gpt-oss-120b`. Only the normal assistant content is displayed, with Markdown sanitised by Moodle.
- AI feedback is based on the transcript; pronunciation, accent, pace, and vocal fluency are not assessed. Whisper and feedback can be wrong. Teachers can edit the prompt to specify level and criteria.
- Successful feedback can satisfy the custom completion rule. Manual completion and viewing completion are also supported.
- Students see their own submitted history. Teachers with the report capability see permitted students, subject to separate groups. Reports support name searches and group filtering.
- Teachers can open **Student attempts → Delete attempt**, then confirm deletion of a recording, transcript, and feedback. Teachers and managers receive the separate **Delete student speaking attempts** permission by default; administrators can restrict it through Moodle roles. Deletion respects separate groups, is logged, frees an attempt allowance, and recalculates automatic completion. Manual completion and explicit teacher completion overrides are preserved. Attempts cannot be deleted while that student's audio is being saved or processed.

## Operations and credentials

Keys are stored in a separate table encrypted by Moodle `core\encryption`. They are never prefilled into forms, sent to browsers, or included in course/activity backups. Blank retains the key; **Clear the stored API key** removes it. Duplication/restore requires a new key.

Back up the Moodle encryption key directory with restricted access (`$CFG->secretdataroot/key` when configured, or `$CFG->dataroot/secret/key`). A database backup alone cannot recover encrypted keys. Keep dataroot outside the public web directory, protect OS permissions, and never publish backups or secret keys.

The server allows two concurrent Groq calls per activity and one per student/activity. This protects individual activities; total site concurrency and provider quotas still require appropriately sized PHP workers and Groq capacity. No global queue or background transcription worker is installed. Busy/rate-limited attempts remain saved and offer explicit retry. Separate activities using the same Groq key share that provider's quota.

Each provider request has a 10-second connection timeout and 90-second overall timeout. PHP requests raise their execution limit to 120 seconds, subject to site limits. Configure reverse proxies/web servers accordingly. In an ambiguous timeout the provider may already have processed a call; retrying can incur another provider charge. Completed database stages are not repeated.

Uploads are limited to the lower of Moodle/PHP/course limits and 25,000,000 bytes. WebM, Opus Ogg, and MP4 containers are accepted with signature validation and Moodle antivirus scanning when configured. There is no server transcoding requirement. Set PHP `upload_max_filesize` and `post_max_size` high enough for the desired limit; `post_max_size` must also allow multipart overhead.

Submitted audio, transcript, prompt snapshots, and feedback remain until course reset, activity deletion, or Moodle privacy deletion. Backups include user data only when enabled. A daily scheduled task removes abandoned, unsubmitted metadata after 24 hours; immediate feedback does not depend on cron. Moodle deletion cannot delete data already held by Groq; the site's external processing policy should account for the provider's retention terms.

## Validation

From the Moodle root, with its isolated PHPUnit environment configured:

```text
php public/admin/tool/phpunit/cli/init.php
php vendor/bin/phpunit --test-suffix=_test.php public/mod/speakingfeedback/tests
node --test public/mod/speakingfeedback/tests/recorder_test.mjs
npx grunt amd --root=public/mod/speakingfeedback
```

Tests use fake keys and mocked provider responses. Never put real API credentials in fixtures. See `tests/manual-checks.md` for real browser and live-provider verification; those checks need a working site, microphone, and teacher-supplied key.

## AJAX contract

`mod_speakingfeedback_attempt` accepts `cmid`, `action`, optional `attemptid`, `token`, and `page`. Actions are `begin`, `transcribe`, `feedback`, `status`, and `list`. Mutations require Moodle AJAX session-key checks and the submit capability; every attempt operation checks ownership. JSON responses contain only student-visible fields, never configuration prompts or credentials.

`upload.php` accepts a same-origin multipart POST containing `id` (course module), `attemptid`, `sesskey`, and `audio`. It validates authorization, uploaded-file provenance, size, antivirus checks, and container signature. Reusing an accepted attempt returns its existing record without replacing the audio or consuming another attempt.
