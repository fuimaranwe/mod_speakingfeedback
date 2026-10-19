# Validation — 17 October 2026

Target: Moodle 5.2.2 (2026042002.00), PHP 8.3.33, MariaDB 10.11.18, Windows.

Passed:

- PHPUnit: **19 tests, 75 assertions**, using Moodle's isolated `phpu_` database tables and test dataroot.
- Recorder contract tests: **4 passed**, using simulated browser media APIs and timers.
- PHP syntax checks for all module PHP files.
- Moodle Grunt AMD build and ESLint.
- Moodle Grunt CSS stylelint and Gherkin lint.
- Template rendering and escaping under Moodle's renderer.
- Real Moodle activity duplication and course backup/restore, including protected audio and exclusion of credentials.
- Scoped local module installation at version `2026101600`. The pre-existing Weeky theme upgrade was not applied.

Important fixes made during validation:

- Use `--test-suffix=_test.php` when invoking this PHPUnit directory directly.
- Preserve manual completion choices when AI feedback succeeds.
- Keep retry controls visible immediately after saving, including when a network request subsequently fails.
- Query history audio metadata in one batch per page.
- Use uploaded-file provenance checks and Moodle antivirus scanning for multipart audio.

Not verified:

- Live Groq requests: require a teacher-supplied API key.
- Real microphone behaviour across Chrome, Edge, Firefox, Safari, and mobile browsers.
- Authenticated browser walkthrough and responsive visual acceptance: the available browser session was logged out.
- Behat execution: the site has no configured Behat URL/data environment. Feature files pass Gherkin lint.
- PHP CodeSniffer: no PHPCS executable is installed in the available project toolchain.

Use `manual-checks.md` for deployment acceptance. Mocked browser media tests do not establish physical microphone or browser codec compatibility.
# Upgrade repair — 17 October 2026

The 0.1.1 migration was tested against the isolated Moodle PHPUnit database: **2 tests, 7 assertions passed**. Tests cover adding the missing completion field while retaining the existing activity, preserving an existing enabled completion setting, and skipping an already completed migration. PHP syntax checks passed for the migration and regression tests. Hostinger deployment and its database upgrade must still be performed by the site administrator.

The 0.1.2 repair additionally handles a missing `maxattempts` field, as reported by Hostinger. After refreshing the isolated test environment, the migration tests passed: **3 tests, 14 assertions**. Both missing fields are restored from versions 2026101600 and 2026101700, existing activities retain their names, existing limits and completion settings survive, and the final version is a no-op. PHP syntax checks passed. This verifies the two confirmed missing columns; the full remote schema has not been inspected.

## Teacher deletion — version 0.1.3

- Deletion service checks: **7 tests, 19 assertions passed**. Covers teacher permissions, denied student/report-only access, separate groups and wrong-activity IDs, audio/record deletion, retained credentials and other attempts, processing locks, automatic completion with one/two results, manual completion preservation, attempt counts, and a content-free deletion audit event.
- Confirmation/shared-template rendering: **2 tests, 8 assertions passed**, including escaped student content and the POST confirmation form with sesskey.
- PHP syntax checks passed for changed/new PHP files. No JavaScript or CSS changed.
- Browser confirmation, invalid-sesskey requests, and GET mutation rejection are listed in `manual-checks.md` and have not been exercised in an authenticated browser here. Upload the complete module and run the Moodle upgrade to register the new capability on deployment.

## Recording labels — version 0.1.4 (19 October 2026)

- Four recorder contract tests passed, including preparation/zero-preparation labels, preparation countdown text, active recording indication, and the repeat action.
- PHP syntax checks passed. AMD build and ESLint passed using Node 22; production assets were regenerated.
- The local Moodle source is now 5.3+. Its CSS validator reports two pre-existing `clamp()` declarations in styles.css (card padding and clock font size); the new indicator styles have no reported errors. Browser visual verification has not been performed for this change.
- Disclosure text now uses the requested “sent to AI for feedback” wording. Groq remains the actual provider; the Privacy API declaration and deployment documentation still identify it.
