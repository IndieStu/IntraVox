<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use OCA\IntraVox\Service\OrphanedDataService;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/orphaned/{id}/migrate accepts every language the site actually runs.
 *
 * It did not. The controller validated against a hardcoded ['nl','en','de','fr']
 * while the service behind it validated against getKnownLanguages() — the union
 * of discovered translations, admin-enabled languages and the legacy default.
 * Two lists, and since the controller rejects first, the narrow one silently won.
 *
 * The consequence was specific and easy to miss: a site with a fifth content
 * language could create pages in it, serve them and export them, but could not
 * recover them out of an orphaned GroupFolder — a 400 on a language the site
 * plainly supports, for a reason with nothing to do with the content. Data
 * stranded on disk by a validation mismatch.
 *
 * The fix is one list, owned by the service. What this test guards is that it
 * STAYS one list: a second hardcoded array reappearing in the controller is the
 * regression, and it would be invisible on any instance that happens to run only
 * the four legacy languages.
 */
class OrphanedMigrateLanguageRangeTest extends TestCase {
    private function controllerSource(): string {
        return file_get_contents(__DIR__ . '/../../../lib/Controller/OrphanedDataController.php');
    }

    public function testTheControllerAsksTheServiceRatherThanCarryingItsOwnList(): void {
        $this->assertStringContainsString(
            '$this->orphanedDataService->getKnownLanguages()',
            $this->controllerSource(),
            'Language validation must come from the service, so there is only one set to keep correct'
        );
    }

    /**
     * No second hardcoded list, under any name.
     *
     * Matching the exact old literal would let the same bug return as
     * ['nl','en','fr','de'] or with a different variable name. This looks for any
     * inline array of language codes instead.
     */
    public function testNoHardcodedLanguageArrayRemains(): void {
        $source = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $this->controllerSource());

        $this->assertSame(
            0,
            preg_match("/=\s*\[\s*'(?:nl|en|de|fr)'\s*,/", $source),
            'A second inline language list is exactly the mismatch this fix removed'
        );
    }

    /** The service's list is genuinely dynamic, or asking it buys nothing. */
    public function testTheServiceListIsResolvedFromTheSite(): void {
        $service = file_get_contents(__DIR__ . '/../../../lib/Service/OrphanedDataService.php');

        $this->assertMatchesRegularExpression(
            '/public function getKnownLanguages\(\)/',
            $service,
            'The controller cannot call it unless it is public'
        );
        $this->assertStringContainsString('getDiscoveredLanguages()', $service);
        $this->assertStringContainsString('getEnabledLanguages()', $service);
    }

    /**
     * A language beyond the legacy four is accepted.
     *
     * The three assertions above are structural — they prove the wiring, not the
     * behaviour. This one drives the real method: a site that has enabled 'es'
     * must see it in the set the controller validates against.
     */
    public function testAnEnabledLanguageOutsideTheLegacyFourIsIncluded(): void {
        $languageService = $this->createMock(\OCA\IntraVox\Service\LanguageService::class);
        $languageService->method('getDiscoveredLanguages')->willReturn([]);
        $languageService->method('getEnabledLanguages')->willReturn(['nl', 'es']);

        $service = (new \ReflectionClass(OrphanedDataService::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(OrphanedDataService::class, 'languageService');
        $prop->setValue($service, $languageService);

        $known = $service->getKnownLanguages();

        $this->assertContains('es', $known, 'An enabled language must be recoverable from an orphaned folder');
        $this->assertContains('nl', $known, 'The legacy defaults stay in the union');
        $this->assertContains('fr', $known, 'Even when no longer enabled — old content folders must stay recognisable');
    }

    /** The spec no longer advertises the narrow enum. */
    public function testTheSpecNoLongerRestrictsTheLanguage(): void {
        $spec = json_decode(file_get_contents(__DIR__ . '/../../../openapi.json'), true);
        $op = $spec['paths']['/api/orphaned/{id}/migrate']['post'] ?? null;
        $this->assertNotNull($op);

        $language = $op['requestBody']['content']['application/json']['schema']['properties']['language'] ?? [];

        $this->assertArrayNotHasKey(
            'enum',
            $language,
            'A fixed enum would re-document the limitation that was just removed'
        );
        $this->assertStringNotContainsString(
            'NARROWER',
            $op['description'] ?? '',
            'The warning must go with the bug'
        );
    }
}
