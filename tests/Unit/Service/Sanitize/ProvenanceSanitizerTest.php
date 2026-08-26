<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Sanitize;

use OCA\IntraVox\Service\Sanitize\ColorSanitizer;
use OCA\IntraVox\Service\Sanitize\HtmlSanitizer;
use OCA\IntraVox\Service\Sanitize\PageShapeSanitizer;
use OCA\IntraVox\Service\Sanitize\UrlSanitizer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Provenance survives an edit.
 *
 * The page sanitizer is a strict whitelist: a field it does not name is dropped
 * on every save. That is the right default, and it is also why provenance needs a
 * test rather than a hope — a migration could write sourceUniqueId perfectly and
 * the first editor to touch the page would erase it, silently, taking delta runs
 * and any chance of verifying the import with it. translationGroup learned this
 * the hard way and the comment above it says so.
 *
 * Field names are not free choices here. plan-multisite-uitvoering.md P3 already
 * fixes `sourceUniqueId` for the import path that mints fresh page ids; a second
 * name for the same idea is cheap to avoid now and expensive to reconcile later.
 */
class ProvenanceSanitizerTest extends TestCase {
    private function sanitize(array $raw): array {
        $sanitizer = new PageShapeSanitizer(
            $this->createMock(IConfig::class),
            $this->createMock(LoggerInterface::class),
            new HtmlSanitizer(),
            new UrlSanitizer(),
            new ColorSanitizer(),
        );

        return $sanitizer->validateAndSanitizePage($raw);
    }

    private function page(array $extra = []): array {
        return array_merge(['id' => 'page-1', 'title' => 'Beleid'], $extra);
    }

    public function testProvenanceSurvivesASave(): void {
        $out = $this->sanitize($this->page([
            'sourceUniqueId' => 'sharepoint:sites/hr/SitePages/beleid.aspx',
            'sourceUrl' => 'https://contoso.sharepoint.com/sites/hr/SitePages/beleid.aspx',
        ]));

        $this->assertSame('sharepoint:sites/hr/SitePages/beleid.aspx', $out['sourceUniqueId']);
        $this->assertSame('https://contoso.sharepoint.com/sites/hr/SitePages/beleid.aspx', $out['sourceUrl']);
    }

    public function testAPageWithoutProvenanceStaysWithoutIt(): void {
        $out = $this->sanitize($this->page());

        $this->assertArrayNotHasKey('sourceUniqueId', $out, 'Absence is valid: most pages were never migrated');
        $this->assertArrayNotHasKey('sourceUrl', $out);
    }

    /**
     * sourceUrl ends up in page metadata that a later view may render as a link,
     * so a migration must not be able to park a script URI there.
     */
    public function testOnlyHttpUrlsAreKept(): void {
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'file:///etc/passwd', 'not a url'] as $hostile) {
            $out = $this->sanitize($this->page(['sourceUrl' => $hostile]));
            $this->assertArrayNotHasKey('sourceUrl', $out, "{$hostile} must be dropped");
        }
    }

    public function testAnIdentifierIsCharacterCheckedNotEscaped(): void {
        $out = $this->sanitize($this->page(['sourceUniqueId' => '<script>alert(1)</script>']));

        $this->assertArrayNotHasKey(
            'sourceUniqueId',
            $out,
            'An identifier that does not look like one is dropped, never escaped and kept'
        );
    }

    public function testOverlongValuesAreDropped(): void {
        $out = $this->sanitize($this->page([
            'sourceUniqueId' => str_repeat('a', 256),
            'sourceUrl' => 'https://example.com/' . str_repeat('b', 2100),
        ]));

        $this->assertArrayNotHasKey('sourceUniqueId', $out);
        $this->assertArrayNotHasKey('sourceUrl', $out);
    }

    /** The whole point: an edit that knows nothing about provenance must not erase it. */
    public function testAnEditThatOmitsNothingKeepsProvenanceAcrossRoundTrips(): void {
        $first = $this->sanitize($this->page([
            'sourceUniqueId' => 'confluence:12345',
            'sourceUrl' => 'https://wiki.example.org/pages/12345',
        ]));

        $second = $this->sanitize(array_merge($first, ['title' => 'Beleid (herzien)']));

        $this->assertSame('confluence:12345', $second['sourceUniqueId']);
        $this->assertSame('https://wiki.example.org/pages/12345', $second['sourceUrl']);
    }
}
