<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\Sanitize\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Footer content is sanitised on the SERVER, not trusted from the editor.
 *
 * saveFooter() stored whatever it was given, under a comment saying "Content is
 * already sanitized by DOMPurify in the frontend". That is true of the editor
 * and irrelevant to security: POST /api/footer is an ordinary endpoint and a
 * crafted request never runs the editor's JavaScript. Client-side sanitisation
 * is a formatting convenience; it is not a control.
 *
 * The footer renders through v-html (Footer.vue:15) on every page, so stored
 * script would execute for every logged-in viewer of that language — persistent
 * XSS, injectable by anyone with write permission on the language folder.
 *
 * What made this worth fixing rather than arguing about: text widgets have
 * ALWAYS been sanitised server-side, by this same service (PageShapeSanitizer
 * calls htmlSanitizer->sanitize() on widget content). Same renderer, same trust
 * boundary, same author population — the footer was simply the one v-html
 * surface that had been missed. The fix is one call to a service that was
 * already there.
 *
 * Not reachable anonymously: the footer is not served through the public-share
 * endpoints, so this needed an account. That bounds the severity; it does not
 * change the verdict.
 */
class FooterSanitizationTest extends TestCase {
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer();
    }

    /**
     * The service the footer now calls actually removes the dangerous parts.
     *
     * Note what is asserted and what is not. strip_tags() removes the <script>
     * ELEMENT but leaves its text content behind, so the string "alert(1)"
     * survives as literal characters in a paragraph. That is not a finding: text
     * is text, and the browser has nothing left to execute. Asserting the payload
     * string is absent would be testing for cosmetics and would fail on a footer
     * that legitimately mentions alert(1) in prose.
     *
     * What must be absent is anything the browser would RUN.
     */
    public function testScriptTagsAreRemoved(): void {
        $clean = $this->sanitizer->sanitize('<p>hi</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $clean, 'The element is what executes');
        $this->assertStringContainsString('hi', $clean, 'Legitimate markup must survive');
    }

    /**
     * The vectors that matter, each reduced to something inert.
     *
     * A whitelist sanitiser is only as good as the list, so these are asserted
     * against the real service rather than assumed from reading it.
     */
    public function testTheUsualVectorsAreNeutralised(): void {
        $cases = [
            '<img src=x onerror=alert(1)>' => ['<img', 'onerror'],
            '<svg/onload=alert(1)>' => ['<svg', 'onload'],
            '<iframe src=//evil.test></iframe>' => ['<iframe'],
            '<object data="evil"></object>' => ['<object'],
            '<a href="javascript:alert(1)">x</a>' => ['javascript:'],
            '<div style="background:url(javascript:alert(1))">x</div>' => ['javascript', 'url('],
        ];

        foreach ($cases as $payload => $forbidden) {
            $clean = $this->sanitizer->sanitize($payload);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $clean,
                    "Sanitising {$payload} left '{$needle}' behind"
                );
            }
        }
    }

    public function testEventHandlersAreRemoved(): void {
        $clean = $this->sanitizer->sanitize('<a href="#" onclick="steal()">x</a>');

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('steal()', $clean);
    }

    public function testJavascriptUrisAreDefused(): void {
        $clean = $this->sanitizer->sanitize('<a href="javascript:alert(1)">x</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
    }

    /** saveFooter() calls it, and the misleading comment is gone. */
    public function testSaveFooterSanitizesBeforeStoring(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/Service/FooterService.php');

        $start = strpos($source, 'public function saveFooter(');
        $this->assertNotFalse($start);
        $next = strpos($source, "\n    public function ", $start + 10);
        $body = substr($source, $start, $next === false ? 4000 : $next - $start);

        $this->assertStringContainsString(
            '$this->htmlSanitizer->sanitize($content)',
            $body,
            'The footer must be sanitised server-side before it is written'
        );

        $this->assertStringNotContainsString(
            'already sanitized by DOMPurify in the frontend',
            $source,
            'That claim described a convenience as if it were a control'
        );
    }

    /**
     * The sanitiser runs BEFORE the content is written, not after.
     *
     * Sanitising on read would leave the raw payload on disk, where an export,
     * a backup or a future reader that skips the filter would still surface it.
     */
    public function testSanitizationHappensBeforeTheWrite(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/Service/FooterService.php');

        $sanitizeAt = strpos($source, '$this->htmlSanitizer->sanitize($content)');
        $dataAt = strpos($source, "'content' => \$content,", $sanitizeAt ?: 0);

        $this->assertNotFalse($sanitizeAt);
        $this->assertNotFalse($dataAt);
        $this->assertLessThan(
            $dataAt,
            $sanitizeAt,
            'Sanitise before building the payload, so what lands on disk is already clean'
        );
    }
}
