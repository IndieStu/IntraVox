<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * CommentController does not hand exception messages to the client.
 *
 * Ten of its catch blocks did: they logged $e->getMessage() and then returned
 * the same string in the response body. Every endpoint on this controller is
 * #[NoAdminRequired], and the exceptions come from the filesystem and the
 * Comments API — so the messages carry group folder paths, storage ids and
 * internal comment ids. A message meant for a log ended up in a browser.
 *
 * They now route through ApiErrorTrait::safeErrorResponse(), which logs the full
 * detail against a generated errorId and returns only a fixed sentence plus that
 * id. The cause stays available to whoever can read the log; it stops being
 * available to whoever can read the response.
 *
 * TWO SITES ARE DELIBERATELY LEFT ALONE, and this test protects them from an
 * over-eager cleanup as much as it protects the rest from a leak. updateComment()
 * and deleteComment() match on $e->getMessage() to map 'Comment not found' and
 * 'Not authorized to edit this comment' onto 404 and 403. Those strings are
 * thrown by this app, are part of the contract clients route on, and disclose
 * nothing. Genericising them would break the mapping and lose the distinction
 * between "gone" and "not yours".
 */
class CommentErrorLeakTest extends TestCase {
    private string $source;

    protected function setUp(): void {
        parent::setUp();
        $this->source = file_get_contents(
            __DIR__ . '/../../../lib/Controller/CommentController.php'
        );
    }

    /** The source with comments stripped — the docblocks necessarily name the pattern. */
    private function code(): string {
        return preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $this->source);
    }

    /**
     * No response body is built from an exception message.
     *
     * Matches the actual leak shape — getMessage() inside an array literal that
     * becomes a response — rather than getMessage() anywhere, so the two
     * legitimate match() sites do not trip it.
     */
    public function testNoResponseBodyCarriesAnExceptionMessage(): void {
        $code = $this->code();

        $this->assertSame(
            0,
            preg_match_all("/\\['error'\s*=>\s*\\\$e->getMessage\(\)\\]\s*,\s*Http::STATUS/", $code),
            'A catch block is returning the exception message to the client again'
        );
        // The two status-mapping sites DO return $e->getMessage(), and must: the
        // mapped strings are the contract. What matters is that they can only be
        // reached for a RECOGNISED message. Their match() therefore has to yield
        // null by default and bail out before the return -- an unrecognised
        // exception falling through to that line is the leak wearing a status code.
        preg_match_all(
            "/match \(\\\$e->getMessage\(\)\) \{(.+?)\};(.+?)return new DataResponse/s",
            $code,
            $sites,
            PREG_SET_ORDER
        );

        $this->assertCount(2, $sites, 'Expected exactly the two contract sites');

        foreach ($sites as $site) {
            $this->assertStringContainsString(
                'default => null',
                $site[1],
                'An unrecognised message must not be assigned a status and returned'
            );
            $this->assertStringContainsString(
                'safeErrorResponse(',
                $site[2],
                'The null case must return through the safe helper before reaching the verbatim return'
            );
        }
    }

    /** The safe path is actually used, not merely available. */
    public function testTheControllerRoutesFailuresThroughTheSafeHelper(): void {
        $code = $this->code();

        $this->assertStringContainsString('use ApiErrorTrait;', $code);
        $this->assertGreaterThanOrEqual(
            10,
            substr_count($code, 'safeErrorResponse('),
            'Every handler that can fail should report through the trait'
        );
    }

    /**
     * The two contract sites survive.
     *
     * These are the ones a later "remove all getMessage() calls" sweep would
     * quietly break — the endpoint would still work, but every failure would
     * collapse to 500 and clients could no longer tell a missing comment from
     * someone else's comment.
     */
    public function testTheStatusMappingContractIsIntact(): void {
        $code = $this->code();

        $this->assertSame(
            2,
            preg_match_all('/match \(\$e->getMessage\(\)\)/', $code),
            'updateComment() and deleteComment() map known messages onto 404 and 403'
        );

        foreach (['Comment not found', 'Not authorized to edit this comment', 'Not authorized to delete this comment'] as $known) {
            $this->assertStringContainsString(
                $known,
                $code,
                'These strings are thrown by this app and are the contract clients route on'
            );
        }
    }

    /** Every safeErrorResponse() call passes a real sentence, not a variable. */
    public function testThePublicMessagesAreLiterals(): void {
        preg_match_all(
            "/safeErrorResponse\(\s*\\\$e,\s*(.+?),/s",
            $this->code(),
            $matches
        );

        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $arg) {
            $this->assertStringStartsWith(
                "'",
                trim($arg),
                'A variable here could carry the exception message straight back out'
            );
        }
    }
}
