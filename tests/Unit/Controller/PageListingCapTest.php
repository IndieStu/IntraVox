<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use OCA\IntraVox\Controller\ApiController;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/pages is bounded, and says so when it truncates.
 *
 * The endpoint returned every page in the language with no limit, no offset and
 * no ceiling. On a small intranet that is fine; it is the worst case that has no
 * answer, and "the biggest customer decides the response size" is not a contract
 * anyone can build against.
 *
 * Two deliberate choices worth recording, because both are easy to get wrong
 * later:
 *
 * 1. The body stays a BARE ARRAY. App.vue treats this response as a complete
 *    index of page ids and reads it in nine places, so an {items, total} envelope
 *    would be a breaking change — 3.0.0 work, not a MINOR. The truncation signal
 *    therefore travels in headers, where a client that does not know about it is
 *    unaffected.
 * 2. There is no cursor yet, on purpose. listPages() has no ORDER BY, and cursor
 *    paging over an unordered result silently skips and repeats rows between
 *    requests. A stable sort is a prerequisite, not a detail.
 *
 * The ceiling is 2000 while the paid tiers stop at 1000 pages per language, so it
 * bounds a worst case rather than shaping ordinary use. Empirically calibrating
 * it needs an instance with thousands of pages, which does not exist yet.
 */
class PageListingCapTest extends TestCase {
    public function testTheCeilingSitsAboveEveryLicensedTier(): void {
        $cap = $this->cap();

        $this->assertGreaterThan(
            1000,
            $cap,
            'A cap at or below the top paid tier would truncate paying customers by design'
        );
    }

    public function testTheCeilingIsActuallyBounded(): void {
        $cap = $this->cap();

        $this->assertIsInt($cap);
        $this->assertLessThanOrEqual(
            10000,
            $cap,
            'A ceiling this high stops bounding anything; it exists to make the worst case finite'
        );
    }

    /**
     * The header contract, asserted against the source.
     *
     * Reflection on a private constant plus a read of the method body is a blunt
     * instrument, but ApiController needs sixteen collaborators to instantiate and
     * this is about the contract, not the wiring: a truncated response must carry
     * both headers, and an untruncated one must still say which ceiling applied.
     */
    public function testBothHeadersAreAlwaysSent(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/Controller/ApiController.php');
        $listing = $this->methodBody($source, 'listPages');

        $this->assertStringContainsString('X-IntraVox-Cap', $listing);
        $this->assertStringContainsString('X-IntraVox-Truncated', $listing);
        $this->assertStringContainsString('array_slice(', $listing, 'The cap must actually be applied');
    }

    /** A truncation is worth a log line: silence would make it invisible in support. */
    public function testTruncationIsLogged(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/Controller/ApiController.php');
        $listing = $this->methodBody($source, 'listPages');

        $this->assertStringContainsString('page listing truncated', $listing);
    }

    private function cap(): int {
        $class = new \ReflectionClass(ApiController::class);

        return $class->getConstant('MAX_PAGES_IN_LISTING');
    }

    private function methodBody(string $source, string $method): string {
        $start = strpos($source, 'public function ' . $method . '(');
        $this->assertNotFalse($start, "Method {$method} not found");
        $next = strpos($source, 'public function ', $start + 10);

        return substr($source, $start, $next === false ? 4000 : $next - $start);
    }
}
