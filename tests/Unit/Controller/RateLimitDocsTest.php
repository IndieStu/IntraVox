<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The documented rate limits are the real ones.
 *
 * api-reference.md now names a number per endpoint, and integrators pace their
 * imports on it. A table of numbers maintained by hand next to attributes changed
 * in code is exactly how openapi.json ended up describing 45% of this API, so it
 * gets a guard rather than good intentions.
 *
 * This checks the direction that actually hurts: a limit in code that the docs do
 * not mention, or mention with the wrong number. An integrator pacing at the
 * documented rate then walks into a 429 it was told would not come.
 */
class RateLimitDocsTest extends TestCase {
    private const DOCS = __DIR__ . '/../../../docs/architecture/api-reference.md';
    private const CONTROLLER = __DIR__ . '/../../../lib/Controller/ApiController.php';

    public function testEveryRateLimitInCodeIsDocumentedWithItsRealNumber(): void {
        $source = file_get_contents(self::CONTROLLER);
        $docs = file_get_contents(self::DOCS);

        preg_match_all(
            '/#\[UserRateLimit\(limit:\s*(\d+),\s*period:\s*(\d+)\)\].*?public function (\w+)/s',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'No rate limits found — the pattern went stale');

        $undocumented = [];
        foreach ($matches as [, $limit, $period, $method]) {
            $this->assertSame('60', $period, "{$method} uses a non-minute window; the docs table says 'per minute'");

            // The handler name and its number must both appear in the docs.
            if (!str_contains($docs, $method) || !str_contains($docs, "| {$limit} / minute |")) {
                $undocumented[] = "{$method} ({$limit}/{$period}s)";
            }
        }

        $this->assertSame(
            [],
            $undocumented,
            "Rate limits changed in code but not in api-reference.md:\n  " . implode("\n  ", $undocumented)
        );
    }

    /** The migration escape hatch must stay documented, including turning it off again. */
    public function testTheMigrationBypassIsDocumentedWithItsCleanup(): void {
        $docs = file_get_contents(self::DOCS);

        $this->assertStringContainsString('apply_allowlist_to_ratelimit', $docs);
        $this->assertStringContainsString('config:app:delete', $docs, 'Telling people how to open it without how to close it is worse than silence');
    }
}
