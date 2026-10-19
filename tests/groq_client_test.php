<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Provider response tests.
 *
 * @package mod_speakingfeedback
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_speakingfeedback;
use mod_speakingfeedback\local\groq_client;
use mod_speakingfeedback\local\provider_exception;
defined('MOODLE_INTERNAL') || die();

/** Provider response validation. */
#[\PHPUnit\Framework\Attributes\CoversClass(groq_client::class)]
final class groq_client_test extends \basic_testcase {
    /** Successful decoding. */
    public function test_success(): void {
        $this->assertSame(['text' => 'Hello'], groq_client::decode(200, '{"text":"Hello"}'));
    }
    /** Sanitise credentials, quota, network, and malformed-response failures. */
    public function test_safe_errors(): void {
        foreach ([[401, 'credentialerror'], [403, 'credentialerror'], [429, 'ratelimited'],
                [0, 'providererror'], [500, 'providererror'], [200, 'invalidresponse']] as [$status, $expected]) {
            try {
                groq_client::decode($status, 'SECRET PROVIDER BODY', ['Retry-After' => '60']);
                $this->fail('Expected a safe provider exception');
            } catch (provider_exception $e) {
                $this->assertSame($expected, $e->errorcode);
                $this->assertStringNotContainsString('SECRET', $e->getMessage());
                if ($status === 429) {
                    $this->assertSame(60, $e->delay);
                }
            }
        }
    }
}
