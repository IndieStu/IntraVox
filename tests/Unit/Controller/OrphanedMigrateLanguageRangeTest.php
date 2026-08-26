<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * POST /api/orphaned/{id}/migrate accepts fewer languages than the app supports.
 *
 * The controller validates against a hardcoded ['nl','en','de','fr']. The service
 * it calls validates against getKnownLanguages(), which merges the DISCOVERED and
 * ENABLED languages with a legacy default — so it accepts whatever the site
 * actually runs. The controller's narrower list wins, because it rejects first.
 *
 * The consequence is specific and easy to miss: a site with a fifth content
 * language (say 'es') can create pages in it, serve them, export them — but
 * cannot recover them out of an orphaned GroupFolder, because this one endpoint
 * refuses the language with a 400. Content stays stranded on disk for a reason
 * that has nothing to do with the content.
 *
 * This test does not assert that the mismatch is CORRECT. It pins that it exists
 * and is deliberate, so the spec's warning stays true and so widening the
 * controller list is a conscious act with a test to update. Widening it is the
 * real fix; it needs the same language resolution the service already does, and
 * that is a behaviour change rather than documentation.
 *
 * @see OrphanedDataService::getKnownLanguages()
 */
class OrphanedMigrateLanguageRangeTest extends TestCase {
    private function controllerSource(): string {
        return file_get_contents(__DIR__ . '/../../../lib/Controller/OrphanedDataController.php');
    }

    private function serviceSource(): string {
        return file_get_contents(__DIR__ . '/../../../lib/Service/OrphanedDataService.php');
    }

    public function testTheControllerStillHardcodesItsLanguageList(): void {
        $this->assertMatchesRegularExpression(
            "/\\\$supportedLanguages\s*=\s*\['nl',\s*'en',\s*'de',\s*'fr'\]/",
            $this->controllerSource(),
            'If this list became dynamic the spec must stop warning that migrate is narrower than the app'
        );
    }

    public function testTheServiceBehindItResolvesLanguagesDynamically(): void {
        $service = $this->serviceSource();

        $this->assertStringContainsString(
            'getKnownLanguages()',
            $service,
            'The asymmetry only matters while the service is the more permissive of the two'
        );
        $this->assertStringContainsString(
            'getDiscoveredLanguages()',
            $service,
            'getKnownLanguages() must actually consult the site rather than a second hardcoded list'
        );
    }

    /**
     * The spec tells integrators about it.
     *
     * A limitation this arbitrary is worse undocumented than unfixed: without the
     * warning, a 400 on a language the site plainly supports reads as a bug in the
     * caller.
     */
    public function testTheSpecDocumentsTheNarrowerRange(): void {
        $spec = json_decode(
            file_get_contents(__DIR__ . '/../../../openapi.json'),
            true
        );

        $op = $spec['paths']['/api/orphaned/{id}/migrate']['post'] ?? null;
        $this->assertNotNull($op, 'The migrate endpoint must stay documented');

        $enum = $op['requestBody']['content']['application/json']['schema']
            ['properties']['language']['enum'] ?? null;

        $this->assertSame(
            ['nl', 'en', 'de', 'fr'],
            $enum,
            'The documented enum must match the hardcoded list the controller enforces'
        );

        $this->assertStringContainsString(
            'NARROWER',
            $op['description'] ?? '',
            'The description must flag the mismatch, not just list the four codes'
        );
    }
}
