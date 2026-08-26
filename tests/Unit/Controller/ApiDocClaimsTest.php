<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The prose documentation does not contradict the measured behaviour.
 *
 * Three claims in the shipped docs were false, and each was the kind an
 * integrator acts on before discovering otherwise:
 *
 *  - "the OCS variant follows the Nextcloud OCS conventions (status codes,
 *    headers, error format)" — there is no {ocs:{meta,data}} envelope at all,
 *    because no controller extends OCSController. A client written against the
 *    envelope fails to parse every response.
 *  - "Both provide largely the same functionality" — the OCS mount carries the
 *    eight /api/v1/* routes and nothing else. /api/health returns 404 there.
 *  - "Current version: 0.9.17" while the app shipped 2.4.1, next to a "Local
 *    server" URL that returns 404 because nothing serves the file.
 *
 * openapi.json now has a guard for each of these (OcsMountScopeTest, the
 * coverage ratchet). The prose had none, which is why it drifted furthest: it is
 * the only artefact here a human writes and nothing reads back.
 *
 * These assertions are deliberately narrow. They pin the specific sentences that
 * were wrong, not the shape of the documents — a doc test that fails on every
 * rewording gets deleted the first time someone reorganises a page.
 */
class ApiDocClaimsTest extends TestCase {
    private const DOCS = __DIR__ . '/../../../docs/architecture/';

    private function read(string $name): string {
        $path = self::DOCS . $name;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * @return array<string,string> filename => human label
     */
    public static function apiReferenceProvider(): array {
        return [
            'english' => ['api-reference.md'],
            'dutch' => ['api-reference.nl.md'],
        ];
    }

    /**
     * Neither language may claim an OCS envelope exists.
     *
     * @dataProvider apiReferenceProvider
     */
    public function testNoDocClaimsAnOcsEnvelope(string $file): void {
        $doc = $this->read($file);

        // The absence of the envelope has to be stated, not merely not-denied:
        // "OCS API" next to silence reads as "standard OCS behaviour".
        $this->assertMatchesRegularExpression(
            '/(no OCS envelope|géén OCS-envelop|geen OCS-envelop)/i',
            $doc,
            "{$file} must state that responses are bare JSON — the /ocs/ path implies otherwise"
        );
    }

    /**
     * And both must say the OCS mount is partial.
     *
     * @dataProvider apiReferenceProvider
     */
    public function testNoDocClaimsFullParityBetweenMounts(string $file): void {
        $doc = $this->read($file);

        $this->assertMatchesRegularExpression(
            '/(api\/v1\/\* routes are mounted|alleen de acht|Only the eight)/i',
            $doc,
            "{$file} must say the OCS mount carries only /api/v1/*"
        );

        $this->assertStringNotContainsString(
            'grotendeels dezelfde functionaliteit',
            $doc,
            'The two mounts do not carry the same routes'
        );
    }

    /**
     * The tooling docs must not print a hardcoded spec version.
     *
     * It said 0.9.17 while the app was at 2.4.1. sync-version.js keeps the spec
     * in step now, so the doc should describe that rather than restate a number
     * that goes stale the moment anyone forgets.
     */
    public function testToolingDocsDoNotHardcodeASpecVersion(): void {
        foreach (['openapi-tooling.md', 'openapi-tooling.nl.md'] as $file) {
            $doc = $this->read($file);

            $this->assertSame(
                0,
                preg_match('/\*\*(Current version|Huidige versie):\*\*\s*\d+\.\d+\.\d+/', $doc),
                "{$file} pins a version by hand; it drifted from 0.9.17 to 2.4.1 unnoticed"
            );
        }
    }

    /** And they must not promise a URL that returns 404. */
    public function testToolingDocsDoNotPromiseAServedSpec(): void {
        foreach (['openapi-tooling.md', 'openapi-tooling.nl.md'] as $file) {
            $doc = $this->read($file);

            $this->assertMatchesRegularExpression(
                '/(not served over HTTP|niet via HTTP geserveerd)/i',
                $doc,
                "{$file} must say the spec is not reachable over HTTP; the obvious guess 404s"
            );
        }

        // The app genuinely has no route for it — if that ever changes, the docs
        // above become wrong in the other direction.
        $routes = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
        $this->assertStringNotContainsString(
            'openapi',
            $routes,
            'A route now serves the spec; the documentation saying it is unreachable must be updated'
        );
    }
}
